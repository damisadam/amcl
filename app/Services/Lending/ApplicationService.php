<?php

namespace App\Services\Lending;

use App\Enums\ApplicationStatus;
use App\Enums\ApprovalType;
use App\Enums\ConsentType;
use App\Enums\FieldVisitStatus;
use App\Events\ApplicationStatusChanged;
use App\Events\ApplicationSubmitted;
use App\Exceptions\DomainException;
use App\Models\Consent;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Guarantor;
use App\Models\LoanApplication;
use App\Models\Objection;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Audit\Auditor;
use App\Services\Notifications\Notifier;
use App\Services\Products\EligibilityService;
use App\Services\Products\FinancingCalculator;
use App\Services\Support\Numbering;
use App\Support\Money;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;

/**
 * Financing application lifecycle (LMS §6, DFS §2). All status changes go through transition(),
 * which enforces the ApplicationStatus state machine and writes status history + audit.
 */
class ApplicationService
{
    public function __construct(
        private EligibilityService $eligibility,
        private FinancingCalculator $calculator,
        private ApprovalEngine $approvals,
        private Notifier $notifier,
    ) {}

    public function createDraft(Customer $customer, Product $product, array $data, User $actor, string $channel = 'web'): LoanApplication
    {
        $version = $product->currentVersion ?? throw new DomainException('This product has no active terms.');
        if (! $product->is_active) {
            throw new DomainException('This product is not currently offered.');
        }
        $app = new LoanApplication([
            'application_no' => Numbering::next('application'),
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'product_version_id' => $version->id,
            'branch_id' => $data['branch_id'] ?? $customer->branch_id,
            'channel' => $channel,
            'created_by' => $actor->id,
            'loan_officer_id' => $actor->isStaff() ? $actor->id : $customer->assigned_officer_id,
            'requested_amount' => '0.00',
            'tenure' => $version->min_tenure,
            'frequency' => $version->frequency,
        ]);
        $this->fillTerms($app, $data);
        $app->forceFill(['status' => ApplicationStatus::Draft])->save();
        $this->history($app, null, ApplicationStatus::Draft, $actor, 'Application started');
        Auditor::record('application.created', $app, [], ['application_no' => $app->application_no, 'product' => $product->code, 'amount' => $app->requested_amount]);

        return $app;
    }

    public function updateDraft(LoanApplication $app, array $data, User $actor): LoanApplication
    {
        if (! $app->isEditableByCustomer()) {
            throw new DomainException('Only draft or returned applications can be edited.');
        }
        $this->fillTerms($app, $data);
        Auditor::changes('application.updated', $app);
        $app->save();

        return $app;
    }

    private function fillTerms(LoanApplication $app, array $data): void
    {
        $version = $app->productVersion ?? $app->product->currentVersion;
        if (! empty($data['vendor_item_id'])) {
            $item = VendorItem::findOrFail($data['vendor_item_id']);
            $data['vendor_id'] = $item->vendor_id;
            $data['asset_price'] ??= $item->price;
            $data['asset_description'] ??= $item->name;
        }
        if (! empty($data['vendor_id']) && ! Vendor::whereKey($data['vendor_id'])->exists()) {
            throw new DomainException('Selected vendor is not available.');
        }
        // QA M4: the dealer and item must supply this product's asset category (e.g. no laptop shop for Motorbike Sahulat).
        if (! empty($data['vendor_id']) && $version->requires_vendor && ! Vendor::forProduct($app->product)->whereKey($data['vendor_id'])->exists()) {
            throw new DomainException('This vendor does not supply assets for '.$app->product->name.'. Choose a dealer for this product.');
        }
        if (isset($item) && ! VendorItem::forProduct($app->product)->whereKey($item->id)->exists()) {
            throw new DomainException('The selected item cannot be financed under '.$app->product->name.'.');
        }
        if (isset($data['asset_price']) && $data['asset_price'] !== null && $data['asset_price'] !== '') {
            $price = Money::of($data['asset_price']);
            $down = Money::of($data['down_payment'] ?? Money::pct($price, (string) $version->down_payment_pct));
            if (Money::lt($down, Money::pct($price, (string) $version->down_payment_pct))) {
                throw new DomainException('Minimum down payment for this product is '.$version->down_payment_pct.'% ('.Money::format(Money::pct($price, (string) $version->down_payment_pct)).').');
            }
            $data['asset_price'] = $price;
            $data['down_payment'] = $down;
            $data['requested_amount'] = Money::sub($price, $down);
        }
        $app->fill(array_intersect_key($data, array_flip([
            'requested_amount', 'tenure', 'purpose', 'asset_description', 'asset_price', 'down_payment', 'vendor_id', 'vendor_item_id', 'branch_id', 'fund_id',
        ])));
        if (Money::isPositive($app->requested_amount ?? 0) && $app->tenure) {
            $app->indicative_instalment = $this->calculator->quote($version, $app->requested_amount, (int) $app->tenure)['instalment'];
        }
    }

