<?php

namespace App\Services\Approvals\Handlers;

use App\Enums\ReconciliationStatus;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Accounting\ReconciliationService;
use Illuminate\Database\Eloquent\Model;

class ReconciliationApprovalHandler extends BaseHandler
{
    public function __construct(private ReconciliationService $service) {}

    public function approved(Model $subject, ApprovalRequest $request, User $actor): void
    {
        $this->service->approve($subject, $actor);
    }

    public function rejected(Model $subject, ApprovalRequest $request, User $actor, string $comment): void
    {
        $subject->forceFill(['status' => ReconciliationStatus::InReview])->save();
    }
}
