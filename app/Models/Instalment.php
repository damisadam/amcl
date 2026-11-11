<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Instalment extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'principal_due' => 'decimal:2',
            'profit_due' => 'decimal:2',
            'charges_due' => 'decimal:2',
            'late_charge_due' => 'decimal:2',
            'total_due' => 'decimal:2',
            'principal_paid' => 'decimal:2',
            'profit_paid' => 'decimal:2',
            'charges_paid' => 'decimal:2',
            'late_charge_paid' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'status' => \App\Enums\InstalmentStatus::class,
        ];
    }

    public function loanAccount(): BelongsTo
    {
        return $this->belongsTo(LoanAccount::class);
    }
}
