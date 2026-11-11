<?php

namespace App\Services\Approvals\Handlers;

use App\Enums\JournalStatus;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Accounting\GeneralLedger;
use App\Services\Audit\Auditor;
use Illuminate\Database\Eloquent\Model;

/** Final approval posts the voucher; the final approver is recorded as the checker. */
class JournalApprovalHandler extends BaseHandler
{
    public function __construct(private GeneralLedger $gl) {}

    public function approved(Model $subject, ApprovalRequest $request, User $actor): void
    {
        $this->gl->post($subject, $actor);
    }

    public function rejected(Model $subject, ApprovalRequest $request, User $actor, string $comment): void
    {
        $subject->forceFill(['status' => JournalStatus::Rejected, 'rejection_reason' => $comment])->save();
        Auditor::record('journal.rejected', $subject, [], [], $comment);
    }

    public function returned(Model $subject, ApprovalRequest $request, User $actor, string $comment): void
    {
        $subject->forceFill(['status' => JournalStatus::Draft, 'rejection_reason' => 'Returned: '.$comment])->save();
    }
}
