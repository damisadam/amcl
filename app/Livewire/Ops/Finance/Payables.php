<?php

namespace App\Livewire\Ops\Finance;

use App\Enums\VendorPaymentStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Models\Account;
use App\Models\ApprovalRequest;
use App\Models\BankAccount;
use App\Models\CostCenter;
use App\Models\JournalLine;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use App\Services\Accounting\VendorPaymentService;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Tenancy\TenantSettings;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/** Accounts payable (FMS §3): bills → payment requests → approval → release → GL. */
#[Layout('layouts.ops')]
class Payables extends Component
{
    use HandlesActions, WithPagination;

    public string $tab = 'bills';

    public array $bill = [];

    public array $pay = [];

    public string $scenario = '';

    public string $ledgerVendor = '';

    public string $filterStatus = '';

    public string $comment = '';

    public function mount(): void
    {
        $this->need('fms.view', 'fms.payables.manage', 'fms.payables.execute');
        $this->resetForms();
    }

    private function resetForms(): void
    {
        $this->bill = ['vendor_id' => '', 'kind' => 'operational', 'vendor_invoice_ref' => '', 'bill_date' => today()->toDateString(), 'due_date' => today()->addDays(30)->toDateString(),
            'amount' => '', 'wht_rate' => '', 'expense_account_id' => '', 'cost_center_id' => '', 'description' => ''];
        $this->pay = ['vendor_id' => '', 'vendor_bill_id' => '', 'is_advance' => false, 'amount' => '', 'method' => 'raast', 'bank_account_id' => '', 'purpose' => ''];
    }

    #[On('realtime')]
    public function refreshData(): void {}

    public function createBill(): void
    {
        $this->need('fms.payables.manage');
        $this->validate(['bill.vendor_id' => 'required|integer', 'bill.amount' => 'required|numeric|min:1', 'bill.bill_date' => 'required|date', 'bill.due_date' => 'required|date',
            'bill.expense_account_id' => 'nullable|integer', 'bill.description' => 'required|string|min:3']);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $this->bill);
        if ($data['wht_rate'] === null) {
            unset($data['wht_rate']);
        }
        if ($this->attempt(fn () => app(VendorPaymentService::class)->createBill(Vendor::findOrFail($data['vendor_id']), $data, auth()->user()), 'Bill recorded and posted to payables.')) {
            $this->resetForms();
        }
    }

    public function requestPayment(): void
    {
        $this->need('fms.payables.manage');
        $this->validate(['pay.vendor_id' => 'required|integer', 'pay.amount' => 'nullable|numeric|min:1', 'pay.method' => 'required|in:raast,bank_transfer,cheque']);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $this->pay) + ['idempotency_key' => $this->idem];
        $data = array_filter($data, fn ($v) => $v !== null);
        if ($this->attempt(fn () => app(VendorPaymentService::class)->request(Vendor::findOrFail($this->pay['vendor_id']), $data, auth()->user()), 'Payment request submitted for approval.')) {
            $this->resetForms();
            $this->tab = 'requests';
        }
    }

    public function release(int $id): void
    {
        $this->need('fms.payables.execute');
        if (! $this->stepUp()) {
            return;
        }
        $this->attempt(fn () => app(VendorPaymentService::class)->execute(VendorPayment::findOrFail($id), auth()->user(), $this->scenario ?: null), 'Payment sent to provider (simulated).');
    }

    public function decide(int $paymentId, string $action): void
    {
        $this->need('approvals.act');
        $p = VendorPayment::findOrFail($paymentId);
        $req = ApprovalRequest::where('approvable_type', $p->getMorphClass())->where('approvable_id', $p->id)->where('status', 'pending')->latest('id')->firstOrFail();
        $e = app(ApprovalEngine::class);
        $this->attempt(fn () => match ($action) {
            'approve' => $e->approve($req, auth()->user(), $this->comment ?: null),
            'reject' => $e->reject($req, auth()->user(), $this->comment ?: 'Rejected'),
        }, 'Decision recorded.');
        $this->comment = '';
    }

    public function render(VendorPaymentService $svc)
    {
        $vendors = Vendor::where('status', 'active')->orderBy('business_name')->pluck('business_name', 'id')->all();
        $whtDefault = $this->bill['vendor_id'] ? (Vendor::find($this->bill['vendor_id'])?->kind === 'asset' ? 'wht_rate_vendor_goods' : 'wht_rate_services') : 'wht_rate_services';
        $rate = $this->bill['wht_rate'] !== '' ? $this->bill['wht_rate'] : (string) TenantSettings::get('finance', $whtDefault);
        $whtPreview = is_numeric($this->bill['amount']) ? Money::pct($this->bill['amount'], $rate) : '0.00';
        $user = auth()->user();
        $engine = app(ApprovalEngine::class);

        $payments = VendorPayment::with(['vendor', 'bill', 'creator', 'paymentOrder'])
            ->when($this->tab === 'requests', fn ($q) => $q->whereIn('status', ['draft', 'pending_approval']))
            ->when($this->tab === 'release', fn ($q) => $q->whereIn('status', ['approved', 'failed', 'processing']))
            ->when($this->tab === 'history', fn ($q) => $q->when($this->filterStatus, fn ($w, $s) => $w->where('status', $s)))
            ->latest('id')->paginate(15);
        $canAct = [];
        foreach ($payments as $p) {
            if ($p->status === VendorPaymentStatus::PendingApproval) {
                $req = ApprovalRequest::where('approvable_type', $p->getMorphClass())->where('approvable_id', $p->id)->where('status', 'pending')->first();
                $canAct[$p->id] = $req && $user->hasPermission('approvals.act') && $engine->canAct($req, $user) ? $req : false;
            }
        }

        $ledger = null;
        if ($this->tab === 'ledger' && $this->ledgerVendor) {
            $payableId = Account::where('system_role', 'vendor_payable')->value('id');
            $ledger = JournalLine::with('voucher')->where('vendor_id', $this->ledgerVendor)->where('account_id', $payableId)
                ->whereHas('voucher', fn ($q) => $q->whereIn('status', ['posted', 'reversed']))->orderBy('id')->get();
        }

        return view('livewire.ops.finance.payables', [
            'vendors' => $vendors,
            'openBills' => $this->pay['vendor_id'] ? VendorBill::where('vendor_id', $this->pay['vendor_id'])->whereIn('status', ['open', 'partially_paid'])->get()
                ->mapWithKeys(fn ($b) => [$b->id => $b->bill_no.' · open '.Money::format(Money::sub(Money::sub($b->amount, $b->wht_amount), $b->paid_amount))])->all() : [],
            'expenseAccounts' => Account::where('type', 'expense')->where('is_postable', true)->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all(),
            'costCenters' => CostCenter::where('is_active', true)->pluck('name', 'id')->all(),
            'bankAccounts' => BankAccount::where('purpose', 'operating')->pluck('name', 'id')->all(),
            'bills' => $this->tab === 'bills' ? VendorBill::with('vendor')->latest('id')->paginate(15, pageName: 'bills') : collect(),
            'payments' => $payments,
            'canAct' => $canAct,
            'aging' => $this->tab === 'aging' ? $svc->aging() : [],
            'ledger' => $ledger,
            'whtRate' => $rate,
            'whtPreview' => $whtPreview,
            'statuses' => VendorPaymentStatus::options(),
        ]);
    }
}
