<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Chart of accounts entry (per tenant). */
class Account extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['type' => AccountType::class, 'is_postable' => 'boolean', 'is_active' => 'boolean'];
    }

    public function parent(): BelongsTo { return $this->belongsTo(Account::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(Account::class, 'parent_id')->orderBy('code'); }
    public function lines(): HasMany { return $this->hasMany(JournalLine::class); }

    /** Debit-normal accounts: assets and expenses. */
    public function isDebitNormal(): bool
    {
        return in_array($this->type, [AccountType::Asset, AccountType::Expense], true);
    }

    public function label(): string
    {
        return $this->code.' · '.$this->name;
    }
}
