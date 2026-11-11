<?php

namespace App\Models;

use App\Enums\ComplaintCategory;
use App\Enums\ComplaintStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Complaint extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => ComplaintStatus::class,
            'category' => ComplaintCategory::class,
            'acknowledged_at' => 'datetime', 'sla_due_at' => 'datetime', 'resolved_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function related(): MorphTo { return $this->morphTo(); }
    public function messages(): HasMany { return $this->hasMany(ComplaintMessage::class)->orderBy('id'); }

    public function isOverdue(): bool
    {
        return $this->sla_due_at && $this->sla_due_at->isPast() && ! in_array($this->status, [ComplaintStatus::Resolved, ComplaintStatus::Closed], true);
    }
}