    public function addGuarantor(LoanApplication $app, array $data, User $actor): Guarantor
    {
        if (! $app->isEditableByCustomer() && ! $actor->isStaff()) {
            throw new DomainException('Guarantors can only be added while the application is editable.');
        }
        if (($data['cnic'] ?? null) === $app->customer->cnic) {
            throw new DomainException('The applicant cannot be their own guarantor.');
        }
        $fields = array_intersect_key($data, array_flip(['name', 'cnic', 'mobile', 'relation', 'address', 'occupation', 'monthly_income']));
        $g = $app->guarantors()->create($fields + ['customer_id' => $app->customer_id, 'consent_at' => ! empty($data['consent']) ? now() : null]);
        Auditor::record('application.guarantor_added', $app, [], ['guarantor' => $g->name]);
        if ($g->consent_at) {
            // Guarantors go through the same sanctions / PEP screening as applicants (C2).
            rescue(fn () => app(UnderwritingService::class)->screenGuarantor($g, $actor), report: false);
            $g->refresh();
        }

        return $g;
    }

    /** Pre-submission checklist. Returns unmet items (empty = can submit). */
    public function submissionChecklist(LoanApplication $app): array
    {
        $v = $app->productVersion;
        $missing = $this->eligibility->check($app->customer, $v, (string) $app->requested_amount, (int) $app->tenure, $app);

        if ($v->requires_vendor && ! $app->vendor_id) {
            $missing[] = 'Select the vendor supplying the asset.';
        }
        if ($app->guarantors()->count() < $v->guarantors_required) {
            $missing[] = "This product requires {$v->guarantors_required} guarantor(s).";
        }
        if ($app->guarantors()->whereNull('consent_at')->exists()) {
            $missing[] = 'Each guarantor must consent to verification and credit bureau checks.';
        }
        foreach ([ConsentType::NadraVerification, ConsentType::CreditBureau] as $type) {
            if (! Consent::where('customer_id', $app->customer_id)->where('consent_type', $type->value)->where('granted', true)->whereNull('withdrawn_at')->exists()) {
                $missing[] = 'Consent required: '.$type->label().'.';
            }
        }
        foreach ($this->missingDocuments($app) as $doc) {
            $missing[] = 'Upload required document: '.$doc.'.';
        }

        return $missing;
    }

    /** Required document types (per product version, amount tier) not yet uploaded for applicant. */
    public function missingDocuments(LoanApplication $app): array
    {
        $required = $app->productVersion->requiredDocuments()->with('documentType')
            ->where('applies_to', 'applicant')->where('is_mandatory', true)
            ->where(fn ($q) => $q->whereNull('min_amount')->orWhere('min_amount', '<=', $app->requested_amount))->get();
        $have = Document::where(function ($q) use ($app) {
            $q->where(fn ($q) => $q->where('documentable_type', $app->getMorphClass())->where('documentable_id', $app->id))
                ->orWhere(fn ($q) => $q->where('documentable_type', $app->customer->getMorphClass())->where('documentable_id', $app->customer_id));
        })->whereNotIn('status', ['rejected', 'superseded'])->pluck('document_type_id')->all();

        return $required->reject(fn ($r) => in_array($r->document_type_id, $have))->map(fn ($r) => $r->documentType->name)->values()->all();
    }

    public function submit(LoanApplication $app, User $actor): LoanApplication
    {
        if ($missing = $this->submissionChecklist($app)) {
            throw new DomainException('The application cannot be submitted yet: '.implode(' ', $missing));
        }
        $from = $app->status;
        // QA H1: every request shown to the customer must be answered before a returned case goes back.
        if ($from === ApplicationStatus::Returned && $app->objections()->where('visible_to_customer', true)->where('status', 'open')->exists()) {
            throw new DomainException('Please respond to each open request from the institution before resubmitting.');
        }
        $this->transition($app, ApplicationStatus::Submitted, $actor, $from === ApplicationStatus::Returned ? 'Resubmitted after correction' : 'Submitted for verification', [
            'submitted_at' => now(),
            'first_submitted_at' => $app->first_submitted_at ?? now(), // the original submission date is never overwritten
        ]);
        // Internal (staff-only) objections are considered answered by the resubmission; customer ones were answered above.
        $app->objections()->where('status', 'open')->where('visible_to_customer', false)->update(['status' => 'responded']);
        Realtime::emit(new ApplicationSubmitted($app->tenant_id, ['application_no' => $app->application_no, 'product' => $app->product->name]));

        return $app;
    }

