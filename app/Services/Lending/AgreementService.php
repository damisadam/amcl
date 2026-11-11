<?php

namespace App\Services\Lending;

use App\Enums\ApplicationStatus;
use App\Exceptions\DomainException;
use App\Models\DocumentType;
use App\Models\KeyFactStatement;
use App\Models\LoanApplication;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Documents\DocumentStore;
use App\Services\Integrations\ESignGateway;
use App\Services\Products\FinancingCalculator;
use Illuminate\Support\Facades\DB;

/**
 * KFS → acknowledgement → e-signature → cooling-off (DFS §2.4, §3, §6.1; LMS §6.5, §6.6).
 *
 * Document signing method comes from Document Type Management: "digital" types are signed through
 * the (simulated) NIFT eSign flow; "conditional"/"wet_ink" types (DPN, Affidavit) are routed to the
 * wet-ink path and must be confirmed received by staff before cooling-off starts.
 */
class AgreementService
{
    /** Document type codes that make up the agreement pack for every financing. */
    public const PACK = ['FIN_AGREEMENT', 'UNDERTAKING', 'DPN', 'AFFIDAVIT'];

    public function __construct(
        private FinancingCalculator $calculator,
        private ESignGateway $esign,
        private ApplicationService $applications,
        private DocumentStore $documents,
    ) {}

    public function generateKfs(LoanApplication $app, ?User $actor = null): KeyFactStatement
    {
        if (! in_array($app->status, [ApplicationStatus::Approved], true)) {
            throw new DomainException('A Key Fact Statement is generated once the application is approved.');
        }
        $v = $app->productVersion;
        $q = $this->calculator->quote($v, (string) $app->financedAmount(), (int) $app->tenure);
        $version = (int) $app->kfs()->max('version') + 1;
        $kfs = $app->kfs()->create([
            'version' => $version,
            'generated_at' => now(),
            'generated_by' => $actor?->id,
            'terms' => [
                'institution' => $app->tenant->name,
                'product' => $app->product->name, 'product_ur' => $app->product->name_ur,
                'structure' => $app->product->shariah_structure->label(),
                'asset' => $app->asset_description, 'asset_price' => $app->asset_price, 'down_payment' => $app->down_payment,
                'financed_amount' => $q['principal'], 'profit_rate' => $q['profit_rate'], 'profit_method' => $q['profit_method'],
                'apr' => $q['apr'], 'tenure' => $q['tenure'], 'frequency' => $q['frequency'], 'instalment' => $q['instalment'],
                'profit_total' => $q['profit_total'], 'charges' => $q['charge_lines'], 'charges_upfront' => $q['charges_upfront'],
                'charges_spread' => $q['charges_spread'], 'net_disbursed' => $q['net_disbursed'], 'total_payable' => $q['total_payable'],
                'payout_label' => app(MurabahaService::class)->payoutLabel($app),
                'first_due_date' => $q['first_due_date'], 'maturity_date' => $q['maturity_date'],
                'late_payment' => $v->latePaymentDisclosure(),
                'cooling_off_hours' => $v->cooling_off_hours,
                'grievance' => $app->tenant->support_phone.' / '.$app->tenant->support_email,
            ],
        ]);
        Auditor::record('agreement.kfs_generated', $app, [], ['kfs_version' => $version, 'apr' => $q['apr']]);

        return $kfs;
    }

    public function acknowledgeKfs(LoanApplication $app, User $user, string $locale, ?string $ip = null, ?string $ua = null): LoanApplication
    {
        $this->assertCustomerOrStaff($app, $user);
        $kfs = $app->kfs ?? throw new DomainException('No Key Fact Statement has been issued yet.');
        if (! app(MurabahaService::class)->assetOwnershipConfirmed($app)) {
            // Murabaha sequence (QA F7): the institution must own the asset before offering the sale.
            throw new DomainException('The institution has not yet confirmed ownership of the asset (vendor invoice in its name). The Murabaha offer can be accepted once ownership is confirmed.');
        }

        return DB::transaction(function () use ($app, $user, $locale, $ip, $ua, $kfs) {
            $kfs->update(['acknowledged_at' => now(), 'acknowledged_locale' => $locale, 'acknowledged_ip' => $ip, 'acknowledged_user_agent' => $ua]);
            $this->applications->transition($app, ApplicationStatus::KfsAcknowledged, $user, "Key Fact Statement v{$kfs->version} acknowledged ({$locale})", ['kfs_acknowledged_at' => now()]);
            $this->createSignatureRequests($app);
            Auditor::record('agreement.kfs_acknowledged', $app, [], ['locale' => $locale, 'ip' => $ip]);

            return $app;
        });
    }

