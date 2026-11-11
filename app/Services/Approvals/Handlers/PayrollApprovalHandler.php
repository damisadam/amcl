<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Accounting\FinanceOperations;
use App\Services\Audit\Auditor;
use Illuminate\Database\Eloquent\Model;

class PayrollApprovalHandler extends BaseHandler
{
    public function __construct(private FinanceOperations $ops) {}

    public function approved(Model $subject, ApprovalRequest $request, User $actor): void
    {
        $this->ops->postPayroll($subject);
    }

    public function rejected(Model $subject, ApprovalRequest $request, User $actor, string $comment): void
    {
        $subject->forceFill(['status' => 'rejected'])->save();
        Auditor::record('fms.payroll_run_rejected', $subject, [], [], $comment);
    }
}
