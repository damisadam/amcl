<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportAccessGrant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function platformUser(): BelongsTo { return $this->belongsTo(User::class, 'platform_user_id'); }
    public function grantor(): BelongsTo { return $this->belongsTo(User::class, 'granted_by'); }
}
