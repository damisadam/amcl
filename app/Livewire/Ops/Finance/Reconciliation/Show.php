<?php

namespace App\Livewire\Ops\Finance\Reconciliation;

use App\Enums\ReconciliationStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Models\ApprovalRequest;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationItem;
use App\Services\Accounting\ReconciliationService;
use App\Services\Approvals\ApprovalEngine;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.ops')]
class Show extends Component
{
    use HandlesActions;

    public ReconciliationBatch $batch;

    public string $filter = 'exceptions';

    public ?int $internalId = null;

    public ?int $externalId = null;

    public string $notes = '';

    public ?int $annotateId = null;

    public string $resolution = 'timing_difference';

    public bool $showAnnotate = false;

    public string $comment = '';

    public function mount(ReconciliationBatch $batch): void
    {
        $this->need('fms.view', 'fms.reconciliation.perform', 'fms.reconciliation.approve');
        $this->batch = $batch;
    }

    private function editable(): bool
    {
        return $this->batch->status === ReconciliationStatus::InReview;
    }

    public function manualMatch(): void
    {
        $this->need('fms.reconciliation.perform');
        $this->validate(['internalId' => 'required|integer', 'externalId' => 'required|integer', 'notes' => 'required|string|min:5']);
        if ($this->attempt(fn () => app(ReconciliationService::class)->manualMatch($this->item($this->internalId), $this->item($this->externalId), auth()->user(), $this->notes), 'Items matched.')) {
            $this->reset(['internalId', 'externalId', 'notes']);
        }
    }

    public function toSuspense(int $id): void
    {
        $this->need('fms.reconciliation.perform');
        $this->attempt(fn () => app(ReconciliationService::class)->moveToSuspense($this->item($id), auth()->user(), 'Unidentified receipt moved to suspense from '.$this->batch->reference), 'Moved to suspense.');
    }

    public function askAnnotate(int $id): void
    {
        $this->need('fms.reconciliation.perform');
        $this->annotateId = $id;
        $this->notes = '';
        $this->showAnnotate = true;
    }

    public function annotate(): void
    {
        $this->need('fms.reconciliation.perform');
        if ($this->attempt(fn () => app(ReconciliationService::class)->annotate($this->item($this->annotateId), auth()->user(), $this->resolution, $this->notes), 'Exception annotated.')) {
            $this->showAnnotate = false;
            $this->notes = '';
        }
    }

    public function submit(): void
    {
        $this->need('fms.reconciliation.perform');
        $this->attempt(fn () => app(ReconciliationService::class)->submit($this->batch->fresh(), auth()->user()), 'Batch submitted.');
        $this->batch->refresh();
    }

    public function decide(string $action): void
    {
        $this->need('approvals.act');
        $req = $this->pending();
        abort_unless($req, 404);
        $e = app(ApprovalEngine::class);
        $this->attempt(fn () => $action === 'approve' ? $e->approve($req, auth()->user(), $this->comment ?: null) : $e->reject($req, auth()->user(), $this->comment), 'Decision recorded.');
        $this->batch->refresh();
    }

    private function item(?int $id): ReconciliationItem
    {
        return $this->batch->items()->whereKey($id)->firstOrFail();
    }

    private function pending(): ?ApprovalRequest
    {
        return ApprovalRequest::where('approvable_type', $this->batch->getMorphClass())->where('approvable_id', $this->batch->id)->where('status', 'pending')->latest('id')->first();
    }

    public function render()
    {
        $items = $this->batch->items()->with(['paymentOrder', 'settlementRecord', 'resolver'])->orderBy('result')->get();
        $shown = match ($this->filter) {
            'exceptions' => $items->reject(fn ($i) => in_array($i->result, ['matched', 'manual_match'], true)),
            'matched' => $items->filter(fn ($i) => in_array($i->result, ['matched', 'manual_match'], true)),
            default => $items,
        };
        $pending = $this->pending();

        return view('livewire.ops.finance.reconciliation.show', [
            'items' => $shown,
            'counts' => $items->groupBy('result')->map->count(),
            'openInternal' => $items->where('result', 'missing_external')->whereNull('resolution'),
            'openExternal' => $items->where('result', 'missing_internal')->whereNull('resolution'),
            'editable' => $this->editable(),
            'pending' => $pending,
            'canDecide' => $pending && auth()->user()->hasPermission('approvals.act') && app(ApprovalEngine::class)->canAct($pending, auth()->user()),
        ]);
    }
}
