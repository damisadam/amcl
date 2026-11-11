<?php

namespace App\Services\Lending;

use App\Enums\AmlStatus;
use App\Enums\ApplicationStatus;
use App\Enums\KycStatus;
use App\Models\LoanApplication;
use App\Models\User;

/**
 * Case readiness checklist (QA S8 / H6): one view of every control an approver or disbursement officer relies on —
 * applicant KYC & AML/PEP, bureau (applicant and each guarantor), guarantor verification, field visits, assessment
 * freshness, documents, KFS, signatures and cooling-off. AML/PEP details are shown only to holders of `aml.view`.
 */
class ReadinessService
{
    public function __construct(private UnderwritingService $uw, private ApplicationService $applications) {}

    /** @return list<array{group:string,label:string,ok:?bool,detail:?string}> ok=null means "not applicable yet" */
    public function checks(LoanApplication $a, User $viewer): array
    {
        $c = $a->customer;
        $aml = $viewer->hasPermission('aml.view');
        $out = [];
        $add = function (string $group, string $label, ?bool $ok, ?string $detail = null) use (&$out) {
            $out[] = compact('group', 'label', 'ok', 'detail');
        };

        $add('Applicant', 'KYC (NADRA, liveness)', $c->kyc_status === KycStatus::Passed, $c->kyc_status->label());
        $amlOk = in_array($c->aml_status, [AmlStatus::Clear, AmlStatus::ClearedAfterReview], true);
        $hits = collect($c->kycVerifications()->where('step', 'aml_screening')->latest('id')->first()?->result['hits'] ?? []);
        $add('Applicant', 'Sanctions / AML screening', $amlOk, $aml ? $c->aml_status->label() : ($amlOk ? 'Cleared' : 'Pending compliance'));
        if ($aml) {
            $pep = $hits->contains(fn ($h) => str_contains(strtoupper($h['list'] ?? '').strtoupper($h['category'] ?? ''), 'PEP'));
            $add('Applicant', 'PEP', ! $pep || $c->aml_status === AmlStatus::ClearedAfterReview, $pep ? ($c->aml_status === AmlStatus::ClearedAfterReview ? 'PEP – cleared by compliance' : 'PEP potential match') : 'No PEP hit');
        }
        $bureau = $a->bureauChecks()->where('subject_type', 'applicant')->latest('id')->first();
        $add('Applicant', 'Credit bureau report', $bureau !== null && $bureau->status !== 'adverse', $bureau ? ucfirst($bureau->status).($bureau->score ? ' · score '.$bureau->score : '') : 'Not requested');

        foreach ($a->guarantors as $g) {
            $r = $this->uw->guarantorReadiness($g);
            $add('Guarantors', $g->name, $r['status'] === 'verified', $r['status'] === 'verified' ? 'Verified (bureau + AML)' : ucfirst($r['status']).': '.implode('; ', $r['reasons']));
        }
        if ($a->guarantors->isEmpty()) {
            $req = (int) $a->productVersion->guarantors_required;
            $add('Guarantors', 'Guarantors', $req === 0 ? null : false, $req === 0 ? 'Not required for this product' : "{$req} required, none added");
        }

        $visits = $a->fieldVisits;
        $pending = $visits->whereIn('status.value', ['scheduled', 'in_progress'])->count();
        $done = $visits->where('status.value', 'completed');
        $add('Verification', 'Field visit', $visits->isEmpty() ? false : ($pending === 0 && $done->isNotEmpty()),
            $visits->isEmpty() ? 'None scheduled' : ($pending ? "{$pending} pending" : $done->count().' completed · '.$done->pluck('recommendation')->filter()->map(fn ($r) => str_replace('_', ' ', $r))->join(', ')));
        $stale = $this->uw->isAssessmentStale($a);
        $add('Verification', 'Credit assessment', $stale === null ? false : ! $stale, $stale === null ? 'Not run' : ($stale ? 'Stale – inputs changed, re-run required' : 'Up to date · grade '.$a->credit_grade.' · DBR '.$a->dbr.'%'));
        $missing = $this->applications->missingDocuments($a);
        $add('Verification', 'Required documents', $missing === [], $missing ? 'Missing: '.implode(', ', $missing) : 'All uploaded');

        $afterApproval = in_array($a->status, [ApplicationStatus::Approved, ApplicationStatus::KfsAcknowledged, ApplicationStatus::CoolingOff,
            ApplicationStatus::ReadyForDisbursement, ApplicationStatus::DisbursementInProgress, ApplicationStatus::Disbursed], true);
        $add('Agreement', 'KFS acknowledged', $afterApproval ? $a->kfs_acknowledged_at !== null : null, $a->kfs_acknowledged_at?->format('d M Y H:i') ?? ($afterApproval ? 'Awaiting customer' : 'After approval'));
        $sigs = $a->signatureRequests;
        $open = $sigs->whereNotIn('status', ['signed', 'wet_ink_received']);
        $add('Agreement', 'Signatures', $sigs->isEmpty() ? null : $open->isEmpty(),
            $sigs->isEmpty() ? 'After KFS' : ($open->isEmpty() ? 'All '.$sigs->count().' complete' : $open->map(fn ($r) => $r->documentType->name.' ('.$r->signer_type.')')->join(', ').' outstanding'));
        if ($a->cooling_off_ends_at) {
            $add('Agreement', 'Cooling-off', $a->isCoolingOffElapsed(), ($a->isCoolingOffElapsed() ? 'Ended ' : 'Ends ').$a->cooling_off_ends_at->format('d M Y H:i'));
        }

        return $out;
    }
}
