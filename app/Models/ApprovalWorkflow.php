<?php

namespace App\Models;

use App\Enums\ApprovalType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalWorkflow extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['type' => ApprovalType::class, 'min_amount' => 'decimal:2', 'max_amount' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function stages(): HasMany { return $this->hasMany(ApprovalStage::class)->orderBy('sequence'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
}
