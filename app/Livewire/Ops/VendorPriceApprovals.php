<?php

namespace App\Livewire\Ops;

use App\Livewire\Concerns\HandlesActions;
use App\Models\VendorItem;
use App\Services\Lending\VendorPriceChangeService;
use Livewire\Component;

/**
 * Embeddable panel for vendor item price approvals (M20): `<livewire:ops.vendor-price-approvals :vendor-id="$vendor->id"/>`
 * on the ops vendor page, or without vendor-id for all pending price changes.
 */
class VendorPriceApprovals extends Component
{
    use HandlesActions;

    public ?int $vendorId = null;

    public string $reason = '';

    public function mount(?int $vendorId = null): void
    {
        $this->need('vendors.view');
        $this->vendorId = $vendorId;
    }

    public function approve(int $id, VendorPriceChangeService $svc): void
    {
        $this->need('vendors.manage');
        $this->attempt(fn () => $svc->approve($this->item($id), auth()->user()), 'Price approved.');
    }

    public function reject(int $id, VendorPriceChangeService $svc): void
    {
        $this->need('vendors.manage');
        $this->attempt(fn () => $svc->reject($this->item($id), auth()->user(), $this->reason ?: 'Price not agreed'), 'Price change rejected.');
    }

    private function item(int $id): VendorItem
    {
        return VendorItem::whereNotNull('pending_price')->when($this->vendorId, fn ($q) => $q->where('vendor_id', $this->vendorId))->findOrFail($id);
    }

    public function render()
    {
        return view('livewire.ops.vendor-price-approvals', [
            'items' => VendorItem::with('vendor')->whereNotNull('pending_price')->when($this->vendorId, fn ($q) => $q->where('vendor_id', $this->vendorId))
                ->orderBy('pending_price_requested_at')->get(),
        ]);
    }
}
