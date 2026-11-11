<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRequest extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'type' => ApprovalType::class,
            'status' => ApprovalStatus::class,
            'amount' => 'decimal:2',
            'stage_plan' => 'array',
            'stage_entered_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function approvable(): MorphTo { return $this->morphTo(); }
    public function workflow(): BelongsTo { return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id'); }
    public function currentStage(): BelongsTo { return $this->belongsTo(ApprovalStage::class, 'current_stage_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function actions(): HasMany { return $this->hasMany(ApprovalAction::class)->orderBy('id'); }

    public function isOverdue(): bool
    {
        $sla = $this->currentStage?->sla_hours;

        return $this->status === ApprovalStatus::Pending && $sla && $this->stage_entered_at?->addHours($sla)->isPast();
    }

    public function stageNumber(): int
    {
        $idx = collect($this->stage_plan)->search(fn ($s) => $s['id'] === $this->current_stage_id);

        return $idx === false ? count($this->stage_plan) : $idx + 1;
    }
}
