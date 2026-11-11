<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Private, versioned document. Files are only served via the authorized download route. */
class Document extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['path', 'disk'];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'verified_at' => 'datetime', 'is_synthetic' => 'boolean'];
    }

    public function documentable(): MorphTo { return $this->morphTo(); }
    public function type(): BelongsTo { return $this->belongsTo(DocumentType::class, 'document_type_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function verifier(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }
    public function previous(): BelongsTo { return $this->belongsTo(Document::class, 'previous_id'); }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isPast();
    }

    public function expiresSoon(int $days = 30): bool
    {
        return $this->expires_on !== null && ! $this->isExpired() && $this->expires_on->lte(now()->addDays($days));
    }

    public function scopeCurrent($q)
    {
        return $q->where('status', '!=', 'superseded');
    }
}
