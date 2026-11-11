<?php

namespace App\Models;

use App\Enums\DisbursementStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Disbursement extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => DisbursementStatus::class,
            'gross_amount' => 'decimal:2', 'deductions' => 'decimal:2', 'net_amount' => 'decimal:2',
            'completed_at' => 'datetime', 'released_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo { return $this->belongsTo(LoanApplication::class, 'loan_application_id'); }
    public function loanAccount(): BelongsTo { return $this->belongsTo(LoanAccount::class); }
    public function paymentOrder(): BelongsTo { return $this->belongsTo(PaymentOrder::class); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
    public function initiator(): BelongsTo { return $this->belongsTo(User::class, 'initiated_by'); }
    public function releaser(): BelongsTo { return $this->belongsTo(User::class, 'released_by'); }

    /** Requested (maker) but not yet released to the provider (checker). */
    public function awaitingRelease(): bool
    {
        return $this->status === DisbursementStatus::Initiated && $this->released_at === null && $this->payment_order_id === null;
    }
}
