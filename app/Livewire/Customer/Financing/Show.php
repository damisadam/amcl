<?php

namespace App\Livewire\Customer\Financing;

use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\LoanAccount;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/** Live ledger for one financing (DFS §4.7): schedule, outstanding, payments, receipts, NOC. */
#[Layout('layouts.customer')]
class Show extends Component
{
    use CustomerContext;

    public LoanAccount $loan;

    public string $tab = 'schedule';

    public function mount(LoanAccount $loan): void
    {
        $this->loan = $this->own($loan);
    }

    #[On('realtime')]
    public function refresh(): void {}

    public function render()
    {
        $loan = $this->own(LoanAccount::with(['product', 'vendor', 'application'])->findOrFail($this->loan->id));

        return view('livewire.customer.financing.show', [
            'l' => $loan,
            'instalments' => $loan->instalments()->get(),
            'payments' => $loan->payments()->get(),
            'next' => $loan->nextInstalment(),
            'overdue' => $loan->overdueAmount(),
            'noc' => $loan->noc_no ? $loan->documents()->whereHas('type', fn ($q) => $q->where('code', 'NOC'))->latest('id')->first() : null,
        ])->title(__('Financing').' '.$this->loan->account_no);
    }
}
