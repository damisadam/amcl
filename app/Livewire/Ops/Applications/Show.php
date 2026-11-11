<?php

namespace App\Livewire\Ops\Applications;

use App\Enums\ApplicationStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Models\ApprovalRequest;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Guarantor;
use App\Models\LoanApplication;
use App\Models\Objection;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Policies\RecordAccess;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Documents\DocumentStore;
use App\Services\Lending\AgreementService;
use App\Services\Lending\ApplicationService;
use App\Services\Lending\UnderwritingService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** The financing case file: verification, underwriting, approval, agreement and timeline. */
#[Layout('layouts.ops')]
class Show extends Component
{
    use HandlesActions, WithFileUploads;

    public LoanApplication $application;

    #[Url] public string $tab = 'overview';

    // modals & forms
    public bool $showReturn = false;
    public bool $showReject = false;
    public bool $showWithdraw = false;
    public bool $showGuarantor = false;
    public bool $showVisit = false;
    public bool $showResolve = false;
    public bool $showWetInk = false;

    private const MODALS = ['showReturn', 'showReject', 'showWithdraw', 'showGuarantor', 'showVisit', 'showResolve', 'showWetInk'];
    public string $reason = '';
    public string $rejectCode = 'policy';
    public bool $visibleToCustomer = true;
    public string $comment = '';
    public array $guarantor = ['name' => '', 'cnic' => '', 'mobile' => '', 'relation' => '', 'occupation' => '', 'monthly_income' => '', 'address' => '', 'consent' => false];
    public string $bureauProvider = 'tasdeeq';
    public string $bureauScenario = 'auto';
    public string $bureauGuarantor = '';
    public array $visit = ['type' => 'residence', 'officer' => '', 'date' => '', 'address' => ''];
    public array $assess = ['monthly_income' => '', 'other_income' => '', 'household_expenses' => '', 'existing_obligations' => '', 'notes' => '',
        'cash_inflows' => '', 'cash_outflows' => ''];
    public string $objection = '';
    public string $objectionCategory = 'documents';
    public bool $objectionVisible = false;
    public ?int $resolveId = null;
    public string $resolution = '';
    public $docFile; // not "upload": that name shadows Livewire's $wire.upload() in the browser
    public string $uploadType = '';
    public ?int $wetInkId = null;
    public string $delegateTo = '';
    public ?string $signingLink = null;
    public $invoiceFile;
    public string $invoiceRef = '';

    public const TABS = ['overview', 'documents', 'guarantors', 'bureau', 'visits', 'assessment', 'objections', 'approvals', 'agreement', 'timeline'];

    /** Human names for validation messages (M2). */
    protected function validationAttributes(): array
    {
        return [
            'reason' => 'reason', 'rejectCode' => 'rejection reason', 'comment' => 'comment', 'resolution' => 'resolution', 'objection' => 'objection',
            'guarantor.name' => 'guarantor name', 'guarantor.cnic' => 'guarantor CNIC', 'guarantor.mobile' => 'guarantor mobile number',
            'guarantor.relation' => 'relation', 'guarantor.monthly_income' => 'guarantor monthly income', 'guarantor.consent' => 'guarantor consent',
            'visit.type' => 'visit type', 'visit.officer' => 'visit officer', 'visit.date' => 'visit date', 'docFile' => 'file', 'uploadType' => 'document type',
            'assess.monthly_income' => 'verified monthly income', 'assess.other_income' => 'other income', 'assess.household_expenses' => 'household expenses',
            'assess.existing_obligations' => 'existing monthly obligations', 'assess.cash_inflows' => 'business monthly inflows', 'assess.cash_outflows' => 'business monthly outflows',
            'bureauGuarantor' => 'guarantor',
        ];
    }

    protected function messages(): array
    {
        return [
            'guarantor.cnic.regex' => 'Enter the guarantor CNIC as 12345-1234567-1.',
            'guarantor.mobile.regex' => 'Enter a valid Pakistani mobile number, e.g. 03001234567.',
            'visit.date.after_or_equal' => 'The visit date cannot be in the past.',
        ];
    }

    /** M3: an error disappears as soon as the user edits that field. */
    public function updated(string $property): void
    {
        $this->resetErrorBag($property);
        if ($property === 'tab' && ! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }
    }

