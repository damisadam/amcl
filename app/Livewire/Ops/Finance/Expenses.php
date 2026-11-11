<?php

namespace App\Livewire\Ops\Finance;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\DocumentType;
use App\Models\ExpenseClaim;
use App\Models\Vendor;
use App\Services\Accounting\FinanceOperations;
use App\Services\Documents\DocumentStore;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/** Company expenses, reimbursements, purchase requests and petty cash (FMS §9). */
#[Layout('layouts.ops')]
class Expenses extends Component
{
    use HandlesActions, WithFileUploads, WithPagination;

    public array $form = [];

    public $receipt;

    public string $scope = 'mine';

    public string $filterStatus = '';

    public string $filterKind = '';

    public function mount(): void
    {
        $this->need('fms.view', 'fms.expenses.create');
        $this->resetForm();
        if (! auth()->user()->hasPermission('fms.view')) {
            $this->scope = 'mine';
        }
    }

    private function resetForm(): void
    {
        $this->form = ['kind' => 'expense', 'expense_account_id' => '', 'branch_id' => (string) auth()->user()->branch_id, 'cost_center_id' => '', 'vendor_id' => '',
            'expense_date' => today()->toDateString(), 'amount' => '', 'description' => ''];
        $this->receipt = null;
    }

    public function submit(): void
    {
        $this->need('fms.expenses.create');
        $this->validate(['form.kind' => 'required|in:expense,reimbursement,purchase_request,petty_cash', 'form.expense_account_id' => 'required|integer',
            'form.amount' => 'required|numeric|min:1', 'form.expense_date' => 'required|date|before_or_equal:today', 'form.description' => 'required|string|min:5',
            'receipt' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,pdf']);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $this->form);
        $claim = $this->attempt(function () use ($data) {
            $claim = app(FinanceOperations::class)->raiseExpense($data, auth()->user());
            if ($this->receipt) {
                app(DocumentStore::class)->store($this->receipt, $claim, DocumentType::where('code', 'JV_SUPPORT')->first(), auth()->user(), 'Receipt');
            }

            return $claim;
        }, 'Submitted for approval.');
        if ($claim) {
            $this->resetForm();
        }
    }

    public function render()
    {
        $user = auth()->user();
        $q = ExpenseClaim::with(['requester', 'expenseAccount', 'branch', 'documents'])
            ->when($this->scope === 'mine' || ! $user->hasPermission('fms.view'), fn ($q) => $q->where('requested_by', $user->id))
            ->when($this->filterStatus, fn ($q, $s) => $q->where('status', $s))
            ->when($this->filterKind, fn ($q, $k) => $q->where('kind', $k));

        return view('livewire.ops.finance.expenses', [
            'claims' => $q->latest('id')->paginate(15),
            'accounts' => Account::where('type', 'expense')->where('is_postable', true)->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all(),
            'branches' => Branch::pluck('name', 'id')->all(),
            'costCenters' => CostCenter::where('is_active', true)->pluck('name', 'id')->all(),
            'vendors' => Vendor::where('status', 'active')->where('kind', 'operational')->pluck('business_name', 'id')->all(),
            'kinds' => ['expense' => 'Operational expense', 'reimbursement' => 'Employee reimbursement', 'purchase_request' => 'Purchase request', 'petty_cash' => 'Petty cash'],
        ]);
    }
}
