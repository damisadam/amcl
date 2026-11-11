<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class FundTransfer extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'status'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function fromAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'from_bank_account_id'); }
    public function toAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'to_bank_account_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function journalVoucher(): BelongsTo { return $this->belongsTo(JournalVoucher::class); }
    public function approvalRequest(): MorphOne { return $this->morphOne(ApprovalRequest::class, 'approvable')->latestOfMany(); }
}
