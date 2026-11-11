<?php

namespace App\Livewire\Ops\Collections;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Branch;
use App\Models\Instalment;
use App\Models\LoanAccount;
use App\Policies\RecordAccess;
use App\Services\Lending\CollectionsService;
use App\Services\Lending\RepaymentService;
use App\Services\Lending\ServicingService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Collections work queue: instalments due today / this week / overdue, with aging buckets. */
#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    #[Url]
    public string $tab = 'overdue';

    public string $filterBucket = '';

    public string $filterBranch = '';

    public bool $showPay = false;

    public ?int $payInstalmentId = null;

    public string $payAmount = '';

    public string $payChannel = 'cash';

    public string $payRef = '';

    public function mount(): void
    {
        $this->need('repayments.view');
    }

    #[On('realtime')]
    public function refreshList(): void {}

    private function loanIds()
    {
        return RecordAccess::loansQuery(LoanAccount::query(), auth()->user())->whereIn('status', ['active', 'overdue'])->select('id');
    }

    private function query()
    {
        $q = Instalment::query()->with(['loanAccount.customer', 'loanAccount.branch', 'loanAccount.recoveryAgent'])
            ->whereIn('loan_account_id', $this->loanIds())->where('status', '!=', 'paid');
        match ($this->tab) {
            'today' => $q->whereDate('due_date', today()),
            'week' => $q->whereBetween('due_date', [today()->toDateString(), today()->addDays(7)->toDateString()]),
            default => $q->whereDate('due_date', '<', today()),
        };
        if ($this->tab === 'overdue' && $this->filterBucket) {
            [$min, $max] = ['1-30' => [1, 30], '31-60' => [31, 60], '61-90' => [61, 90], '90+' => [91, 100000]][$this->filterBucket];
            $q->whereBetween('due_date', [today()->subDays($max)->toDateString(), today()->subDays($min)->toDateString()]);
        }
        if ($this->filterBranch) {
            $q->whereHas('loanAccount', fn ($w) => $w->where('branch_id', $this->filterBranch));
        }
        if (trim($this->search) !== '') {
            $t = '%'.trim($this->search).'%';
            $q->whereHas('loanAccount', fn ($w) => $w->where('account_no', 'like', $t)->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', $t)->orWhere('cif_no', 'like', $t)));
        }

        return $q;
    }

    public function remind(int $id, CollectionsService $svc): void
    {
        $this->need('repayments.view');
        $inst = Instalment::whereIn('loan_account_id', $this->loanIds())->findOrFail($id);
        $this->attempt(fn () => $svc->sendReminder($inst, auth()->user()), __('Reminder sent to customer.'));
    }

    public function openPay(int $id): void
    {
        $this->need('repayments.record');
        $inst = Instalment::whereIn('loan_account_id', $this->loanIds())->findOrFail($id);
        $this->payInstalmentId = $id;
        $this->payAmount = Money::sub(Money::add($inst->total_due, $inst->late_charge_due), $inst->total_paid);
        $this->payRef = '';
        $this->showPay = true;
    }

    public function recordPayment(RepaymentService $svc): void
    {
        $this->need('repayments.record');
        $this->validate(['payAmount' => 'required|numeric|min:1', 'payChannel' => 'required|in:'.implode(',', RepaymentService::MANUAL_CHANNELS), 'payRef' => 'required|string|max:60']);
        $inst = Instalment::whereIn('loan_account_id', $this->loanIds())->findOrFail($this->payInstalmentId);
        if ($this->attempt(fn () => $svc->recordManual($inst->loanAccount, (string) $this->payAmount, $this->payChannel, $this->payRef, auth()->user()), __('Payment recorded.'))) {
            $this->showPay = false;
        }
    }

    public function runDelinquency(ServicingService $svc): void
    {
        $this->need('loans.manage');
        $r = $this->attempt(fn () => $svc->runDelinquency());
        if ($r) {
            \App\Services\Audit\Auditor::record('collections.delinquency_run', null, [], $r);
            $this->toast(__('Delinquency run complete: :l loans checked, :o overdue.', ['l' => $r['loans'], 'o' => $r['overdue']]));
        }
    }

    public function render()
    {
        $aging = Instalment::whereIn('loan_account_id', $this->loanIds())->where('status', '!=', 'paid')->whereDate('due_date', '<', today())
            ->selectRaw("CASE WHEN DATEDIFF(CURDATE(), due_date) <= 30 THEN '1-30' WHEN DATEDIFF(CURDATE(), due_date) <= 60 THEN '31-60'
                WHEN DATEDIFF(CURDATE(), due_date) <= 90 THEN '61-90' ELSE '90+' END bucket, COUNT(*) n, SUM(total_due + late_charge_due - total_paid) amt")
            ->groupBy('bucket')->get()->keyBy('bucket');
        $dueToday = Instalment::whereIn('loan_account_id', $this->loanIds())->where('status', '!=', 'paid')->whereDate('due_date', today())
            ->selectRaw('COUNT(*) n, COALESCE(SUM(total_due + late_charge_due - total_paid),0) amt')->first();
        $collectedToday = Money::of(\App\Models\LoanPayment::whereIn('loan_account_id', $this->loanIds())->where('status', 'succeeded')->whereDate('received_at', today())->sum('amount'));

        return view('livewire.ops.collections.index', [
            'rows' => $this->query()->orderBy('due_date')->paginate($this->perPage),
            'aging' => $aging, 'dueToday' => $dueToday, 'collectedToday' => $collectedToday,
            'branches' => Branch::orderBy('name')->pluck('name', 'id')->all(),
            'sensitive' => auth()->user()->hasPermission('customers.view_sensitive'),
        ]);
    }
}
