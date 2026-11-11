<?php

namespace App\Livewire\Vendor;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Vendor\Concerns\ResolvesVendor;
use App\Models\WalletTransaction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/** Merchant wallet: in-ecosystem QR payments received from customers (ledger-only, no bank movement). */
#[Layout('layouts.vendor')]
class Merchant extends Component
{
    use HandlesActions, ResolvesVendor, WithPagination;

    #[On('realtime')]
    public function refreshMerchant(): void {}

    /** Deterministic SVG grid derived from the merchant code – a visual placeholder, NOT a scannable payment QR. */
    public static function pseudoQr(string $code, int $n = 21): array
    {
        $cells = [];
        $hash = hash('sha256', $code);
        for ($i = 0; $i < $n * $n; $i++) {
            $cells[] = (hexdec($hash[$i % 64]) + intdiv($i, 64)) % 2 === 0;
        }
        foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$r0, $c0]) { // finder squares
            for ($r = 0; $r < 7; $r++) {
                for ($c = 0; $c < 7; $c++) {
                    $edge = $r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                    $cells[($r0 + $r) * $n + $c0 + $c] = $edge;
                }
            }
        }

        return $cells;
    }

    public function render()
    {
        $vendor = $this->vendor();
        $wallet = $vendor->is_merchant ? $vendor->wallet()->first() : null;

        return view('livewire.vendor.merchant', [
            'vendor' => $vendor,
            'wallet' => $wallet,
            'cells' => $vendor->merchant_code ? self::pseudoQr($vendor->merchant_code) : [],
            'receipts' => $wallet ? WalletTransaction::where('wallet_id', $wallet->id)->latest('id')->paginate(15) : null,
            'today' => $wallet ? WalletTransaction::where('wallet_id', $wallet->id)->where('type', 'merchant_receipt')->where('status', 'succeeded')->where('created_at', '>=', today())->sum('amount') : 0,
        ]);
    }
}
