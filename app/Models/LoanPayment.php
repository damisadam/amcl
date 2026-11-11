<?php

namespace App\Models;

use App\Enums\LoanPaymentStatus;
use App\Enums\PaymentChannel;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanPayment extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => LoanPaymentStatus::class,
            'channel' => PaymentChannel::class,
            'amount' => 'decimal:2', 'unallocated_amount' => 'decimal:2',
            'allocation' => 'array', 'received_at' => 'datetime',
        ];
    }

    public function loanAccount(): BelongsTo { return $this->belongsTo(LoanAccount::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function paymentOrder(): BelongsTo { return $this->belongsTo(PaymentOrder::class); }
    public function walletTransaction(): BelongsTo { return $this->belongsTo(WalletTransaction::class); }
    public function allocations(): HasMany { return $this->hasMany(PaymentAllocation::class); }
    public function reversalOf(): BelongsTo { return $this->belongsTo(LoanPayment::class, 'reversal_of_id'); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
