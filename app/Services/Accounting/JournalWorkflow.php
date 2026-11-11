<?php

namespace App\Services\Accounting;

use App\Enums\ApprovalType;
use App\Enums\JournalStatus;
use App\Exceptions\DomainException;
use App\Models\JournalVoucher;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Audit\Auditor;
use App\Services\Tenancy\TenantSettings;
use Illuminate\Support\Facades\DB;

/**
 * Maker-checker for manual journal vouchers (FMS §1.2, §14.2).
 *  - With finance.journal_requires_approval (default) a submitted voucher enters the finance approval
 *    hierarchy; the final approver posts it (JournalApprovalHandler).
 *  - Without it, a different user holding fms.journals.post posts the draft directly.
 */
class JournalWorkflow
{
    public function __construct(private GeneralLedger $gl, private ApprovalEngine $approvals) {}

    public function requiresApproval(): bool
    {
        return (bool) TenantSettings::get('finance', 'journal_requires_approval', true);
    }

    public function submit(JournalVoucher $jv, User $maker): JournalVoucher
    {
        if ($jv->origin !== 'manual') {
            throw new DomainException('System vouchers are posted automatically and cannot be submitted.');
        }
        if (! in_array($jv->status, [JournalStatus::Draft, JournalStatus::Rejected], true)) {
            throw new DomainException('Only draft or rejected vouchers can be submitted.');
        }
        $this->gl->assertBalanced($jv->lines->map(fn ($l) => ['debit' => $l->debit, 'credit' => $l->credit])->all());
        $this->gl->openPeriodFor($jv->voucher_date);

        if (! $this->requiresApproval()) {
            Auditor::record('journal.submitted', $jv, [], ['route' => 'direct_post']);

            return $jv;
        }

        return DB::transaction(function () use ($jv, $maker) {
            $jv->forceFill(['status' => JournalStatus::PendingApproval, 'submitted_at' => now(), 'rejection_reason' => null])->save();
            $this->approvals->submit($jv, ApprovalType::JournalVoucher, (string) $jv->total_debit, $maker, branchId: $jv->branch_id);
            Auditor::record('journal.submitted', $jv, [], ['route' => 'approval', 'total' => $jv->total_debit]);

            return $jv;
        });
    }

    /** Direct posting by a checker when the tenant does not route vouchers through approval. */
    public function postDirect(JournalVoucher $jv, User $poster): JournalVoucher
    {
        if ($this->requiresApproval()) {
            throw new DomainException('This institution requires journal vouchers to be approved through the finance approval hierarchy.');
        }
        if (! $poster->hasPermission('fms.journals.post')) {
            throw new DomainException('You are not authorized to post journal vouchers.');
        }
        if ($jv->status !== JournalStatus::Draft) {
            throw new DomainException('Only draft vouchers can be posted directly.');
        }

        return $this->gl->post($jv, $poster);
    }

    public function discard(JournalVoucher $jv, User $actor): void
    {
        if ($jv->origin !== 'manual' || ! in_array($jv->status, [JournalStatus::Draft, JournalStatus::Rejected], true)) {
            throw new DomainException('Only manual draft or rejected vouchers can be discarded.');
        }
        if ((int) $jv->created_by !== (int) $actor->id && ! $actor->hasPermission('fms.journals.post')) {
            throw new DomainException('Only the maker or a finance officer can discard this draft.');
        }
        Auditor::record('journal.discarded', $jv, ['voucher_no' => $jv->voucher_no]);
        DB::transaction(function () use ($jv) {
            $jv->lines()->delete();
            $jv->delete();
        });
    }

    /**
     * Maker step of a reversal (S6): any posted voucher – manual or system – is reversed only after a second
     * user approves the request (journal_reversal workflow). Refused when the original period is closed.
     */
    public function requestReversal(JournalVoucher $jv, User $requester, string $reason): \App\Models\ApprovalRequest
    {
        if (! $requester->hasPermission('fms.journals.reverse')) {
            throw new DomainException('You are not authorized to request journal reversals.');
        }
        if (strlen(trim($reason)) < 5) {
            throw new DomainException('Give a reason for the reversal (at least 5 characters).');
        }

        return DB::transaction(function () use ($jv, $requester, $reason) {
            $jv = JournalVoucher::whereKey($jv->id)->lockForUpdate()->firstOrFail();
            if ($jv->status !== JournalStatus::Posted) {
                throw new DomainException('Only posted vouchers can be reversed.');
            }
            if ($jv->period && $jv->period->status !== 'open') {
                throw new DomainException("The voucher's accounting period {$jv->period->name} is closed. Reopen it (restricted, audited) before requesting a reversal.");
            }
            $jv->forceFill(['reversal_requested_by' => $requester->id, 'reversal_requested_at' => now(), 'reversal_reason' => trim($reason)])->save();
            $request = $this->approvals->submit($jv, ApprovalType::JournalReversal, (string) $jv->total_debit, $requester);
            Auditor::record('journal.reversal_requested', $jv, [], ['voucher_no' => $jv->voucher_no], $reason);

            return $request;
        });
    }
}
