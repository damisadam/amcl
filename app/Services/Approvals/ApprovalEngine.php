<?php

namespace App\Services\Approvals;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Events\ApprovalTaskAssigned;
use App\Exceptions\DomainException;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStage;
use App\Models\ApprovalWorkflow;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Notifications\Notifier;
use App\Services\Tenancy\TenantSettings;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Configurable multi-stage approval engine shared by lending and finance.
 *
 * Workflow selection: the active workflow of the given type that matches product/branch/amount,
 * lowest priority first. Stage plan: the workflow's stages whose min_amount <= amount, frozen at
 * submission so later configuration edits do not alter in-flight requests.
 *
 * Controls: the requester can never approve their own request; by default one user may act on only
 * one stage of a request; the actor must hold the stage's role (or be the explicit delegate).
 */
class ApprovalEngine
{
    public function __construct(private ApprovalHandlerRegistry $handlers) {}

    public function resolveWorkflow(ApprovalType $type, string $amount, ?int $productId = null, ?int $branchId = null): ApprovalWorkflow
    {
        $workflow = ApprovalWorkflow::query()
            ->where('type', $type->value)->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('product_id')->orWhere('product_id', $productId))
            ->where(fn (Builder $q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->where(fn (Builder $q) => $q->whereNull('min_amount')->orWhere('min_amount', '<=', $amount))
            ->where(fn (Builder $q) => $q->whereNull('max_amount')->orWhere('max_amount', '>=', $amount))
            ->orderByRaw('product_id IS NULL, branch_id IS NULL') // most specific first
            ->orderBy('priority')
            ->with('stages.role')
            ->first();

        if (! $workflow || $workflow->stages->isEmpty()) {
            throw new DomainException("No active {$type->label()} approval workflow is configured for this amount. Configure one under Approval Workflows.");
        }

        return $workflow;
    }

    public function submit(Model $subject, ApprovalType $type, string $amount, User $requester, ?int $productId = null, ?int $branchId = null): ApprovalRequest
    {
        return DB::transaction(function () use ($subject, $type, $amount, $requester, $productId, $branchId) {
            $open = ApprovalRequest::where('approvable_type', $subject->getMorphClass())->where('approvable_id', $subject->getKey())
                ->where('status', ApprovalStatus::Pending->value)->lockForUpdate()->first();
            if ($open) {
                return $open; // idempotent re-submit
            }

            $workflow = $this->resolveWorkflow($type, $amount, $productId, $branchId);
            $plan = $workflow->stages
                ->filter(fn (ApprovalStage $s) => $s->min_amount === null || Money::gte($amount, $s->min_amount))
                ->values()
                ->map(fn (ApprovalStage $s) => ['id' => $s->id, 'sequence' => $s->sequence, 'name' => $s->name, 'role_id' => $s->role_id, 'role' => $s->role->name])
                ->all();
            if (! $plan) {
                throw new DomainException('The selected workflow has no stage applicable to this amount.');
            }

            $request = ApprovalRequest::create([
                'approvable_type' => $subject->getMorphClass(),
                'approvable_id' => $subject->getKey(),
                'approval_workflow_id' => $workflow->id,
                'type' => $type,
                'amount' => $amount,
                'stage_plan' => $plan,
                'current_sequence' => $plan[0]['sequence'],
                'current_stage_id' => $plan[0]['id'],
                'status' => ApprovalStatus::Pending,
                'requested_by' => $requester->id,
                'stage_entered_at' => now(),
            ]);
            $request->actions()->create(['actor_id' => $requester->id, 'action' => 'submit', 'stage_name' => 'Submission', 'tenant_id' => $request->tenant_id]);
            Auditor::record('approval.submitted', $request, [], ['type' => $type->value, 'amount' => $amount, 'workflow' => $workflow->name, 'stages' => count($plan)]);
            $this->notifyStage($request);

            return $request;
        });
    }

    /** Users who may act on the request's current stage right now. */
    public function eligibleActors(ApprovalRequest $request): \Illuminate\Support\Collection
    {
        if ($request->assigned_to) {
            return User::whereKey($request->assigned_to)->get();
        }
        $roleId = $request->currentStage?->role_id;

        return User::query()->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('roles.id', $roleId))->get()
            ->reject(fn (User $u) => ! $this->canAct($request, $u, false));
    }

    public function canAct(ApprovalRequest $request, User $user, bool $throw = false): bool
    {
        $fail = function (string $msg) use ($throw) {
            if ($throw) {
                throw new DomainException($msg);
            }

            return false;
        };

        if ($request->status !== ApprovalStatus::Pending) {
            return $fail('This approval request is no longer pending.');
        }
        if ((int) $request->tenant_id !== (int) $user->tenant_id) {
            return $fail('You are not authorized for this request.');
        }
        if (! $user->hasPermission('approvals.act')) {
            return $fail('You do not have permission to act on approvals.');
        }
        if ((int) $request->requested_by === (int) $user->id) {
            return $fail('Segregation of duties: you cannot approve a request you submitted.');
        }
        $subject = $request->approvable;
        if ($subject && in_array((int) $user->id, array_filter([(int) ($subject->created_by ?? 0), (int) ($subject->requested_by ?? 0)]), true)) {
            return $fail('Segregation of duties: you created this item and cannot approve it.');
        }
        if ($request->assigned_to) {
            if ((int) $request->assigned_to !== (int) $user->id) {
                return $fail('This stage has been delegated to another approver.');
            }
        } elseif (! $user->roles->contains('id', $request->currentStage?->role_id)) {
            return $fail('This stage requires the '.($request->currentStage?->role?->name ?? 'assigned').' role.');
        }
        if (TenantSettings::get('lending', 'prevent_multi_stage_same_approver', true)) {
            $actedBefore = $request->actions()->where('actor_id', $user->id)->where('action', 'approve')->exists();
            if ($actedBefore) {
                return $fail('Segregation of duties: you already approved an earlier stage of this request.');
            }
        }

        return true;
    }

