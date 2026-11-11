<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioTransfer extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'loan_account_ids' => 'array',
        ];
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function fromOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_officer_id');
    }

    public function toOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_officer_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}
