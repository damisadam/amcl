<?php

namespace App\Livewire\Vendor;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Vendor\Concerns\ResolvesVendor;
use App\Models\Disbursement;
use App\Models\VendorPayment;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Payments received from the institution (financing disbursements and bill payments). */
#[Layout('layouts.vendor')]
class Payments extends Component
{
    use HandlesActions, ResolvesVendor, WithDataTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    public function render()
    {
        $vendor = $this->vendor();
        $q = VendorPayment::query()->where('vendor_id', $vendor->id)->with(['paymentOrder', 'disbursement.application', 'bill'])
            ->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus));
        $this->applySearch($q, ['payment_no', 'purpose']);
        $this->applySort($q, 'id', ['id', 'net_amount', 'paid_at']);

        return view('livewire.vendor.payments', [
            'payments' => $q->paginate($this->perPage),
            'inFlight' => Disbursement::where('recipient_type', 'vendor')->where('recipient_id', $vendor->id)->whereIn('status', ['initiated', 'processing', 'failed'])
                ->with(['application', 'paymentOrder'])->latest('id')->take(20)->get(),
            'statuses' => \App\Enums\VendorPaymentStatus::options(),
        ]);
    }
}
