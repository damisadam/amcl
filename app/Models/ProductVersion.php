<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Immutable once used by an application: changes create a new version. */
class ProductVersion extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    public const DEFAULT_ALLOCATION = ['late_charge', 'charges', 'profit', 'principal'];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2', 'max_amount' => 'decimal:2', 'profit_rate' => 'decimal:3',
            'down_payment_pct' => 'decimal:2', 'min_income' => 'decimal:2', 'max_dbr' => 'decimal:2',
            'late_charge_per_day' => 'decimal:2', 'dbr_hard_gate' => 'boolean', 'requires_vendor' => 'boolean',
            'cooling_off_waivable' => 'boolean', 'allow_partial_payment' => 'boolean', 'allow_prepayment' => 'boolean',
            'allocation_order' => 'array', 'eligibility' => 'array', 'effective_from' => 'date',
        ];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function charges(): HasMany { return $this->hasMany(Charge::class); }
    public function requiredDocuments(): HasMany { return $this->hasMany(ProductRequiredDocument::class); }

    public function allocationOrder(): array
    {
        return $this->allocation_order ?: self::DEFAULT_ALLOCATION;
    }

    /** One late-payment disclosure used verbatim in the catalogue, product page and KFS (QA F4). */
    public function latePaymentDisclosure(): string
    {
        return $this->late_payment_treatment === 'charity' && (float) $this->late_charge_per_day > 0
            ? 'PKR '.number_format((float) $this->late_charge_per_day, 2).' per day after '.$this->grace_days.' grace days (maximum 90 days), donated to charity – not company income.'
            : 'No late payment charge.';
    }

    /** Representative pricing for catalogue cards: lowest instalment (minimum amount, longest tenor) and its APR (QA F3). */
    public function representativeQuote(): array
    {
        $q = app(\App\Services\Products\FinancingCalculator::class)->quote($this, (string) $this->min_amount, (int) $this->max_tenure);

        return ['instalment' => $q['instalment'], 'apr' => $q['apr'], 'amount' => $q['principal'], 'tenure' => (int) $this->max_tenure];
    }

    public function isWeekly(): bool
    {
        return $this->frequency === 'weekly';
    }
}
