<?php

namespace App\Livewire\Vendor\Orders;

use App\Enums\ApplicationStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Vendor\Concerns\ResolvesVendor;
use App\Livewire\Vendor\Concerns\VendorOrderStatus;
use App\Models\LoanApplication;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.vendor')]
class Index extends Component
{
    use HandlesActions, ResolvesVendor, WithDataTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    public function render()
    {
        $vendor = $this->vendor();
        $q = LoanApplication::query()->where('vendor_id', $vendor->id)->whereIn('status', VendorOrderStatus::visible())->with(['customer', 'product', 'disbursements'])
            ->when(isset(VendorOrderStatus::STAGES[$this->filterStatus]), fn ($q) => $q->whereIn('status', VendorOrderStatus::STAGES[$this->filterStatus]))
            ->when(trim($this->search) !== '', function ($q) {
                $t = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('application_no', 'like', $t)->orWhere('asset_description', 'like', $t)->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', $t)));
            });
        $this->applySort($q, 'id', ['id', 'requested_amount', 'created_at']);

        return view('livewire.vendor.orders.index', ['orders' => $q->paginate($this->perPage), 'statuses' => array_map('__', VendorOrderStatus::LABELS)]);
    }
}