    public function approve(ApprovalRequest $request, User $actor, ?string $comment = null): ApprovalRequest
    {
        return $this->act($request, $actor, 'approve', $comment);
    }

    public function reject(ApprovalRequest $request, User $actor, string $comment): ApprovalRequest
    {
        return $this->act($request, $actor, 'reject', $comment);
    }

    public function returnForCorrection(ApprovalRequest $request, User $actor, string $comment): ApprovalRequest
    {
        return $this->act($request, $actor, 'return', $comment);
    }

    public function delegate(ApprovalRequest $request, User $actor, User $delegate, string $comment): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $actor, $delegate, $comment) {
            $request = ApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->canAct($request, $actor, true);
            if (! $delegate->roles->contains('id', $request->currentStage->role_id) || (int) $delegate->tenant_id !== (int) $request->tenant_id) {
                throw new DomainException('The delegate must hold the same stage role in this institution.');
            }
            $request->update(['assigned_to' => $delegate->id]);
            $request->actions()->create(['actor_id' => $actor->id, 'action' => 'delegate', 'comment' => $comment, 'delegated_to' => $delegate->id,
                'approval_stage_id' => $request->current_stage_id, 'stage_name' => $request->currentStage->name, 'tenant_id' => $request->tenant_id]);
            Auditor::record('approval.delegated', $request, [], ['to' => $delegate->name], $comment);
            app(Notifier::class)->toUser($delegate, 'approval_task', ['title' => 'Approval task delegated to you', 'body' => $request->type->label().' awaiting your decision.']);

            return $request;
        });
    }

    private function act(ApprovalRequest $request, User $actor, string $action, ?string $comment): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $actor, $action, $comment) {
            $request = ApprovalRequest::whereKey($request->id)->lockForUpdate()->with('currentStage.role')->firstOrFail();
            $this->canAct($request, $actor, true);
            $stage = $request->currentStage;

            if ($action === 'approve' && $stage->comment_required_on_approve && blank($comment)) {
                throw new DomainException('A comment is required to approve at this stage.');
            }
            if (in_array($action, ['reject', 'return'], true) && blank($comment)) {
                throw new DomainException('A reason is required to '.($action === 'reject' ? 'reject' : 'return').' this request.');
            }
            if ($action === 'return' && ! $stage->allow_return) {
                throw new DomainException('Returning is not permitted at this stage.');
            }

            $request->actions()->create([
                'approval_stage_id' => $stage->id, 'stage_name' => $stage->name, 'actor_id' => $actor->id,
                'action' => $action, 'comment' => $comment, 'tenant_id' => $request->tenant_id,
            ]);

            $handler = $this->handlers->for($request->type);
            $subject = $request->approvable;
            $before = ['status' => $request->status->value, 'stage' => $stage->name];

            if ($action === 'approve') {
                $plan = collect($request->stage_plan);
                $idx = $plan->search(fn ($s) => $s['id'] === $stage->id);
                $next = $plan->get($idx + 1);
                if ($next) {
                    $request->update(['current_stage_id' => $next['id'], 'current_sequence' => $next['sequence'], 'assigned_to' => null, 'stage_entered_at' => now()]);
                    $handler->stageAdvanced($subject, $request, $actor);
                    $this->notifyStage($request->fresh('currentStage.role'));
                } else {
                    $request->update(['status' => ApprovalStatus::Approved, 'completed_at' => now(), 'current_stage_id' => null, 'assigned_to' => null]);
                    $handler->approved($subject, $request, $actor);
                }
            } elseif ($action === 'reject') {
                $request->update(['status' => ApprovalStatus::Rejected, 'completed_at' => now(), 'assigned_to' => null]);
                $handler->rejected($subject, $request, $actor, $comment);
            } else {
                $request->update(['status' => ApprovalStatus::Returned, 'completed_at' => now(), 'assigned_to' => null]);
                $handler->returned($subject, $request, $actor, $comment);
            }

            Auditor::record("approval.{$action}", $request, $before, ['status' => $request->status->value, 'stage' => $stage->name], $comment);

            return $request;
        });
    }

    private function notifyStage(ApprovalRequest $request): void
    {
        $roleId = $request->currentStage?->role_id;
        $users = User::where('status', 'active')->whereHas('roles', fn ($q) => $q->where('roles.id', $roleId))->get();
        foreach ($users as $u) {
            if ((int) $u->id === (int) $request->requested_by) {
                continue;
            }
            app(Notifier::class)->toUser($u, 'approval_task', [
                'title' => 'Approval required: '.$request->currentStage->name,
                'body' => $request->type->label().' for '.Money::format($request->amount).' is awaiting your decision.',
                'url' => '/ops/approvals',
            ]);
        }
        rescue(fn () => ApprovalTaskAssigned::dispatch($request->tenant_id, $request->id, $request->currentStage?->name, $roleId), report: false);
    }
}
