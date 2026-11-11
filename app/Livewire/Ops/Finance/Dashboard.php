<?php

namespace App\Livewire\Ops\Finance;

use App\Livewire\Concerns\HandlesActions;
use App\Models\AccountingPeriod;
use App\Models\Fund;
use App\Models\JournalVoucher;
use App\Models\PaymentOrder;
use App\Models\ReconciliationBatch;
use App\Models\SuspenseItem;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use App\Services\Accounting\FinanceOperations;
use App\Services\Accounting\FinancialReports;
use App\Services\Lending\DisbursementService;
use App\Services\Reports\WalletReports;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.ops')]
class Dashboard extends Component
{
    use HandlesActions;

    public function mount(): void
    {
        $this->need('fms.view');
    }

    #[On('realtime')]
    public function refreshData(): void {}

    public function render(FinancialReports $reports, FinanceOperations $ops)
    {
        $from = now()->startOfMonth()->toDateString();
        $to = today()->toDateString();
        $pl = $reports->profitAndLoss($from, $to);
        $plLast = $reports->profitAndLoss(now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString());
        $funds = Fund::orderBy('name')->get()->map(fn ($f) => ['fund' => $f, 'available' => app(DisbursementService::class)->fundAvailable($f)]);

        return view('livewire.ops.finance.dashboard', [
            'banks' => $ops->bankBalances(),
            'three' => (new WalletReports)->threeWay(),
            'receivable' => $reports->netFinancingReceivable(),
            'receivableGross' => $reports->roleBalance('financing_receivable'),
            'otherReceivable' => $reports->roleBalance('other_receivables'),
            'payable' => $reports->roleBalance('vendor_payable'),
            'apOpen' => Money::of(VendorBill::whereIn('status', ['open', 'partially_paid'])->selectRaw('COALESCE(SUM(amount - wht_amount - paid_amount),0) v')->value('v')),
            'paymentsAwaiting' => VendorPayment::whereIn('status', ['pending_approval', 'approved'])->count(),
            'jv' => JournalVoucher::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status')->all(),
            'reconExceptions' => (int) ReconciliationBatch::whereIn('status', ['in_review', 'pending_approval'])->sum('exception_count'),
            'ordersUnreconciled' => PaymentOrder::where('status', 'succeeded')->where('reconciliation_status', '!=', 'matched')->count(),
            'suspense' => Money::of(SuspenseItem::where('status', 'open')->sum('amount')),
            'period' => AccountingPeriod::whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $to)->first(),
            'openPast' => AccountingPeriod::whereDate('end_date', '<', now()->startOfMonth())->where('status', 'open')->count(),
            'funds' => $funds,
            'charity' => $ops->charityBalance(),
            'pl' => $pl,
            'plLast' => $plLast,
        ]);
    }
}
