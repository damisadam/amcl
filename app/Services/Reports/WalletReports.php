<?php

namespace App\Services\Reports;

use App\Models\PaymentOrder;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Accounting\FinancialReports;
use App\Support\Money;

/** Wallet MIS. Settlement figures come from SIMULATED provider/bank records. */
class WalletReports
{
    public function __construct(private array $f = []) {}

    private function from(): string { return $this->f['from'] ?? now()->subDays(30)->toDateString(); }
    private function to(): string { return $this->f['to'] ?? today()->toDateString(); }

    public function balances(): array
    {
        return Wallet::selectRaw('status, owner_type, COUNT(*) n, SUM(balance) bal, SUM(held_balance) held')->groupBy('status', 'owner_type')->get()
            ->map(fn ($r) => ['status' => self::v($r->status), 'owner' => class_basename($r->owner_type), 'count' => (int) $r->n, 'balance' => Money::of($r->bal), 'held' => Money::of($r->held)])->all();
    }

    public function volumes(): array
    {
        return WalletTransaction::whereBetween('created_at', [$this->from(), $this->to().' 23:59:59'])
            ->selectRaw('type, status, COUNT(*) n, SUM(amount) amt')->groupBy('type', 'status')->orderBy('type')->get()
            ->map(fn ($r) => ['type' => self::v($r->type), 'status' => self::v($r->status), 'count' => (int) $r->n, 'amount' => Money::of($r->amt)])->all();
    }

    public function daily(): array
    {
        return WalletTransaction::whereBetween('created_at', [$this->from(), $this->to().' 23:59:59'])->where('status', 'succeeded')
            ->selectRaw("DATE(created_at) d, SUM(CASE WHEN direction='credit' THEN amount ELSE 0 END) cr, SUM(CASE WHEN direction='debit' THEN amount ELSE 0 END) dr, COUNT(*) n")
            ->groupBy('d')->orderBy('d')->get()->map(fn ($r) => ['date' => $r->d, 'credits' => Money::of($r->cr), 'debits' => Money::of($r->dr), 'count' => (int) $r->n])->all();
    }

    public function reconciliation(): array
    {
        return PaymentOrder::whereIn('purpose', ['wallet_topup', 'wallet_withdrawal', 'bill_payment', 'merchant_payment'])
            ->whereBetween('created_at', [$this->from(), $this->to().' 23:59:59'])
            ->selectRaw('purpose, status, reconciliation_status, COUNT(*) n, SUM(amount) amt')->groupBy('purpose', 'status', 'reconciliation_status')->get()
            ->map(fn ($r) => ['purpose' => self::v($r->purpose), 'status' => self::v($r->status), 'reconciliation' => $r->reconciliation_status, 'count' => (int) $r->n, 'amount' => Money::of($r->amt)])->all();
    }

    /** Three-way check: safeguarded bank (GL) = wallet liability (GL) = Σ wallet balances (sub-ledger). */
    public function threeWay(): array
    {
        $fr = app(FinancialReports::class);
        $bank = $fr->roleBalance('safeguarded_bank');
        $liability = $fr->roleBalance('wallet_liability');
        $wallets = Money::of(Wallet::sum('balance'));

        return ['safeguarded_bank' => $bank, 'wallet_liability' => $liability, 'wallet_balances' => $wallets,
            'liability_vs_wallets' => Money::sub($liability, $wallets), 'bank_vs_liability' => Money::sub($bank, $liability),
            'ok' => Money::eq($liability, $wallets) && Money::eq($bank, $liability)];
    }

    private static function v(mixed $x): mixed
    {
        return $x instanceof \BackedEnum ? $x->value : $x;
    }
}
