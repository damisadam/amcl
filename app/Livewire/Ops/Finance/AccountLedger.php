<?php

namespace App\Livewire\Ops\Finance;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Account;
use App\Services\Accounting\FinancialReports;
use App\Services\Reports\CsvExport;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.ops')]
class AccountLedger extends Component
{
    use HandlesActions;

    public Account $account;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(Account $account): void
    {
        $this->need('fms.view');
        $this->account = $account;
        $this->from = $this->from ?: now()->startOfMonth()->subMonths(2)->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function export()
    {
        $l = app(FinancialReports::class)->ledger($this->account, $this->from, $this->to);
        $rows = collect($l['rows'])->map(fn ($r) => [$r['line']->voucher_date, $r['line']->voucher_no, $r['line']->narration, $r['line']->debit, $r['line']->credit, $r['balance']]);

        return CsvExport::download('ledger-'.$this->account->code, ['Date', 'Voucher', 'Narration', 'Debit', 'Credit', 'Balance'], $rows, ['from' => $this->from, 'to' => $this->to]);
    }

    public function render(FinancialReports $reports)
    {
        return view('livewire.ops.finance.account-ledger', ['ledger' => $reports->ledger($this->account, $this->from, $this->to)]);
    }
}
