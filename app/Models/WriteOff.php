<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class WriteOff extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'status'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function loanAccount(): BelongsTo { return $this->belongsTo(LoanAccount::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function journalVoucher(): BelongsTo { return $this->belongsTo(JournalVoucher::class); }
    public function approvalRequest(): MorphOne { return $this->morphOne(ApprovalRequest::class, 'approvable')->latestOfMany(); }
}