    public function createSignatureRequests(LoanApplication $app): void
    {
        if ($app->signatureRequests()->exists()) {
            return;
        }
        $pack = app(MurabahaService::class)->pack($app);
        $types = DocumentType::whereIn('code', $pack)->get()->keyBy('code');
        $signers = [['applicant', $app->customer_id, $app->customer->full_name]];
        foreach ($app->guarantors as $g) {
            $signers[] = ['guarantor', $g->id, $g->name];
        }
        foreach ($pack as $code) {
            $type = $types->get($code);
            if (! $type || $type->signing_method === 'none') {
                continue;
            }
            foreach ($signers as [$signerType, $signerId, $name]) {
                if ($signerType === 'guarantor' && ! in_array($code, ['FIN_AGREEMENT', 'UNDERTAKING'], true)) {
                    continue; // guarantors sign the agreement and undertaking only
                }
                $method = $type->signing_method === 'digital' ? 'digital' : 'wet_ink';
                $req = $app->signatureRequests()->create([
                    'document_type_id' => $type->id, 'signer_type' => $signerType, 'signer_id' => $signerId, 'signer_name' => $name,
                    'signing_method' => $method, 'status' => $method === 'digital' ? 'pending' : 'wet_ink_pending',
                    'provider' => $method === 'digital' ? 'nift_esign' : null,
                ]);
                if ($method === 'digital') {
                    $res = $this->esign->send($req);
                    $req->update(['status' => 'sent', 'provider_ref' => $res->providerRef, 'integration_log_id' => $res->logId]);
                }
            }
        }
    }

    /** Applicant/guarantor completes a digital signature after OTP confirmation (OTP verified by caller). */
    public function sign(SignatureRequest $req, User $user, ?string $ip = null, ?string $ua = null, ?string $viewedHash = null): SignatureRequest
    {
        $app = $req->application;
        if ($req->signing_method !== 'digital' || ! in_array($req->status, ['sent', 'pending'], true)) {
            throw new DomainException('This document is not awaiting a digital signature.');
        }
        if ($app->status !== ApplicationStatus::KfsAcknowledged) {
            throw new DomainException('Documents can be signed only after the Key Fact Statement is acknowledged.');
        }
        if ($req->signer_type === 'applicant') {
            $this->assertCustomerOrStaff($app, $user);
        } elseif (! $user->isStaff()) {
            throw new DomainException('Guarantor signatures are completed through the guarantor signing link.');
        }

        $html = view('documents.agreement', ['app' => $app, 'type' => $req->documentType, 'kfs' => $app->kfs, 'signer' => $req->signer_name])->render();
        $hash = hash('sha256', $html);
        // QA C4: the signer must sign exactly the version they opened.
        $expected = $viewedHash ?? $req->viewed_hash;
        if ($expected && ! hash_equals($hash, $expected)) {
            throw new DomainException('The document changed after you opened it. Please open and read it again before signing.');
        }
        $res = $this->esign->complete($req, $hash);
        $doc = $this->documents->storeGenerated($app, $req->documentType, $req->documentType->name.' – '.$req->signer_name, $html, $user);
        $req->update(['status' => 'signed', 'signed_at' => now(), 'signed_ip' => $ip, 'signed_user_agent' => $ua, 'document_hash' => $hash,
            'document_id' => $doc->id, 'integration_log_id' => $res->logId]);
        if ($req->signer_type === 'guarantor') {
            $app->guarantors()->whereKey($req->signer_id)->update(['signed_at' => now()]);
        }
        Auditor::record('agreement.signed', $app, [], ['document' => $req->documentType->code, 'signer' => $req->signer_type, 'hash' => $hash, 'ip' => $ip, 'device' => $ua]);
        $this->maybeStartCoolingOff($app->fresh());

        return $req;
    }

