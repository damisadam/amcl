<?php

namespace App\Models;

use App\Enums\VendorPaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class VendorPayment extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => VendorPaymentStatus::class,
            'amount' => 'decimal:2', 'wht_amount' => 'decimal:2', 'net_amount' => 'decimal:2',
            'is_advance' => 'boolean', 'paid_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function bill(): BelongsTo { return $this->belongsTo(VendorBill::class, 'vendor_bill_id'); }
    public function disbursement(): BelongsTo { return $this->belongsTo(Disbursement::class); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
    public function paymentOrder(): BelongsTo { return $this->belongsTo(PaymentOrder::class); }
    public function journalVoucher(): BelongsTo { return $this->belongsTo(JournalVoucher::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function approvalRequest(): MorphOne { return $this->morphOne(ApprovalRequest::class, 'approvable')->latestOfMany(); }
}
