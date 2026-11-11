<?php

namespace App\Livewire\Vendor\Concerns;

use App\Models\Vendor;

/** Every vendor-portal query is restricted to the signed-in vendor user's own vendor record. */
trait ResolvesVendor
{
    protected function vendor(): Vendor
    {
        $user = auth()->user();
        abort_unless($user?->isVendor() && $user->vendor_id, 403);

        // Tenant-scoped lookup: a vendor id from another tenant can never resolve.
        return Vendor::findOrFail($user->vendor_id);
    }
}