    public function startReview(LoanApplication $app, User $actor): LoanApplication
    {
        return $this->transition($app, ApplicationStatus::UnderReview, $actor, 'Verification and credit assessment started');
    }

    /** Move a reviewed case into the configured approval hierarchy. */
    public function sendForApproval(LoanApplication $app, User $actor): LoanApplication
    {
        $assessment = $app->latestAssessment;
        if (! $assessment) {
            throw new DomainException('Complete the credit assessment before sending the case for approval.');
        }
        if (! $app->bureauChecks()->where('subject_type', 'applicant')->exists()) {
            throw new DomainException('A Tasdeeq / bureau check for the applicant is required before approval.');
        }
        if ($app->fieldVisits()->whereIn('status', [FieldVisitStatus::Scheduled->value, FieldVisitStatus::InProgress->value])->exists()) {
            throw new DomainException('Pending field visits must be completed first.');
        }
        if ($app->objections()->where('status', 'open')->exists()) {
            throw new DomainException('Resolve open objections before sending for approval.');
        }
        $uw = app(UnderwritingService::class);
        foreach ($app->guarantors()->get() as $g) {
            $ready = $uw->guarantorReadiness($g);
            $uw->refreshGuarantorStatus($g);
            if ($ready['status'] !== 'verified') {
                throw new DomainException("Guarantor {$g->name} is not verified: ".implode('; ', $ready['reasons']).'.');
            }
        }
        if ($uw->isAssessmentStale($app)) {
            throw new DomainException('The credit assessment is out of date (bureau, field visit, guarantor or income data changed after it was run). Re-run the assessment before sending for approval.');
        }

        return DB::transaction(function () use ($app, $actor) {
            $this->transition($app, ApplicationStatus::PendingApproval, $actor, 'Sent to approval hierarchy');
            $this->approvals->submit($app, ApprovalType::Loan, (string) $app->requested_amount, $actor, $app->product_id, $app->branch_id);

            return $app;
        });
    }

    /** Return to the originator (objection) – customer sees it if visible_to_customer. */
    public function returnForCorrection(LoanApplication $app, User $actor, string $reason, bool $visibleToCustomer = true, ?int $assignTo = null): LoanApplication
    {
        if (blank($reason)) {
            throw new DomainException('A reason is required when returning an application.');
        }

        return DB::transaction(function () use ($app, $actor, $reason, $visibleToCustomer, $assignTo) {
            // The timeline always shows the return to the customer; the internal reason stays internal when not visible.
            $this->transition($app, ApplicationStatus::Returned, $actor, $visibleToCustomer ? $reason : 'Returned for additional information or correction.', [], true);
            $this->raiseObjection($app, $actor, $reason, $visibleToCustomer, $assignTo ?? ($app->creator?->isStaff() ? $app->created_by : $app->loan_officer_id));

            return $app;
        });
    }

    public function raiseObjection(LoanApplication $app, User $actor, string $body, bool $visibleToCustomer = false, ?int $assignTo = null, string $category = 'documents'): Objection
    {
        $o = $app->objections()->create([
            'raised_by' => $actor->id, 'assigned_to' => $assignTo, 'stage' => $app->status->label(),
            'category' => $category, 'body' => $body, 'visible_to_customer' => $visibleToCustomer,
        ]);
        Auditor::record('application.objection_raised', $app, [], ['objection_id' => $o->id], $body);
        if ($assignTo && ($user = User::find($assignTo))) {
            $this->notifier->toUser($user, 'objection', ['title' => 'Objection on '.$app->application_no, 'body' => $body, 'url' => '/ops/applications/'.$app->id]);
        }
        if ($visibleToCustomer) {
            $this->notifier->toCustomer($app->customer, 'application_returned', ['title' => 'Action required on '.$app->application_no, 'body' => $body, 'url' => '/app/applications/'.$app->id]);
        }

        return $o;
    }

    public function resolveObjection(Objection $o, User $actor, string $response): Objection
    {
        $o->update(['status' => 'resolved', 'response' => $response, 'resolved_by' => $actor->id, 'resolved_at' => now()]);
        Auditor::record('application.objection_resolved', $o->application, [], ['objection_id' => $o->id], $response);

        return $o;
    }

