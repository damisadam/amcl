<?php

namespace App\Models;

use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WalletTransaction extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'status'];

    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            'status' => WalletTransactionStatus::class,
            'amount' => 'decimal:2', 'fee' => 'decimal:2',
            'is_external' => 'boolean', 'meta' => 'array', 'completed_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo { return $this->belongsTo(Wallet::class); }
    public function counterpartyWallet(): BelongsTo { return $this->belongsTo(Wallet::class, 'counterparty_wallet_id'); }
    public function related(): BelongsTo { return $this->belongsTo(WalletTransaction::class, 'related_transaction_id'); }
    public function paymentOrder(): BelongsTo { return $this->belongsTo(PaymentOrder::class); }
    public function subject(): MorphTo { return $this->morphTo(); }
    public function ledgerEntries(): HasMany { return $this->hasMany(WalletLedgerEntry::class); }

    public function isCredit(): bool
    {
        return $this->direction === 'credit';
    }
}
