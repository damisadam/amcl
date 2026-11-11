<?php

namespace App\Services\Lending;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Services\Audit\Auditor;
use App\Services\Notifications\Notifier;
use App\Support\Money;

/**
 * Vendor item prices drive financed amounts, so a vendor cannot change them directly (M20): a new
 * price is held as `pending_price` until staff with vendors.manage approve it. New items start
 * inactive until their price is approved. Applications keep the asset_price captured at application
 * time; approved changes never alter existing applications.
 */
class VendorPriceChangeService
{
    public function __construct(private Notifier $notifier) {}

    public function proposeNewItem(Vendor $vendor, array $data, User $by): VendorItem
    {
        $item = $vendor->items()->create(array_merge($data, ['is_active' => false]));
        $item->forceFill(['pending_price' => $item->price, 'pending_price_requested_by' => $by->id, 'pending_price_requested_at' => now()])->save();
        Auditor::record('vendor.item_created', $item, [], ['name' => $item->name, 'price' => $item->price, 'awaiting_price_approval' => true]);
        $this->notifyStaff($vendor, $item, 'New item '.$item->name.' at '.Money::format($item->price).' needs price approval.');

        return $item;
    }

    public function proposePrice(VendorItem $item, string $price, User $by): void
    {
        $price = Money::of($price);
        if (Money::eq($price, $item->price) && $item->pending_price === null) {
            return;
        }
        $item->forceFill(['pending_price' => $price, 'pending_price_requested_by' => $by->id, 'pending_price_requested_at' => now()])->save();
        Auditor::record('vendor.item_price_change_requested', $item, ['price' => $item->price], ['pending_price' => $price]);
        $this->notifyStaff($item->vendor, $item, $item->name.': price change '.Money::format($item->price).' → '.Money::format($price).' needs approval.');
    }

    public function approve(VendorItem $item, User $staff): void
    {
        $this->authorize($staff);
        if ($item->pending_price === null) {
            throw new DomainException('There is no pending price for this item.');
        }
        $old = $item->price;
        $isNew = ! $item->is_active && Money::eq($item->pending_price, $item->price);
        $item->forceFill(['price' => $item->pending_price, 'pending_price' => null, 'pending_price_requested_by' => null, 'pending_price_requested_at' => null,
            'is_active' => $isNew ? true : $item->is_active])->save();
        Auditor::record('vendor.item_price_approved', $item, ['price' => $old], ['price' => $item->price]);
    }

    public function reject(VendorItem $item, User $staff, string $reason): void
    {
        $this->authorize($staff);
        if ($item->pending_price === null) {
            throw new DomainException('There is no pending price for this item.');
        }
        $pending = $item->pending_price;
        $item->forceFill(['pending_price' => null, 'pending_price_requested_by' => null, 'pending_price_requested_at' => null])->save();
        Auditor::record('vendor.item_price_rejected', $item, [], ['rejected_price' => $pending], $reason);
    }

    private function authorize(User $staff): void
    {
        if (! $staff->isStaff() || ! $staff->hasPermission('vendors.manage')) {
            throw new DomainException('Only staff who manage vendors can approve item prices.');
        }
    }

    private function notifyStaff(Vendor $vendor, VendorItem $item, string $body): void
    {
        $staff = User::where('type', 'staff')->where('status', 'active')->get()->filter(fn ($u) => $u->hasPermission('vendors.manage'));
        foreach ($staff as $u) {
            $this->notifier->toUser($u, 'vendor_price_change', ['title' => 'Vendor price approval: '.$vendor->business_name, 'body' => $body, 'url' => '/ops/vendors/'.$vendor->id]);
        }
    }
}
