<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Accounting\GeneralLedger;
use App\Services\Audit\Auditor;
use Illuminate\Database\Eloquent\Model;

/** Checker step of a journal reversal: the approver posts the linked reversal voucher. */
class JournalReversalApprovalHandler extends BaseHandler
{
    public function __construct(private GeneralLedger $gl) {}

    public function approved(Model $subject, ApprovalRequest $request, User $actor): void
    {
        $this->gl->reverse($subject, $subject->reversal_reason ?? 'Approved reversal', $actor);
    }

    public function rejected(Model $subject, ApprovalRequest $request, User $actor, string $comment): void
    {
        $subject->forceFill(['reversal_requested_by' => null, 'reversal_requested_at' => null, 'reversal_reason' => null])->save();
        Auditor::record('journal.reversal_declined', $subject, [], [], $comment);
    }
}
