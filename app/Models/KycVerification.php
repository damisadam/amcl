<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycVerification extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'reviewed_at' => 'datetime',
            'score' => 'decimal:2',
            'step' => \App\Enums\KycStep::class,
            'status' => \App\Enums\VerificationResult::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function integrationLog(): BelongsTo
    {
        return $this->belongsTo(IntegrationLog::class);
    }
}
