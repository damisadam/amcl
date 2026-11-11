<?php

namespace App\Livewire\Ops\Products;

use App\Models\DocumentType;
use App\Models\ProductVersion;

/** Shared terms / charges / required-documents form used when creating a product or a new version. */
trait ProductTermsForm
{
    public array $terms = [];

    /** @var list<array{code:string,name:string,type:string,calculation:string,value:string,apply_at:string}> */
    public array $charges = [];

    /** @var list<array{code:string,applies_to:string,mandatory:bool,min_amount:?string}> */
    public array $docs = [];

    protected function defaultTerms(?ProductVersion $v = null): void
    {
        $this->terms = $v ? array_map(fn ($x) => is_bool($x) ? $x : (string) $x, $v->only([
            'min_amount', 'max_amount', 'min_tenure', 'max_tenure', 'frequency', 'profit_rate', 'profit_method', 'down_payment_pct', 'min_income', 'max_dbr',
            'dbr_hard_gate', 'min_age', 'max_age', 'guarantors_required', 'requires_vendor', 'disbursement_mode', 'cooling_off_hours', 'cooling_off_waivable',
            'allow_partial_payment', 'allow_prepayment', 'late_payment_treatment', 'late_charge_per_day', 'grace_days', 'min_credit_grade',
        ])) + ['allocation' => implode(',', $v->allocationOrder())] : [
            'min_amount' => '50000', 'max_amount' => '500000', 'min_tenure' => '6', 'max_tenure' => '24', 'frequency' => 'monthly', 'profit_rate' => '24',
            'profit_method' => 'flat', 'down_payment_pct' => '10', 'min_income' => '25000', 'max_dbr' => '40', 'dbr_hard_gate' => true, 'min_age' => '18',
            'max_age' => '60', 'guarantors_required' => '0', 'requires_vendor' => true, 'disbursement_mode' => 'vendor', 'cooling_off_hours' => '24',
            'cooling_off_waivable' => false, 'allow_partial_payment' => true, 'allow_prepayment' => true, 'late_payment_treatment' => 'charity',
            'late_charge_per_day' => '50', 'grace_days' => '3', 'min_credit_grade' => '', 'allocation' => 'late_charge,charges,profit,principal',
        ];
        $this->charges = $v ? $v->charges->map(fn ($c) => ['code' => $c->code, 'name' => $c->name, 'type' => $c->type->value, 'calculation' => $c->calculation,
            'value' => (string) (float) $c->value, 'apply_at' => $c->apply_at])->all()
            : [['code' => 'PF', 'name' => 'Processing fee', 'type' => 'processing_fee', 'calculation' => 'percentage', 'value' => '2', 'apply_at' => 'upfront_deducted']];
        $this->docs = $v ? $v->requiredDocuments->map(fn ($d) => ['code' => $d->documentType->code, 'applies_to' => $d->applies_to, 'mandatory' => (bool) $d->is_mandatory,
            'min_amount' => $d->min_amount ? (string) (float) $d->min_amount : ''])->all()
            : [['code' => 'CNIC_FRONT', 'applies_to' => 'applicant', 'mandatory' => true, 'min_amount' => '']];
    }

    public function addCharge(): void
    {
        $this->charges[] = ['code' => 'C'.(count($this->charges) + 1), 'name' => '', 'type' => 'other', 'calculation' => 'flat', 'value' => '0', 'apply_at' => 'added_to_instalments'];
    }

    public function removeCharge(int $i): void
    {
        unset($this->charges[$i]);
        $this->charges = array_values($this->charges);
    }

    public function addDoc(): void
    {
        $this->docs[] = ['code' => DocumentType::value('code'), 'applies_to' => 'applicant', 'mandatory' => true, 'min_amount' => ''];
    }

    public function removeDoc(int $i): void
    {
        unset($this->docs[$i]);
        $this->docs = array_values($this->docs);
    }

    protected function termsRules(): array
    {
        return [
            'terms.min_amount' => 'required|numeric|min:1', 'terms.max_amount' => 'required|numeric|gte:terms.min_amount',
            'terms.min_tenure' => 'required|integer|min:1', 'terms.max_tenure' => 'required|integer|gte:terms.min_tenure',
            'terms.frequency' => 'required|in:monthly,weekly', 'terms.profit_rate' => 'required|numeric|min:0|max:100',
            'terms.profit_method' => 'required|in:flat,reducing', 'terms.down_payment_pct' => 'required|numeric|min:0|max:90',
            'terms.min_income' => 'required|numeric|min:0', 'terms.max_dbr' => 'required|numeric|min:1|max:100',
            'terms.min_age' => 'required|integer|min:18', 'terms.max_age' => 'required|integer|gte:terms.min_age',
            'terms.guarantors_required' => 'required|integer|min:0|max:3', 'terms.disbursement_mode' => 'required|in:vendor,customer_bank,wallet',
            'terms.cooling_off_hours' => 'required|integer|min:0|max:240', 'terms.late_payment_treatment' => 'required|in:charity,none',
            'terms.late_charge_per_day' => 'required|numeric|min:0', 'terms.grace_days' => 'required|integer|min:0|max:30',
            'terms.min_credit_grade' => 'nullable|in:A,B+,B,C,D', 'terms.allocation' => 'required|string',
            'charges.*.code' => 'required|string|max:30', 'charges.*.name' => 'required|string|max:100', 'charges.*.type' => 'required|in:'.implode(',', \App\Enums\ChargeType::values()),
            'charges.*.calculation' => 'required|in:flat,percentage', 'charges.*.value' => 'required|numeric|min:0',
            'charges.*.apply_at' => 'required|in:upfront_deducted,added_to_instalments,on_event',
            'docs.*.code' => 'required|exists:document_types,code', 'docs.*.applies_to' => 'required|in:applicant,guarantor', 'docs.*.min_amount' => 'nullable|numeric|min:0',
        ];
    }

    protected function termsPayload(): array
    {
        $alloc = array_values(array_filter(array_map('trim', explode(',', $this->terms['allocation']))));
        if (array_diff($alloc, ['late_charge', 'charges', 'profit', 'principal']) || count($alloc) !== 4) {
            throw new \App\Exceptions\DomainException('Allocation order must list late_charge, charges, profit and principal exactly once.');
        }
        $t = $this->terms;
        unset($t['allocation']);
        foreach (['dbr_hard_gate', 'requires_vendor', 'cooling_off_waivable', 'allow_partial_payment', 'allow_prepayment'] as $b) {
            $t[$b] = (bool) ($t[$b] ?? false);
        }
        $t['min_credit_grade'] = $t['min_credit_grade'] ?: null;
        $t['allocation_order'] = $alloc;

        return $t;
    }

    protected function chargesPayload(): array
    {
        return array_map(fn ($c) => [$c['code'], $c['name'], $c['type'], $c['calculation'], (string) $c['value'], $c['apply_at']], $this->charges);
    }

    protected function docsPayload(): array
    {
        return array_map(fn ($d) => [$d['code'], $d['applies_to'], (bool) $d['mandatory'], $d['min_amount'] === '' ? null : $d['min_amount']], $this->docs);
    }
}
