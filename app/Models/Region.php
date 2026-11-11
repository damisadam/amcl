<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }
}
