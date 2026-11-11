<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLine extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'basic' => 'decimal:2',
            'allowances' => 'decimal:2',
            'gross' => 'decimal:2',
            'income_tax' => 'decimal:2',
            'provident_fund' => 'decimal:2',
            'eobi' => 'decimal:2',
            'staff_loan_deduction' => 'decimal:2',
            'net' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
