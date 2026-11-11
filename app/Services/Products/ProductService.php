<?php

namespace App\Services\Products;

use App\Exceptions\DomainException;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Product catalogue management with immutable versions (LMS §3). Changing terms creates a new
 * version; applications and loans keep the version they were created / approved under.
 */
class ProductService
{
    public function create(array $product, array $terms, array $charges = [], array $documents = [], ?User $by = null): Product
    {
        return DB::transaction(function () use ($product, $terms, $charges, $documents, $by) {
            $p = Product::create($product);
            $this->newVersion($p, $terms, $charges, $documents, $by);
            Auditor::record('product.created', $p, [], ['code' => $p->code]);

            return $p->fresh('currentVersion');
        });
    }

    /**
     * @param  array  $charges  list of [code, name, type, calculation, value, apply_at]
     * @param  array  $documents  list of [document type code, applies_to, mandatory, min_amount]
     */
    public function newVersion(Product $p, array $terms, array $charges = [], array $documents = [], ?User $by = null, bool $exact = false): ProductVersion
    {
        $this->validate($terms);

        return DB::transaction(function () use ($p, $terms, $charges, $documents, $by, $exact) {
            $previous = $p->currentVersion;
            $v = $p->versions()->create(array_merge($previous ? $previous->only([
                'min_amount', 'max_amount', 'min_tenure', 'max_tenure', 'frequency', 'profit_rate', 'profit_method', 'down_payment_pct', 'min_income', 'max_dbr',
                'dbr_hard_gate', 'min_age', 'max_age', 'guarantors_required', 'requires_vendor', 'disbursement_mode', 'cooling_off_hours', 'cooling_off_waivable',
                'allow_partial_payment', 'allow_prepayment', 'allocation_order', 'late_payment_treatment', 'late_charge_per_day', 'grace_days', 'min_credit_grade', 'eligibility',
            ]) : [], $terms, [
                'version' => ($previous?->version ?? 0) + 1, 'status' => 'active', 'effective_from' => today(), 'created_by' => $by?->id,
            ]));

            $charges = ($charges || $exact) ? $charges : ($previous ? $previous->charges->map(fn ($c) => [$c->code, $c->name, $c->type->value, $c->calculation, (string) $c->value, $c->apply_at])->all() : []);
            foreach ($charges as [$code, $name, $type, $calc, $value, $applyAt]) {
                $v->charges()->create(['code' => $code, 'name' => $name, 'type' => $type, 'calculation' => $calc, 'value' => $value, 'apply_at' => $applyAt]);
            }
            $documents = ($documents || $exact) ? $documents : ($previous ? $previous->requiredDocuments->map(fn ($d) => [$d->documentType->code, $d->applies_to, $d->is_mandatory, $d->min_amount])->all() : []);
            $types = DocumentType::pluck('id', 'code');
            foreach ($documents as $d) {
                [$code, $appliesTo, $mandatory, $min] = $d + [null, 'applicant', true, null];
                if (! isset($types[$code])) {
                    throw new DomainException("Unknown document type {$code}.");
                }
                $v->requiredDocuments()->create(['document_type_id' => $types[$code], 'applies_to' => $appliesTo, 'is_mandatory' => $mandatory, 'min_amount' => $min]);
            }
            $previous?->update(['status' => 'retired']);
            $p->forceFill(['current_version_id' => $v->id])->save();
            Auditor::record('product.version_created', $p, ['version' => $previous?->version], ['version' => $v->version, 'profit_rate' => $v->profit_rate]);

            return $v;
        });
    }

    private function validate(array $t): void
    {
        if (isset($t['min_amount'], $t['max_amount']) && Money::gt($t['min_amount'], $t['max_amount'])) {
            throw new DomainException('Minimum amount cannot exceed maximum amount.');
        }
        if (isset($t['min_tenure'], $t['max_tenure']) && $t['min_tenure'] > $t['max_tenure']) {
            throw new DomainException('Minimum tenure cannot exceed maximum tenure.');
        }
        if (isset($t['profit_rate']) && ((float) $t['profit_rate'] < 0 || (float) $t['profit_rate'] > 100)) {
            throw new DomainException('Profit rate must be between 0 and 100%.');
        }
    }
}
