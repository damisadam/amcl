<?php

namespace App\Models;

use App\Enums\ReconciliationStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationBatch extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => ReconciliationStatus::class,
            'period_start' => 'date', 'period_end' => 'date', 'approved_at' => 'datetime',
            'internal_total' => 'decimal:2', 'external_total' => 'decimal:2', 'difference' => 'decimal:2',
        ];
    }

    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
    public function items(): HasMany { return $this->hasMany(ReconciliationItem::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
