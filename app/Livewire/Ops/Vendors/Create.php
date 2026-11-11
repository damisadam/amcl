<?php

namespace App\Livewire\Ops\Vendors;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Vendor;
use App\Services\Lending\VendorAdminService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.ops')]
class Create extends Component
{
    use HandlesActions, VendorForm;

    public function mount(): void
    {
        $this->need('vendors.manage');
        $this->form = $this->blankForm();
    }

    public function save(VendorAdminService $svc)
    {
        $this->need('vendors.manage');
        $this->validate($this->formRules(), $this->formMessages());
        $v = $this->attempt(fn () => $svc->create($this->formPayload(), auth()->user()), __('Vendor created – pending approval.'));
        if ($v instanceof Vendor) {
            return $this->redirect(route('ops.vendors.show', $v), navigate: true);
        }
    }

    public function render()
    {
        return view('livewire.ops.vendors.create');
    }
}
