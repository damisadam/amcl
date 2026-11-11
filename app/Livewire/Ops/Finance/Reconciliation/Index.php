<?php

namespace App\Livewire\Ops\Finance\Reconciliation;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\ReconciliationBatch;
use App\Models\SuspenseItem;
use App\Services\Accounting\FinanceOperations;
use App\Services\Accounting\ReconciliationService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithPagination;

    public string $tab = 'batches';

    public string $bank_account_id = '';

    public string $from = '';

    public string $to = '';

    public ?int $reclassId = null;

    public string $reclassAccount = '';

    public string $reclassNotes = '';

    public bool $showReclass = false;

    public function mount(): void
    {
        $this->need('fms.view', 'fms.reconciliation.perform', 'fms.reconciliation.approve');
        $this->from = now()->subDays(30)->toDateString();
        $this->to = today()->toDateString();
    }

    public function run()
    {
        $this->need('fms.reconciliation.perform');
        $this->validate(['bank_account_id' => 'required|integer', 'from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $batch = $this->attempt(fn () => app(ReconciliationService::class)->run(BankAccount::findOrFail($this->bank_account_id), $this->from, $this->to, auth()->user()), 'Reconciliation run completed.');
        if ($batch instanceof ReconciliationBatch) {
            return $this->redirect(route('ops.finance.reconciliation.show', $batch), navigate: true);
        }
    }

    public function askReclass(int $id): void
    {
        $this->need('fms.reconciliation.perform');
        $this->reclassId = $id;
        $this->reclassAccount = '';
        $this->reclassNotes = '';
        $this->showReclass = true;
    }

    public function reclassify(): void
    {
        $this->need('fms.reconciliation.perform');
        $this->validate(['reclassAccount' => 'required|integer', 'reclassNotes' => 'required|string|min:5']);
        if ($this->attempt(fn () => app(FinanceOperations::class)->reclassifySuspense(SuspenseItem::findOrFail($this->reclassId), (int) $this->reclassAccount, $this->reclassNotes, auth()->user()), 'Suspense item reclassified.')) {
            $this->showReclass = false;
        }
    }

    public function render()
    {
        return view('livewire.ops.finance.reconciliation.index', [
            'banks' => BankAccount::where('is_active', true)->get()->mapWithKeys(fn ($b) => [$b->id => $b->name.' – '.strtoupper($b->provider ?? '')])->all(),
            'batches' => ReconciliationBatch::with(['bankAccount', 'creator', 'approver'])->latest('id')->paginate(15),
            'suspense' => SuspenseItem::with('settlementRecord')->latest('id')->get(),
            'accounts' => Account::where('is_postable', true)->where('is_active', true)->where('system_role', '!=', 'suspense')->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all(),
        ]);
    }
}
