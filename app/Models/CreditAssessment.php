<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditAssessment extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'monthly_income' => 'decimal:2',
            'inputs_snapshot' => 'array',
            'other_income' => 'decimal:2',
            'household_expenses' => 'decimal:2',
            'existing_obligations' => 'decimal:2',
            'proposed_instalment' => 'decimal:2',
            'dbr' => 'decimal:2',
            'dbr_limit' => 'decimal:2',
            'dbr_pass' => 'boolean',
            'business_cashflow' => 'array',
            'score_breakdown' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
