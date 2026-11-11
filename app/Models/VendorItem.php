<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorItem extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'pending_price', 'pending_price_requested_by', 'pending_price_requested_at'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'pending_price' => 'decimal:2',
            'pending_price_requested_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Items whose asset category matches the product (items without a category follow their vendor's mapping). */
    public function scopeForProduct($query, Product $product)
    {
        return $query->where('is_active', true)->where(fn ($q) => $q->whereNull('product_category')->orWhere('product_category', $product->category->value)
            ->orWhereIn('product_category', Vendor::categoriesForProduct($product)));
    }
}
