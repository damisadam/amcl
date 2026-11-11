<?php

namespace App\Models;

use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentPurpose;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** External money movement. Status changes only via App\Services\Payments\PaymentOrchestrator. */
class PaymentOrder extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => PaymentOrderStatus::class,
            'purpose' => PaymentPurpose::class,
            'amount' => 'decimal:2',
            'meta' => 'array',
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function payable(): MorphTo { return $this->morphTo(); }
    public function callbacks(): HasMany { return $this->hasMany(PaymentCallback::class); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
    public function initiator(): BelongsTo { return $this->belongsTo(User::class, 'initiated_by'); }

    public function isFinal(): bool
    {
        return in_array($this->status, [PaymentOrderStatus::Succeeded, PaymentOrderStatus::Failed, PaymentOrderStatus::Reversed, PaymentOrderStatus::Cancelled], true);
    }
}
