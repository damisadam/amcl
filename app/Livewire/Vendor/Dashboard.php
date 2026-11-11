<?php

namespace App\Livewire\Vendor;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Vendor\Concerns\ResolvesVendor;
use App\Models\Disbursement;
use App\Models\LoanApplication;
use App\Models\VendorPayment;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.vendor')]
class Dashboard extends Component
{
    use HandlesActions, ResolvesVendor;

    public const PENDING = ['draft', 'submitted', 'under_review', 'pending_approval', 'returned'];

    public const APPROVED = ['approved', 'kfs_acknowledged', 'cooling_off', 'ready_for_disbursement', 'disbursement_in_progress'];

    public function render()
    {
        $vendor = $this->vendor();
        $apps = LoanApplication::where('vendor_id', $vendor->id)->whereIn('status', Concerns\VendorOrderStatus::visible());
        $byStatus = (clone $apps)->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $sum = fn (array $keys) => collect($keys)->sum(fn ($k) => $byStatus[$k] ?? 0);
        $paidMonth = VendorPayment::where('vendor_id', $vendor->id)->where('status', 'paid')->where('paid_at', '>=', now()->startOfMonth())->sum('net_amount');
        $checks = [
            'Business registration' => filled($vendor->registration_no), 'NTN' => filled($vendor->ntn), 'Bank account (IBAN)' => filled($vendor->iban),
            'Contact email' => filled($vendor->email), 'Registration document' => $vendor->documents()->whereHas('type', fn ($q) => $q->where('code', 'VENDOR_REG'))->exists(),
            'Bank letter' => $vendor->documents()->whereHas('type', fn ($q) => $q->where('code', 'VENDOR_BANK'))->exists(), 'Items listed' => $vendor->items()->exists(),
        ];

        return view('livewire.vendor.dashboard', [
            'vendor' => $vendor,
            'pending' => $sum(self::PENDING), 'approved' => $sum(self::APPROVED), 'disbursed' => $byStatus['disbursed'] ?? 0,
            'paidMonth' => Money::of($paidMonth),
            'inFlight' => Disbursement::where('recipient_type', 'vendor')->where('recipient_id', $vendor->id)->whereIn('status', ['initiated', 'processing'])->sum('net_amount'),
            'payments' => VendorPayment::where('vendor_id', $vendor->id)->latest('id')->take(5)->get(),
            'recent' => (clone $apps)->with('customer')->latest('id')->take(5)->get(),
            'checks' => $checks,
            'completeness' => (int) round(100 * count(array_filter($checks)) / count($checks)),
        ]);
    }
}
