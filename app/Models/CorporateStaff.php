<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorporateStaff extends Model
{
    use BelongsToTenant;

    protected $table = 'corporate_staff';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'joined_on' => 'date',
            'monthly_salary' => 'decimal:2',
        ];
    }

    public function corporateClient(): BelongsTo
    {
        return $this->belongsTo(CorporateClient::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(StaffSalary::class);
    }
}