    public function mount(LoanApplication $application): void
    {
        $this->need('applications.view');
        abort_unless(RecordAccess::canViewApplication(auth()->user(), $application), 403);
        $this->application = $application;
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview'; // M11: unknown ?tab= falls back to the overview
        }
        // H5: the analyst enters VERIFIED income; declared figures are only shown as hints.
        $this->visit['date'] = now()->addDay()->toDateString();
    }

    #[On('realtime')]
    public function refreshCase(): void
    {
        $this->application->refresh();
    }

    public function openModal(string $name): void
    {
        $this->resetErrorBag();
        $this->reset('reason', 'comment');
        $this->closeModals();
        $prop = 'show'.ucfirst($name);
        if (in_array($prop, self::MODALS, true)) {
            $this->{$prop} = true;
        }
    }

    public function openResolve(int $id): void
    {
        $this->openModal('resolve');
        $this->resolveId = $id;
        $this->resolution = '';
    }

    public function openWetInk(int $id): void
    {
        $this->openModal('wetInk');
        $this->wetInkId = $id;
    }

    private function app(): LoanApplication
    {
        return $this->application->fresh();
    }

    private function closeModals(): void
    {
        foreach (self::MODALS as $m) {
            $this->{$m} = false;
        }
    }

    private function done(): void
    {
        $this->closeModals();
        $this->application->refresh();
    }

    // ------------------------------------------------------------ status actions

    public function submit(): void
    {
        $this->need('applications.submit');
        if ($this->attempt(fn () => app(ApplicationService::class)->submit($this->app(), auth()->user()), __('Application submitted.'))) {
            $this->done();
        }
    }

    public function startReview(): void
    {
        $this->need('applications.verify');
        if ($this->attempt(fn () => app(ApplicationService::class)->startReview($this->app(), auth()->user()), __('Review started.'))) {
            $this->done();
        }
    }

    public function sendForApproval(): void
    {
        $this->need('applications.verify', 'applications.assess');
        if ($this->attempt(fn () => app(ApplicationService::class)->sendForApproval($this->app(), auth()->user()), __('Sent to the approval hierarchy.'))) {
            $this->done();
            $this->tab = 'approvals';
        }
    }

    public function returnCase(): void
    {
        $this->need('applications.return');
        $this->validate(['reason' => 'required|string|min:5|max:1000']);
        if ($this->attempt(fn () => app(ApplicationService::class)->returnForCorrection($this->app(), auth()->user(), $this->reason, $this->visibleToCustomer), __('Returned for correction.'))) {
            $this->done();
        }
    }

    public function reject(): void
    {
        $this->need('applications.reject');
        $this->validate(['rejectCode' => 'required|in:'.implode(',', array_keys(ApplicationService::REJECTION_CODES)), 'reason' => 'required|string|min:5|max:1000']);
        if (! in_array($this->application->status, [ApplicationStatus::Submitted, ApplicationStatus::UnderReview, ApplicationStatus::Returned], true)) {
            $this->addError('action', __('Cases in the approval hierarchy are rejected through the approval task.'));

            return;
        }
        if ($this->attempt(fn () => app(ApplicationService::class)->reject($this->app(), auth()->user(), $this->rejectCode, $this->reason), __('Application rejected.'))) {
            $this->done();
        }
    }

    public function withdraw(): void
    {
        $this->need('applications.create');
        $this->validate(['reason' => 'required|string|min:5']);
        if ($this->attempt(fn () => app(ApplicationService::class)->withdraw($this->app(), auth()->user(), 'Withdrawn on customer request: '.$this->reason), __('Application withdrawn.'))) {
            $this->done();
        }
    }

    public function reapply(bool $reevaluation)
    {
        $this->need('applications.reevaluate');
        $new = $this->attempt(fn () => app(ApplicationService::class)->reapply($this->app(), auth()->user(), $reevaluation),
            $reevaluation ? __('Re-evaluation case created.') : __('Reapplication created.'));
        if ($new) {
            return $this->redirect(route('ops.applications.show', $new), navigate: true);
        }
    }

    // ------------------------------------------------------------ documents

    public function uploadDocument(): void
    {
        $this->need('documents.upload');
        $this->validate(['docFile' => 'required|file|max:5120|mimes:jpg,jpeg,png,webp,pdf', 'uploadType' => 'required|integer']);
        $type = DocumentType::findOrFail($this->uploadType);
        if ($this->attempt(fn () => app(DocumentStore::class)->store($this->docFile, $this->application, $type, auth()->user()), __('Document uploaded.'))) {
            $this->reset('docFile', 'uploadType');
        }
    }

    public function verifyDocument(int $id, bool $approve): void
    {
        $this->need('documents.verify');
        $doc = $this->docsQuery()->findOrFail($id);
        if (! $approve && blank($this->reason)) {
            $this->addError('reason', __('Enter a rejection reason in the box above the list.'));

            return;
        }
        $this->attempt(fn () => app(DocumentStore::class)->verify($doc, auth()->user(), $approve, $approve ? null : $this->reason), $approve ? __('Document verified.') : __('Document rejected.'));
    }

    private function docsQuery()
    {
        $a = $this->application;

        return Document::with(['type', 'uploader'])->where(function ($q) use ($a) {
            $q->where(fn ($w) => $w->where('documentable_type', $a->getMorphClass())->where('documentable_id', $a->id))
                ->orWhere(fn ($w) => $w->where('documentable_type', $a->customer->getMorphClass())->where('documentable_id', $a->customer_id));
        });
    }

    // ------------------------------------------------------------ guarantors & bureau

    public function addGuarantor(): void
    {
        $this->need('applications.create');
        $this->validate(['guarantor.name' => 'required|string|max:120', 'guarantor.cnic' => ['required', 'regex:/^\d{5}-\d{7}-\d$/'],
            'guarantor.mobile' => ['required', 'regex:/^(\+92|92|0)3\d{9}$/'], 'guarantor.relation' => 'nullable|string|max:30',
            'guarantor.monthly_income' => 'nullable|numeric|min:0', 'guarantor.consent' => 'accepted']);
        $g = $this->guarantor;
        $g['monthly_income'] = $g['monthly_income'] ?: 0;
        if ($this->attempt(fn () => app(ApplicationService::class)->addGuarantor($this->app(), $g, auth()->user()), __('Guarantor added.'))) {
            $this->reset('guarantor');
            $this->closeModals();
        }
    }

    public function requestBureau(string $subject): void
    {
        $this->need('bureau.request');
        $g = $subject === 'guarantor' ? Guarantor::where('loan_application_id', $this->application->id)->find($this->bureauGuarantor) : null;
        if ($subject === 'guarantor' && ! $g) {
            $this->addError('bureauGuarantor', __('Select a guarantor.'));

            return;
        }
        $this->attempt(fn () => app(UnderwritingService::class)->requestBureau($this->app(), auth()->user(), $subject, $g, $this->bureauProvider,
            \App\Services\Payments\Simulation::enabled() ? $this->bureauScenario : 'auto'), __('Credit report received (simulated).'));
    }

    // ------------------------------------------------------------ field visits

    public function scheduleVisit(): void
    {
        $this->need('field_visits.assign');
        $this->validate(['visit.type' => 'required|in:residence,business,collateral', 'visit.officer' => 'required|integer', 'visit.date' => 'required|date|after_or_equal:today']);
        $officer = User::where('type', 'staff')->findOrFail($this->visit['officer']);
        if ($this->attempt(fn () => app(UnderwritingService::class)->scheduleVisit($this->app(), auth()->user(), $this->visit['type'], $officer, $this->visit['date'], $this->visit['address'] ?: null), __('Visit scheduled.'))) {
            $this->closeModals();
        }
    }

    // ------------------------------------------------------------ assessment

    public function runAssessment(): void
    {
        $this->need('applications.assess');
        $this->validate(['assess.monthly_income' => 'required|numeric|min:1', 'assess.other_income' => 'nullable|numeric|min:0', 'assess.household_expenses' => 'nullable|numeric|min:0',
            'assess.existing_obligations' => 'nullable|numeric|min:0', 'assess.notes' => 'nullable|string|max:2000', 'assess.cash_inflows' => 'nullable|numeric|min:0', 'assess.cash_outflows' => 'nullable|numeric|min:0']);
        $in = $this->assess;
        if ($in['cash_inflows'] !== '' || $in['cash_outflows'] !== '') {
            $in['business_cashflow'] = ['monthly_inflows' => $in['cash_inflows'] ?: '0', 'monthly_outflows' => $in['cash_outflows'] ?: '0',
                'net' => \App\Support\Money::sub($in['cash_inflows'] ?: '0', $in['cash_outflows'] ?: '0')];
        }
        foreach (['other_income', 'household_expenses', 'existing_obligations'] as $k) {
            $in[$k] = $in[$k] === '' ? '0' : $in[$k];
        }
        if ($this->attempt(fn () => app(UnderwritingService::class)->assess($this->app(), auth()->user(), $in), __('Credit assessment recorded.'))) {
            $this->application->refresh();
        }
    }

    // ------------------------------------------------------------ objections

    public function raiseObjection(): void
    {
        $this->need('applications.verify');
        $this->validate(['objection' => 'required|string|min:5|max:2000', 'objectionCategory' => 'required|in:documents,income,identity,visit,policy,other']);
        $assignTo = $this->application->creator?->isStaff() ? $this->application->created_by : $this->application->loan_officer_id;
        if ($this->attempt(fn () => app(ApplicationService::class)->raiseObjection($this->app(), auth()->user(), $this->objection, $this->objectionVisible, $assignTo, $this->objectionCategory), __('Objection raised.'))) {
            $this->reset('objection', 'objectionVisible');
        }
    }

    public function resolveObjection(): void
    {
        $this->need('applications.verify', 'applications.create');
        $this->validate(['resolution' => 'required|string|min:3|max:2000']);
        $o = Objection::where('loan_application_id', $this->application->id)->findOrFail($this->resolveId);
        if ($this->attempt(fn () => app(ApplicationService::class)->resolveObjection($o, auth()->user(), $this->resolution), __('Objection resolved.'))) {
            $this->reset('resolution', 'resolveId');
            $this->closeModals();
        }
    }

    // ------------------------------------------------------------ approvals (inline)

    public function approval(string $action): void
    {
        $this->need('approvals.act');
        $req = $this->application->approvalRequest;
        abort_unless($req, 404);
        $engine = app(ApprovalEngine::class);
        if (in_array($action, ['reject', 'return'], true)) {
            $this->validate(['comment' => 'required|string|min:5']);
        }
        $ok = $this->attempt(fn () => match ($action) {
            'approve' => $engine->approve($req, auth()->user(), $this->comment ?: null),
            'reject' => $engine->reject($req, auth()->user(), $this->comment),
            'return' => $engine->returnForCorrection($req, auth()->user(), $this->comment),
            'delegate' => $engine->delegate($req, auth()->user(), User::findOrFail($this->delegateTo), $this->comment ?: 'Delegated'),
        }, __('Decision recorded.'));
        if ($ok) {
            $this->done();
        }
    }

    // ------------------------------------------------------------ agreement

    public function confirmWetInk(): void
    {
        $this->need('agreements.wet_ink', 'agreements.manage');
        $this->validate(['comment' => 'required|string|min:3']);
        $req = SignatureRequest::where('loan_application_id', $this->application->id)->findOrFail($this->wetInkId);
        if ($this->attempt(fn () => app(AgreementService::class)->confirmWetInk($req, auth()->user(), $this->comment), __('Wet-ink document receipt confirmed.'))) {
            $this->done();
        }
    }

    public function screenGuarantor(int $id): void
    {
        $this->need('bureau.request', 'applications.verify');
        $g = Guarantor::where('loan_application_id', $this->application->id)->findOrFail($id);
        $this->attempt(fn () => app(UnderwritingService::class)->screenGuarantor($g, auth()->user()),
            __('Guarantor sanctions / PEP screening completed (simulated).'));
    }

    public function sendSigningLink(int $id): void
    {
        $this->need('agreements.wet_ink', 'agreements.manage');
        $req = SignatureRequest::where('loan_application_id', $this->application->id)->findOrFail($id);
        $url = $this->attempt(fn () => app(AgreementService::class)->sendGuarantorLink($req, auth()->user()), __('Signing link sent to the guarantor by SMS (simulated).'));
        if (is_string($url)) {
            $this->signingLink = \App\Services\Payments\Simulation::enabled() ? $url : null;
        }
    }

    /** QA F7 – maker: record the vendor invoice issued in the institution's name (Murabaha asset purchase). */
    public function recordVendorInvoice(): void
    {
        $this->need('agreements.wet_ink', 'agreements.manage', 'applications.create');
        $this->validate(['invoiceFile' => 'required|file|max:5120|mimes:jpg,jpeg,png,webp,pdf', 'invoiceRef' => 'required|string|max:60'],
            [], ['invoiceFile' => 'vendor invoice file', 'invoiceRef' => 'invoice number']);
        if ($this->attempt(fn () => app(\App\Services\Lending\MurabahaService::class)->recordVendorInvoice($this->application, $this->invoiceFile, auth()->user(), $this->invoiceRef),
            __('Vendor invoice recorded. A second user must confirm asset ownership.'))) {
            $this->reset('invoiceFile', 'invoiceRef');
        }
    }

    /** QA F7 – checker: a different user confirms the institution owns the asset. */
    public function confirmAssetOwnership(): void
    {
        $this->need('agreements.manage', 'disbursements.initiate');
        $this->attempt(fn () => app(\App\Services\Lending\MurabahaService::class)->confirmOwnership($this->application, auth()->user()),
            __('Asset ownership confirmed. The customer can now accept the Murabaha offer.'));
    }

    public function render()
    {
        $u = auth()->user();
        $a = $this->application->load(['customer.branch', 'product', 'productVersion.charges', 'branch', 'vendor', 'vendorItem', 'fund', 'officer', 'creator', 'previousApplication',
            'guarantors', 'bureauChecks', 'fieldVisits.officer', 'objections.raiser', 'objections.assignee', 'histories.actor', 'kfs', 'signatureRequests.documentType', 'disbursements']);
        $req = $a->approvalRequest()->with(['actions.actor', 'currentStage.role'])->first();
        $engine = app(ApprovalEngine::class);
        $s = $a->status;

        return view('livewire.ops.applications.show', [
            'a' => $a,
            'u' => $u,
            'req' => $req,
            'canDecide' => $req && $req->status->value === 'pending' && $engine->canAct($req, $u),
            'delegates' => $req && $req->current_stage_id ? User::where('status', 'active')->whereKeyNot($u->id)->whereHas('roles', fn ($q) => $q->where('roles.id', $req->currentStage->role_id))->pluck('name', 'id')->all() : [],
            'assessment' => $a->assessments()->with('assessor')->first(),
            'docs' => $this->docsQuery()->latest('id')->get(),
            'missingDocs' => in_array($s, [ApplicationStatus::Draft, ApplicationStatus::Returned, ApplicationStatus::Submitted, ApplicationStatus::UnderReview], true) ? app(ApplicationService::class)->missingDocuments($a) : [],
            'checklist' => in_array($s, [ApplicationStatus::Draft, ApplicationStatus::Returned], true) ? app(ApplicationService::class)->submissionChecklist($a) : [],
            'docTypes' => DocumentType::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            // M14: only real visit officers (a role other than Tenant Admin grants field_visits.perform), same branch first.
            'officers' => User::with('branch')->where('type', 'staff')->where('status', 'active')
                ->whereHas('roles', fn ($r) => $r->where('key', '!=', 'tenant_admin')->whereHas('permissions', fn ($p) => $p->where('key', 'field_visits.perform')))
                ->orderByRaw('branch_id = ? desc', [$a->branch_id ?? 0])->orderBy('name')->get()
                ->mapWithKeys(fn ($o) => [$o->id => $o->name.($o->branch ? ' · '.$o->branch->name : '')])->all(),
            'readiness' => app(\App\Services\Lending\ReadinessService::class)->checks($a, $u),
            'murabaha' => app(\App\Services\Lending\MurabahaService::class)->requiresAssetOwnership($a)
                ? ['invoice' => app(\App\Services\Lending\MurabahaService::class)->invoice($a), 'confirmed' => app(\App\Services\Lending\MurabahaService::class)->assetOwnershipConfirmed($a)] : null,
            'assessmentStale' => app(UnderwritingService::class)->isAssessmentStale($a),
            'guarantorReadiness' => $a->guarantors->mapWithKeys(fn ($g) => [$g->id => app(UnderwritingService::class)->guarantorReadiness($g)])->all(),
            'declared' => ['monthly_income' => $a->customer->monthly_income, 'other_income' => $a->customer->other_income, 'existing_obligations' => $a->customer->existing_obligations],
            'sensitive' => $u->hasPermission('customers.view_sensitive'),
            'canAml' => $u->hasPermission('aml.view'),
        ]);
    }
}
