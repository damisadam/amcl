<?php

namespace App\Livewire\Ops\Admin;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Livewire\Concerns\HandlesActions;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStage;
use App\Models\ApprovalWorkflow;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Role;
use App\Services\Audit\Auditor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Configurable approval hierarchies (LMS §1.2/§9.5, FMS §15.2). The 5-stage loan chain is only a
 * provisioned template; admins add, remove, reorder and threshold stages without code changes.
 * In-flight requests keep their stage_plan snapshot.
 */
#[Layout('layouts.ops')]
class Workflows extends Component
{
    use HandlesActions;

    #[Url(except: 'loan')]
    public string $type = 'loan';

    #[Url(except: null)]
    public ?int $workflowId = null;

    public bool $showWorkflow = false;

    public array $wf = [];

    public bool $showStage = false;

    public ?int $stageId = null;

    public array $stage = [];

    public function mount(): void
    {
        $this->need('approvals.configure');
        $this->workflowId ??= ApprovalWorkflow::where('type', $this->type)->orderBy('priority')->value('id');
    }

    public function updatedType(): void
    {
        abort_unless(in_array($this->type, ApprovalType::values(), true), 422);
        $this->workflowId = ApprovalWorkflow::where('type', $this->type)->orderBy('priority')->value('id');
    }

    private function workflow(): ApprovalWorkflow
    {
        return ApprovalWorkflow::findOrFail($this->workflowId);
    }

    public function editWorkflow(?int $id = null): void
    {
        $this->need('approvals.configure');
        $w = $id ? ApprovalWorkflow::findOrFail($id) : null;
        $this->wf = ['id' => $w?->id, 'type' => $w?->type->value ?? $this->type, 'name' => $w?->name ?? '', 'product_id' => (string) ($w?->product_id ?? ''),
            'branch_id' => (string) ($w?->branch_id ?? ''), 'min_amount' => (string) ($w?->min_amount ?? ''), 'max_amount' => (string) ($w?->max_amount ?? ''),
            'priority' => (string) ($w?->priority ?? 100), 'is_active' => (bool) ($w?->is_active ?? true)];
        $this->resetValidation();
        $this->showWorkflow = true;
    }

    public function saveWorkflow(): void
    {
        $this->need('approvals.configure');
        $this->validate([
            'wf.type' => 'required|in:'.implode(',', ApprovalType::values()), 'wf.name' => 'required|string|max:120',
            'wf.product_id' => 'nullable|integer', 'wf.branch_id' => 'nullable|integer',
            'wf.min_amount' => 'nullable|numeric|min:0', 'wf.max_amount' => 'nullable|numeric|min:0|gte:wf.min_amount',
            'wf.priority' => 'required|integer|min:1|max:999',
        ]);
        foreach (['product_id' => Product::class, 'branch_id' => Branch::class] as $k => $cls) {
            if ($this->wf[$k] !== '' && ! $cls::whereKey($this->wf[$k])->exists()) {
                $this->addError('wf.'.$k, __('Invalid selection.'));

                return;
            }
        }
        $data = collect($this->wf)->except('id')->map(fn ($v) => $v === '' ? null : $v)->all();
        $w = $this->wf['id'] ? ApprovalWorkflow::findOrFail($this->wf['id']) : new ApprovalWorkflow;
        $w->fill($data);
        $isNew = ! $w->exists;
        if (! $isNew) {
            Auditor::changes('approvals.workflow_updated', $w);
        }
        $w->save();
        if ($isNew) {
            Auditor::record('approvals.workflow_created', $w, [], $data);
        }
        $this->type = $w->type->value;
        $this->workflowId = $w->id;
        $this->showWorkflow = false;
        $this->toast(__('Workflow saved.'));
    }

    public function editStage(?int $id = null): void
    {
        $this->need('approvals.configure');
        $s = $id ? $this->workflow()->stages()->findOrFail($id) : null;
        $this->stageId = $s?->id;
        $this->stage = ['name' => $s?->name ?? '', 'role_id' => (string) ($s?->role_id ?? ''), 'min_amount' => (string) ($s?->min_amount ?? ''),
            'comment_required_on_approve' => (bool) ($s?->comment_required_on_approve ?? false), 'comment_required_on_reject' => (bool) ($s?->comment_required_on_reject ?? true),
            'allow_return' => (bool) ($s?->allow_return ?? true), 'sla_hours' => (string) ($s?->sla_hours ?? '24')];
        $this->resetValidation();
        $this->showStage = true;
    }