    /**
     * QA C4: the applicant opens the exact document before signing. Records when, and the SHA-256 of the
     * version shown; sign() later refuses if the signed version differs.
     */
    public function markViewed(SignatureRequest $req, User $user): string
    {
        $app = $req->application;
        if ($req->signer_type !== 'applicant') {
            throw new DomainException('Only the applicant\'s own documents can be opened here.');
        }
        $this->assertCustomerOrStaff($app, $user);
        $hash = hash('sha256', $this->guarantorDocumentHtml($req));
        $req->update(['viewed_at' => now(), 'viewed_hash' => $hash]);
        Auditor::record('agreement.document_viewed', $app, [], ['document' => $req->documentType->code, 'hash' => $hash]);

        return $hash;
    }

    /** Staff confirms receipt of a wet-ink DPN/Affidavit (stamp paper / attestation). */
    public function confirmWetInk(SignatureRequest $req, User $staff, string $note): SignatureRequest
    {
        if ($req->status !== 'wet_ink_pending') {
            throw new DomainException('This document is not awaiting wet-ink receipt.');
        }
        $req->update(['status' => 'wet_ink_received', 'signed_at' => now()]);
        Auditor::record('agreement.wet_ink_received', $req->application, [], ['document' => $req->documentType->code, 'signer' => $req->signer_name], $note);
        $this->maybeStartCoolingOff($req->application->fresh());

        return $req;
    }

    /** Agreement HTML exactly as a guarantor sees and signs it (hash binds what was shown to what is signed). */
    public function guarantorDocumentHtml(SignatureRequest $req): string
    {
        $app = $req->application;

        return view('documents.agreement', ['app' => $app, 'type' => $req->documentType, 'kfs' => $app->kfs, 'signer' => $req->signer_name])->render();
    }

    /**
     * H6: staff send a time-limited signing link to the guarantor's mobile (simulated SMS). The link opens a guest
     * page where the guarantor reads the document, confirms with an OTP sent to the same mobile, and signs.
     */
    public function sendGuarantorLink(SignatureRequest $req, User $staff, int $hours = 48): string
    {
        if ($req->signer_type !== 'guarantor' || $req->signing_method !== 'digital' || ! in_array($req->status, ['sent', 'pending'], true)) {
            throw new DomainException('Only pending digital guarantor signatures can be sent a signing link.');
        }
        if ($req->application->status !== ApplicationStatus::KfsAcknowledged) {
            throw new DomainException('Guarantors sign after the customer acknowledges the Key Fact Statement.');
        }
        $guarantor = $req->application->guarantors()->findOrFail($req->signer_id);
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('guarantor.sign', now()->addHours($hours),
            ['tenant' => $req->application->tenant->code, 'signatureRequest' => $req->id]);
        app(\App\Services\Integrations\IntegrationManager::class)->message('sms', $guarantor->mobile, null,
            "{$req->application->tenant->name}: please review and e-sign the {$req->documentType->name} as guarantor for {$req->application->customer->full_name}: {$url}");
        Auditor::record('agreement.guarantor_link_sent', $req->application, [], ['document' => $req->documentType->code, 'guarantor_id' => $guarantor->id, 'expires_hours' => $hours]);

        return $url;
    }

