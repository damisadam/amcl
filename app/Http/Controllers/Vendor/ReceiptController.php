<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\VendorPayment;
use App\Support\TenantContext;

/** Printable payment advice for the signed-in vendor's own paid payments. */
class ReceiptController extends Controller
{
    public function __invoke(VendorPayment $payment)
    {
        $user = auth()->user();
        abort_unless($user->isVendor() && (int) $payment->vendor_id === (int) $user->vendor_id && $payment->status->value === 'paid', 403);

        return view('livewire.vendor.receipt', ['payment' => $payment->load('paymentOrder'), 'vendor' => $payment->vendor, 'tenant' => TenantContext::get()]);
    }
}
