<?php

namespace App\Services\Approvals\Handlers;

use App\Enums\VendorPaymentStatus;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Database\Eloquent\Model;

/** Approval does not pay the vendor: an authorized user must then release the payment. */
class VendorPaymentApprovalHandler extends BaseHandler
{
    public function approved(Model $subject, ApprovalRequest $request, User $actor): void
    {
        $subject->status->assertCanTransitionTo(VendorPaymentStatus::Approved, 'Vendor payment');
        $subject->forceFill(['status' => VendorPaymentStatus::Approved])->save();
    }

    public function rejected(Model $subject, ApprovalRequest $request, User $actor, string $comment): void
    {
        $subject->forceFill(['status' => VendorPaymentStatus::Rejected, 'failure_reason' => $comment])->save();
        Auditor::record('ap.payment_rejected', $subject, [], [], $comment);
    }

    public function returned(Model $subject, ApprovalRequest $request, User $actor, string $comment): void
    {
        $subject->forceFill(['status' => VendorPaymentStatus::Draft, 'failure_reason' => 'Returned: '.$comment])->save();
    }
}
