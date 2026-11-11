<?php

namespace App\Models;

use App\Enums\WalletStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * `balance` is a cache maintained exclusively by WalletLedger inside a locked DB transaction.
 * It is deliberately NOT mass assignable.
 */
class Wallet extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'balance', 'held_balance', 'status', 'pin_hash'];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'status' => WalletStatus::class,
            'balance' => 'decimal:2', 'held_balance' => 'decimal:2',
            'daily_limit' => 'decimal:2', 'per_transaction_limit' => 'decimal:2',
            'monthly_limit' => 'decimal:2', 'max_balance' => 'decimal:2',
            'biometric_enabled' => 'boolean',
            'activated_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public function owner(): MorphTo { return $this->morphTo(); }
    public function transactions(): HasMany { return $this->hasMany(WalletTransaction::class)->latest('id'); }
    public function ledgerEntries(): HasMany { return $this->hasMany(WalletLedgerEntry::class)->orderBy('id'); }

    public function availableBalance(): string
    {
        return Money::sub($this->balance, $this->held_balance);
    }

    public function isActive(): bool
    {
        return $this->status === WalletStatus::Active;
    }
}
