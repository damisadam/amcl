<?php

namespace App\Services\Approvals\Handlers;

use App\Enums\ApplicationStatus;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Lending\AgreementService;
use App\Services\Lending\ApplicationService;
use Illuminate\Database\Eloquent\Model;

class LoanApprovalHandler extends BaseHandler
{
    public function __construct(private ApplicationService $applications) {}

    public function stageAdvanced(Model $subject, ApprovalRequest $request, User $actor): void
    {
        $subject->histories()->create([
            'from_status' => $subject->status, 'to_status' => $subject->status, 'actor_id' => $actor->id,
            'comment' => 'Approved at stage: '.($request->actions()->latest('id')->first()?->stage_name).'. Next: '.$request->fresh()->currentStage?->name,
            'visible_to_customer' => false,
        ]);
    }

    public function approved(Model $subject, ApprovalRequest $request, User $actor): void
    {
        $this->applications->transition($subject, ApplicationStatus::Approved, $actor, 'Approved through all '.count($request->stage_plan).' approval stages.', [
            'approved_at' => now(), 'approved_amount' => $subject->approved_amount ?? $subject->requested_amount,
        ]);
        app(AgreementService::class)->generateKfs($subject->fresh(), $actor);
    }

    public function rejected(Model $subject, ApprovalRequest $request, User $actor, string $comment): void
    {
        $this->applications->transition($subject, ApplicationStatus::Rejected, $actor, $comment, [
            'rejected_at' => now(), 'rejection_code' => 'policy', 'rejection_notes' => $comment,
        ]);
    }

    public function returned(Model $subject, ApprovalRequest $request, User $actor, string $comment): void
    {
        $this->applications->returnForCorrection($subject, $actor, $comment, false);
    }
}