    /** Guarantor signs from the signed link (no user account). $viewedHash must match the document shown to them. */
    public function signAsGuarantor(SignatureRequest $req, string $viewedHash, ?string $ip = null, ?string $ua = null): SignatureRequest
    {
        $app = $req->application;
        if ($req->signer_type !== 'guarantor' || $req->signing_method !== 'digital' || ! in_array($req->status, ['sent', 'pending'], true)) {
            throw new DomainException('This document is not awaiting the guarantor\'s signature.');
        }
        if ($app->status !== ApplicationStatus::KfsAcknowledged) {
            throw new DomainException('This document can no longer be signed.');
        }
        $html = $this->guarantorDocumentHtml($req);
        $hash = hash('sha256', $html);
        if (! hash_equals($hash, $viewedHash)) {
            throw new DomainException('The document changed after you opened it. Please reload and read it again before signing.');
        }
        $res = $this->esign->complete($req, $hash);
        $doc = $this->documents->storeGenerated($app, $req->documentType, $req->documentType->name.' – '.$req->signer_name, $html);
        $req->update(['status' => 'signed', 'signed_at' => now(), 'signed_ip' => $ip, 'signed_user_agent' => $ua, 'document_hash' => $hash,
            'document_id' => $doc->id, 'integration_log_id' => $res->logId]);
        $app->guarantors()->whereKey($req->signer_id)->update(['signed_at' => now()]);
        Auditor::record('agreement.signed', $app, [], ['document' => $req->documentType->code, 'signer' => 'guarantor', 'hash' => $hash, 'ip' => $ip, 'device' => $ua, 'via' => 'guarantor_link'], tenantId: $app->tenant_id);
        $this->maybeStartCoolingOff($app->fresh());

        return $req;
    }

    public function maybeStartCoolingOff(LoanApplication $app): void
    {
        if ($app->status !== ApplicationStatus::KfsAcknowledged) {
            return;
        }
        if ($app->signatureRequests()->whereNotIn('status', ['signed', 'wet_ink_received'])->exists()) {
            return;
        }
        $hours = (int) $app->productVersion->cooling_off_hours;
        $this->applications->transition($app, ApplicationStatus::CoolingOff, null,
            "All documents signed. {$hours}-hour cooling-off period started; you may cancel without any charge until it ends.", [
                'signed_at' => now(), 'cooling_off_ends_at' => now()->addHours($hours),
            ]);
    }

    /** Cancel within cooling-off: no markup or charge (DFS §6.1). */
    public function cancelDuringCoolingOff(LoanApplication $app, User $user, string $reason): LoanApplication
    {
        $this->assertCustomerOrStaff($app, $user);
        if (! in_array($app->status, [ApplicationStatus::CoolingOff, ApplicationStatus::KfsAcknowledged, ApplicationStatus::Approved], true)) {
            throw new DomainException('Cancellation without charge is available only before disbursement during the cooling-off window.');
        }
        if ($app->status === ApplicationStatus::CoolingOff && $app->cooling_off_ends_at?->isPast()) {
            throw new DomainException('The cooling-off window has ended.');
        }

        return $this->applications->transition($app, ApplicationStatus::Cancelled, $user, 'Cancelled by customer: '.$reason.' (no charge applied)', [
            'cancelled_at' => now(), 'cancellation_reason' => $reason,
        ]);
    }

    public function waiveCoolingOff(LoanApplication $app, User $user): LoanApplication
    {
        $this->assertCustomerOrStaff($app, $user);
        if (! $app->productVersion->cooling_off_waivable) {
            throw new DomainException('This product does not permit waiving the cooling-off period.');
        }
        $app->forceFill(['cooling_off_waived' => true])->save();
        Auditor::record('agreement.cooling_off_waived', $app);

        return $this->completeCoolingOff($app);
    }

    /** Scheduler: move elapsed cooling-off cases to the disbursement queue. */
    public function completeCoolingOff(LoanApplication $app): LoanApplication
    {
        if ($app->status === ApplicationStatus::CoolingOff && $app->isCoolingOffElapsed()) {
            $this->applications->transition($app, ApplicationStatus::ReadyForDisbursement, null, 'Cooling-off period ended; queued for disbursement.');
        }

        return $app;
    }

    private function assertCustomerOrStaff(LoanApplication $app, User $user): void
    {
        $isOwner = $user->isCustomer() && (int) $user->customer_id === (int) $app->customer_id;
        $isStaff = $user->isStaff() && (int) $user->tenant_id === (int) $app->tenant_id && $user->hasPermission('agreements.manage');
        if (! $isOwner && ! $isStaff) {
            throw new DomainException('You are not authorized to act on this agreement.');
        }
    }
}
