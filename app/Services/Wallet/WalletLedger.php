<?php

namespace App\Services\Wallet;

use App\Exceptions\DomainException;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY code allowed to change a wallet balance.
 *
 * Every movement writes an immutable ledger entry with before/after balances. A unique key on
 * (transaction, wallet, entry_type) makes each posting happen at most once even under retries.
 * Callers must run inside a DB transaction; the wallet row is locked FOR UPDATE here.
 */
class WalletLedger
{
    public function lock(int $walletId): Wallet
    {
        $this->assertInTransaction();

        return Wallet::whereKey($walletId)->lockForUpdate()->firstOrFail();
    }

    /** Lock several wallets in a stable order (by id) to avoid deadlocks. */
    public function lockMany(array $walletIds): array
    {
        $this->assertInTransaction();
        $ids = array_values(array_unique($walletIds));
        sort($ids);
        $locked = Wallet::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return array_map(fn ($id) => $locked[$id], $walletIds);
    }

    public function credit(Wallet $wallet, WalletTransaction $txn, ?string $amount = null): WalletLedgerEntry
    {
        $amount = Money::of($amount ?? $txn->amount);
        $this->assertPositive($amount);
        $before = Money::of($wallet->balance);
        $after = Money::add($before, $amount);

        return $this->write($wallet, $txn, 'credit', $amount, $before, $after, Money::of($wallet->held_balance));
    }

    /** Debit available (unheld) funds. $allowNegative only for provider-initiated reversals. */
    public function debit(Wallet $wallet, WalletTransaction $txn, ?string $amount = null, bool $allowNegative = false): WalletLedgerEntry
    {
        $amount = Money::of($amount ?? $txn->amount);
        $this->assertPositive($amount);
        if (! $allowNegative && Money::lt($wallet->availableBalance(), $amount)) {
            throw new DomainException('Insufficient wallet balance. Available: '.Money::format($wallet->availableBalance()).'.');
        }
        $before = Money::of($wallet->balance);
        $after = Money::sub($before, $amount);

        return $this->write($wallet, $txn, 'debit', $amount, $before, $after, Money::of($wallet->held_balance));
    }

    /** Reserve funds for a pending outbound transaction. */
    public function hold(Wallet $wallet, WalletTransaction $txn): WalletLedgerEntry
    {
        $amount = Money::add($txn->amount, $txn->fee);
        $this->assertPositive($amount);
        if (Money::lt($wallet->availableBalance(), $amount)) {
            throw new DomainException('Insufficient wallet balance. Available: '.Money::format($wallet->availableBalance()).'.');
        }
        $held = Money::add($wallet->held_balance, $amount);

        return $this->write($wallet, $txn, 'hold', $amount, Money::of($wallet->balance), Money::of($wallet->balance), $held);
    }

    /** Release a hold without moving money (outbound transaction failed). */
    public function release(Wallet $wallet, WalletTransaction $txn): WalletLedgerEntry
    {
        $amount = Money::add($txn->amount, $txn->fee);
        $held = Money::sub($wallet->held_balance, $amount);
        if (Money::lt($held, 0)) {
            throw new \LogicException('Hold release exceeds held balance.');
        }

        return $this->write($wallet, $txn, 'release', $amount, Money::of($wallet->balance), Money::of($wallet->balance), $held);
    }

    /** Convert a hold into a debit (outbound transaction confirmed). */
    public function capture(Wallet $wallet, WalletTransaction $txn): WalletLedgerEntry
    {
        $this->release($wallet, $txn);
        $wallet->refresh();
        $amount = Money::add($txn->amount, $txn->fee);
        $before = Money::of($wallet->balance);

        return $this->write($wallet, $txn, 'debit', $amount, $before, Money::sub($before, $amount), Money::of($wallet->held_balance));
    }

    /** Recompute balance from the ledger (used by integrity checks and tests). */
    public function ledgerBalance(Wallet $wallet): string
    {
        $row = WalletLedgerEntry::where('wallet_id', $wallet->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type='credit' THEN amount WHEN entry_type='debit' THEN -amount ELSE 0 END),0) as bal")
            ->first();

        return Money::of($row->bal);
    }

    public function ledgerHeld(Wallet $wallet): string
    {
        $row = WalletLedgerEntry::where('wallet_id', $wallet->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type='hold' THEN amount WHEN entry_type='release' THEN -amount ELSE 0 END),0) as held")
            ->first();

        return Money::of($row->held);
    }

    private function write(Wallet $wallet, WalletTransaction $txn, string $type, string $amount, string $before, string $after, string $heldAfter): WalletLedgerEntry
    {
        $this->assertInTransaction();
        if ((int) $wallet->tenant_id !== (int) $txn->tenant_id) {
            throw new \App\Exceptions\TenantIsolationViolation('WalletTransaction', $txn->id);
        }
        try {
            $entry = WalletLedgerEntry::create([
                'wallet_id' => $wallet->id,
                'wallet_transaction_id' => $txn->id,
                'entry_type' => $type,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'held_after' => $heldAfter,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'wallet_ledger_unique_posting')) {
                throw new DuplicatePosting("Ledger {$type} for transaction {$txn->reference} was already posted.");
            }
            throw $e;
        }
        $wallet->forceFill(['balance' => $after, 'held_balance' => $heldAfter])->save();

        return $entry;
    }

    private function assertPositive(string $amount): void
    {
        if (! Money::isPositive($amount)) {
            throw new DomainException('Amount must be greater than zero.');
        }
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Wallet ledger operations must run inside a database transaction.');
        }
    }
}
