<?php

namespace App\Models;

use App\Enums\ProductCategory;
use App\Enums\ShariahStructure;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'category' => ProductCategory::class,
            'shariah_structure' => ShariahStructure::class,
            'shariah_board_approved' => 'boolean',
            'is_active' => 'boolean',
            'show_in_catalog' => 'boolean',
            'is_staff_product' => 'boolean',
        ];
    }

    public function parent(): BelongsTo { return $this->belongsTo(Product::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(Product::class, 'parent_id'); }
    public function versions(): HasMany { return $this->hasMany(ProductVersion::class)->orderByDesc('version'); }
    public function currentVersion(): BelongsTo { return $this->belongsTo(ProductVersion::class, 'current_version_id'); }
    public function loanCategory(): BelongsTo { return $this->belongsTo(LoanCategory::class); }
    public function branches(): BelongsToMany { return $this->belongsToMany(Branch::class); }

    public function localizedName(?string $locale = null): string
    {
        return (($locale ?? app()->getLocale()) === 'ur' && $this->name_ur) ? $this->name_ur : $this->name;
    }

    public function localizedDescription(?string $locale = null): ?string
    {
        return (($locale ?? app()->getLocale()) === 'ur' && $this->description_ur) ? $this->description_ur : $this->description;
    }
}
