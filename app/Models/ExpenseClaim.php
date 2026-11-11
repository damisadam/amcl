<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class ExpenseClaim extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'status'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expense_date' => 'date'];
    }

    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function costCenter(): BelongsTo { return $this->belongsTo(CostCenter::class); }
    public function expenseAccount(): BelongsTo { return $this->belongsTo(Account::class, 'expense_account_id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function journalVoucher(): BelongsTo { return $this->belongsTo(JournalVoucher::class); }
    public function documents(): MorphMany { return $this->morphMany(Document::class, 'documentable'); }
    public function approvalRequest(): MorphOne { return $this->morphOne(ApprovalRequest::class, 'approvable')->latestOfMany(); }
}
