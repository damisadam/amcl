<?php

namespace App\Models;

use App\Enums\VendorStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Goods supplier (disbursement recipient), operational supplier, and/or wallet merchant. */
class Vendor extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = ['id', 'tenant_id', 'status', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return [
            'status' => VendorStatus::class,
            'is_merchant' => 'boolean',
            'approved_at' => 'datetime',
            'markup_share_pct' => 'decimal:2',
        ];
    }

    public function items(): HasMany { return $this->hasMany(VendorItem::class); }
    public function users(): HasMany { return $this->hasMany(User::class); }
    public function documents(): MorphMany { return $this->morphMany(Document::class, 'documentable'); }
    public function applications(): HasMany { return $this->hasMany(LoanApplication::class); }
    public function disbursements(): HasMany { return $this->hasMany(Disbursement::class, 'recipient_id')->where('recipient_type', 'vendor'); }
    public function bills(): HasMany { return $this->hasMany(VendorBill::class); }
    public function payments(): HasMany { return $this->hasMany(VendorPayment::class); }
    public function wallet(): MorphOne { return $this->morphOne(Wallet::class, 'owner'); }
    public function customers(): HasMany { return $this->hasMany(Customer::class, 'origin_vendor_id'); }

    /** Product category → vendor categories that can supply that asset (M4). */
    public const SUPPLIES = [
        'motorbike' => ['motorbike'], 'scooty' => ['motorbike'], 'rickshaw' => ['rickshaw'], 'laptop' => ['electronics'],
        'solar' => ['solar'], 'home_appliances' => ['appliances'], 'sewing_machine' => ['appliances'],
        'school_fees' => ['education'], // D-020: fees are paid to the school as payee
    ];

    /** Vendor categories able to supply a product (empty = the product needs no vendor). */
    public static function categoriesForProduct(Product $product): array
    {
        return self::SUPPLIES[$product->category->value] ?? [];
    }

    /** Product categories this vendor can supply. */
    public function productCategories(): array
    {
        return array_keys(array_filter(self::SUPPLIES, fn ($cats) => in_array($this->category, $cats, true)));
    }

    /** Active vendors that supply the given product's asset category. */
    public function scopeForProduct($query, Product $product)
    {
        return $query->where('status', 'active')->whereIn('category', self::categoriesForProduct($product) ?: ['__none__']);
    }

    public function isActive(): bool
    {
        return $this->status === VendorStatus::Active;
    }
}