    public function saveStage(): void
    {
        $this->need('approvals.configure');
        $this->validate(['stage.name' => 'required|string|max:120', 'stage.role_id' => 'required|integer', 'stage.min_amount' => 'nullable|numeric|min:0', 'stage.sla_hours' => 'nullable|integer|min:1|max:720']);
        $role = Role::where('tenant_id', TenantContext::id())->find($this->stage['role_id']);
        if (! $role) {
            $this->addError('stage.role_id', __('Select a role of this institution.'));

            return;
        }
        $w = $this->workflow();
        $data = ['name' => $this->stage['name'], 'role_id' => $role->id, 'min_amount' => $this->stage['min_amount'] === '' ? null : $this->stage['min_amount'],
            'comment_required_on_approve' => (bool) $this->stage['comment_required_on_approve'], 'comment_required_on_reject' => (bool) $this->stage['comment_required_on_reject'],
            'allow_return' => (bool) $this->stage['allow_return'], 'sla_hours' => $this->stage['sla_hours'] === '' ? null : (int) $this->stage['sla_hours']];
        $isFinal = self::isDisbursementStage($data['name'], $role->key);
        if ($this->stageId) {
            $s = $w->stages()->findOrFail($this->stageId);
            if ($isFinal && $w->stages()->where('sequence', '>', $s->sequence)->exists()) {
                $this->addError('stage.role_id', __('The disbursement approval stage must be the last stage. Move it to the end first.'));

                return;
            }
            $s->fill($data);
            Auditor::changes('approvals.stage_updated', $s);
            $s->save();
        } else {
            $final = $w->stages()->get()->first(fn ($st) => self::isDisbursementStage($st->name, $st->role?->key));
            if ($isFinal && $final) {
                $this->addError('stage.role_id', __('This workflow already ends with a disbursement approval stage.'));

                return;
            }
            DB::transaction(function () use ($w, $data, $role, $final, &$s) {
                if ($final && $final->sequence === (int) $w->stages()->max('sequence')) {
                    // Keep disbursement approval last: insert the new stage just before it.
                    $seq = $final->sequence;
                    $final->update(['sequence' => 1000 + $seq]);
                    $s = $w->stages()->create($data + ['sequence' => $seq]);
                    $final->update(['sequence' => $seq + 1]);
                } else {
                    $s = $w->stages()->create($data + ['sequence' => (int) $w->stages()->max('sequence') + 1]);
                }
                Auditor::record('approvals.stage_added', $w, [], ['stage' => $s->name, 'role' => $role->key, 'min_amount' => $s->min_amount]);
            });
        }
        $this->showStage = false;
        $this->toast(__('Stage saved. In-flight requests keep their original stage plan.'));
    }

    public function move(int $stageId, int $dir): void
    {
        $this->need('approvals.configure');
        $w = $this->workflow();
        $stages = $w->stages()->get()->values();
        $i = $stages->search(fn ($s) => $s->id === $stageId);
        $j = $i + ($dir < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= $stages->count()) {
            return;
        }
        $order = $stages->pluck('id')->all();
        [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        $byId = $stages->keyBy('id');
        $finalAt = collect($order)->search(fn ($id) => self::isDisbursementStage($byId[$id]->name, $byId[$id]->role?->key));
        if ($finalAt !== false && $finalAt !== count($order) - 1) {
            $this->toast(__('Disbursement approval must remain the last stage, after any committee or risk decision.'), 'red');

            return;
        }
        DB::transaction(function () use ($stages, $order, $w) {
            foreach ($order as $seq => $id) {
                ApprovalStage::whereKey($id)->update(['sequence' => 1000 + $seq]); // avoid transient duplicates
            }
            foreach ($order as $seq => $id) {
                ApprovalStage::whereKey($id)->update(['sequence' => $seq + 1]);
            }
            Auditor::record('approvals.stages_reordered', $w, ['order' => $stages->pluck('name')->all()], ['order' => ApprovalStage::whereIn('id', $order)->orderBy('sequence')->pluck('name')->all()]);
        });
    }

    /** A stage that releases the case for disbursement must always be the final decision. */
    public static function isDisbursementStage(?string $name, ?string $roleKey): bool
    {
        return $roleKey === 'disbursement_approver' || str_contains(strtolower((string) $name), 'disbursement');
    }

    public function removeStage(int $stageId): void
    {
        $this->need('approvals.configure');
        $w = $this->workflow();
        $s = $w->stages()->findOrFail($stageId);
        if ($w->stages()->count() <= 1) {
            $this->toast(__('A workflow needs at least one stage. Deactivate the workflow instead.'), 'red');

            return;
        }
        if (ApprovalRequest::where('current_stage_id', $s->id)->where('status', ApprovalStatus::Pending->value)->exists()) {
            $this->toast(__('Requests are currently waiting at this stage; it cannot be removed until they move on.'), 'red');

            return;
        }
        Auditor::record('approvals.stage_removed', $w, ['stage' => $s->name, 'role_id' => $s->role_id]);
        $s->delete();
        $w->stages()->get()->values()->each(fn ($st, $k) => $st->update(['sequence' => $k + 1]));
        $this->toast(__('Stage removed.'));
    }

    public function toggleActive(int $id): void
    {
        $this->need('approvals.configure');
        $w = ApprovalWorkflow::findOrFail($id);
        $w->is_active = ! $w->is_active;
        Auditor::changes('approvals.workflow_updated', $w);
        $w->save();
    }

    public function render()
    {
        $this->need('approvals.configure');
        $current = $this->workflowId ? ApprovalWorkflow::with(['stages.role', 'product', 'branch'])->find($this->workflowId) : null;

        return view('livewire.ops.admin.workflows', [
            'types' => ApprovalType::options(),
            'workflows' => ApprovalWorkflow::withCount('stages')->with(['product', 'branch'])->where('type', $this->type)->orderBy('priority')->get(),
            'current' => $current,
            'roles' => Role::where('tenant_id', TenantContext::id())->orderBy('name')->pluck('name', 'id'),
            'products' => Product::orderBy('name')->pluck('name', 'id'),
            'branches' => Branch::orderBy('name')->pluck('name', 'id'),
            'inFlight' => $current ? ApprovalRequest::where('approval_workflow_id', $current->id)->where('status', 'pending')->count() : 0,
        ])->title(__('Approval workflows'));
    }
}
