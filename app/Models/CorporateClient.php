<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorporateClient extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'authorized_signatories' => 'array',
        ];
    }

    public function staff(): HasMany
    {
        return $this->hasMany(CorporateStaff::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
