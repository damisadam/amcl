<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class PayrollRun extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'status'];

    protected function casts(): array
    {
        return ['total_gross' => 'decimal:2', 'total_tax' => 'decimal:2', 'total_deductions' => 'decimal:2', 'total_net' => 'decimal:2'];
    }

    public function lines(): HasMany { return $this->hasMany(PayrollLine::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function journalVoucher(): BelongsTo { return $this->belongsTo(JournalVoucher::class); }
    public function approvalRequest(): MorphOne { return $this->morphOne(ApprovalRequest::class, 'approvable')->latestOfMany(); }
}
