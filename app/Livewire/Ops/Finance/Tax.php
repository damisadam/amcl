<?php

namespace App\Livewire\Ops\Finance;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Account;
use App\Models\JournalLine;
use App\Models\VendorBill;
use App\Services\Reports\CsvExport;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Withholding tax summary from the general ledger (FMS §12). FBR schedules are SIMULATED formats. */
#[Layout('layouts.ops')]
class Tax extends Component
{
    use HandlesActions;

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->need('fms.tax.view');
        $this->from = now()->startOfYear()->toDateString();
        $this->to = today()->toDateString();
    }

    private function movements()
    {
        $wht = Account::where('system_role', 'wht_payable')->value('id');

        return JournalLine::query()->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_lines.journal_voucher_id')
            ->where('journal_lines.account_id', $wht)->whereIn('journal_vouchers.status', ['posted', 'reversed'])
            ->whereBetween('journal_vouchers.voucher_date', [$this->from, $this->to])
            ->selectRaw("DATE_FORMAT(journal_vouchers.voucher_date, '%Y-%m') m, COALESCE(journal_vouchers.event_type, 'manual') e,
                SUM(journal_lines.credit) withheld, SUM(journal_lines.debit) deposited")
            ->groupBy('m', 'e')->orderBy('m')->get();
    }

    private function vendorSchedule()
    {
        return VendorBill::with('vendor')->where('wht_amount', '>', 0)->whereBetween('bill_date', [$this->from, $this->to])->orderBy('bill_date')->get();
    }

    public function export()
    {
        $rows = $this->vendorSchedule()->map(fn ($b) => [$b->bill_date->toDateString(), $b->vendor->business_name, $b->vendor->ntn, $b->vendor->cnic ? substr($b->vendor->cnic, 0, 5).'*******' : '',
            $b->bill_no, $b->amount, $b->wht_amount, 'SIMULATED FORMAT']);

        return CsvExport::download('wht-vendor-schedule', ['Date', 'Vendor', 'NTN', 'CNIC (masked)', 'Bill', 'Gross', 'WHT', 'Note'], $rows, ['from' => $this->from, 'to' => $this->to]);
    }

    public function render()
    {
        $mov = $this->movements();

        return view('livewire.ops.finance.tax', [
            'movements' => $mov,
            'withheld' => Money::add('0', ...$mov->pluck('withheld')->all()),
            'deposited' => Money::add('0', ...$mov->pluck('deposited')->all()),
            'schedule' => $this->vendorSchedule(),
        ]);
    }
}
