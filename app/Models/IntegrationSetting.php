<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant provider configuration. `credentials` is encrypted at rest and hidden from serialization;
 * UI shows only masked values (see App\Services\Integrations\IntegrationManager::masked()).
 */
class IntegrationSetting extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'options' => 'array',
            'live_approved_at' => 'datetime',
        ];
    }

    public function isLive(): bool
    {
        return $this->mode === 'live' && $this->live_approved_at !== null;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options, $key, $default);
    }
}
