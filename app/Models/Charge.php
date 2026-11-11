<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Charge extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:3',
            'type' => \App\Enums\ChargeType::class,
        ];
    }

    public function productVersion(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class);
    }
}
