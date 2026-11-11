<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Accounting\FinanceOperations;
use App\Services\Audit\Auditor;
use Illuminate\Database\Eloquent\Model;

class FundTransferApprovalHandler extends BaseHandler
{
    public function __construct(private FinanceOperations $ops) {}

    public function approved(Model $subject, ApprovalRequest $request, User $actor): void
    {
        $this->ops->completeFundTransfer($subject);
    }

    public function rejected(Model $subject, ApprovalRequest $request, User $actor, string $comment): void
    {
        $subject->forceFill(['status' => 'rejected'])->save();
        Auditor::record('fms.fund_transfer_rejected', $subject, [], [], $comment);
    }
}