    public function reject(LoanApplication $app, User $actor, string $code, string $notes): LoanApplication
    {
        if (blank($notes)) {
            throw new DomainException('Rejection notes are required.');
        }

        return $this->transition($app, ApplicationStatus::Rejected, $actor, self::REJECTION_CODES[$code] ?? $code, [
            'rejected_at' => now(), 'rejection_code' => $code, 'rejection_notes' => $notes,
        ]);
    }

    public function withdraw(LoanApplication $app, User $actor, string $reason): LoanApplication
    {
        return $this->transition($app, ApplicationStatus::Withdrawn, $actor, $reason ?: 'Withdrawn by applicant', ['withdrawn_at' => now()]);
    }

    /** Reapplication (LMS §6.10) or lighter re-evaluation (§6.11) referencing the original case. */
    public function reapply(LoanApplication $original, User $actor, bool $reevaluation = false): LoanApplication
    {
        if (! in_array($original->status, [ApplicationStatus::Rejected, ApplicationStatus::Withdrawn, ApplicationStatus::Cancelled], true)) {
            throw new DomainException('Only rejected, withdrawn or cancelled applications can be re-applied or re-evaluated.');
        }
        $app = $this->createDraft($original->customer, $original->product, [
            'requested_amount' => $original->requested_amount, 'tenure' => $original->tenure, 'purpose' => $original->purpose,
            'asset_description' => $original->asset_description, 'asset_price' => $original->asset_price, 'down_payment' => $original->down_payment,
            'vendor_id' => $original->vendor_id, 'vendor_item_id' => $original->vendor_item_id,
        ], $actor, $original->channel);
        $app->forceFill(['previous_application_id' => $original->id, 'is_reevaluation' => $reevaluation])->save();
        foreach ($original->guarantors as $g) {
            $app->guarantors()->create($g->only(['customer_id', 'name', 'cnic', 'mobile', 'relation', 'address', 'occupation', 'monthly_income', 'consent_at']));
        }
        Auditor::record($reevaluation ? 'application.reevaluation' : 'application.reapplication', $app, [], ['original' => $original->application_no]);

        return $app;
    }

    public function transition(LoanApplication $app, ApplicationStatus $to, ?User $actor, ?string $comment = null, array $attributes = [], bool $visibleToCustomer = true): LoanApplication
    {
        return DB::transaction(function () use ($app, $to, $actor, $comment, $attributes, $visibleToCustomer) {
            $locked = LoanApplication::whereKey($app->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $from->assertCanTransitionTo($to, 'Application '.$locked->application_no);
            $locked->forceFill($attributes + ['status' => $to])->save();
            $app->setRawAttributes($locked->getAttributes(), true);
            $this->history($app, $from, $to, $actor, $comment, $visibleToCustomer);
            Auditor::record('application.status_changed', $app, ['status' => $from->value], ['status' => $to->value], $comment);

            DB::afterCommit(function () use ($app, $to, $visibleToCustomer) {
                Realtime::emit(new ApplicationStatusChanged($app->tenant_id, ['application_no' => $app->application_no, 'status' => $to->value]));
                if ($visibleToCustomer && in_array($to, self::CUSTOMER_NOTIFY, true)) {
                    $this->notifier->toCustomer($app->customer, 'application_status', [
                        'title' => 'Application '.$app->application_no.': '.$to->label(),
                        'body' => 'Your financing application status is now "'.$to->label().'".',
                        'application_no' => $app->application_no, 'status' => $to->label(), 'url' => '/app/applications/'.$app->id,
                    ]);
                }
            });

            return $app;
        });
    }

    private function history(LoanApplication $app, ?ApplicationStatus $from, ApplicationStatus $to, ?User $actor, ?string $comment, bool $visible = true): void
    {
        $app->histories()->create([
            'from_status' => $from, 'to_status' => $to, 'actor_id' => $actor?->id, 'comment' => $comment, 'visible_to_customer' => $visible,
        ]);
    }

    public const REJECTION_CODES = [
        'failed_bureau' => 'Failed Tasdeeq / credit bureau check',
        'insufficient_income' => 'Insufficient income / DBR above limit',
        'negative_visit' => 'Negative field verification report',
        'blacklist' => 'Blacklist match',
        'incomplete_documents' => 'Incomplete documents',
        'kyc_failed' => 'Identity verification failed',
        'aml' => 'Compliance screening',
        'policy' => 'Outside credit policy',
        'other' => 'Other',
    ];

    private const CUSTOMER_NOTIFY = [
        ApplicationStatus::Submitted, ApplicationStatus::UnderReview, ApplicationStatus::Approved, ApplicationStatus::Rejected,
        ApplicationStatus::CoolingOff, ApplicationStatus::Disbursed, ApplicationStatus::Cancelled, ApplicationStatus::Returned,
    ];
}
