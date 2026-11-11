<?php

namespace App\Livewire\Customer\Applications;

use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\LoanApplication;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.customer')]
class Index extends Component
{
    use CustomerContext, WithPagination;

    #[On('realtime')]
    public function refresh(): void {}

    public function render()
    {
        return view('livewire.customer.applications.index', [
            'applications' => LoanApplication::with(['product', 'loanAccount'])->where('customer_id', $this->customer()->id)->latest('id')->paginate(10),
        ])->title(__('My Applications'));
    }
}
