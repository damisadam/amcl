<?php

namespace App\Livewire\Customer\Financing;

use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\LoanAccount;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.customer')]
class Index extends Component
{
    use CustomerContext;

    public function render()
    {
        return view('livewire.customer.financing.index', [
            'loans' => LoanAccount::with(['product', 'application'])->where('customer_id', $this->customer()->id)->latest('disbursed_at')->get(),
        ])->title(__('My Financing'));
    }
}
