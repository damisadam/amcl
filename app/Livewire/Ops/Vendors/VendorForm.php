<?php

namespace App\Livewire\Ops\Vendors;

trait VendorForm
{
    public array $form = [];

    protected function blankForm(): array
    {
        return ['business_name' => '', 'owner_name' => '', 'cnic' => '', 'ntn' => '', 'registration_no' => '', 'category' => 'motorbike', 'kind' => 'asset',
            'is_merchant' => false, 'merchant_code' => '', 'phone' => '', 'email' => '', 'address' => '', 'city' => '', 'bank_name' => '', 'iban' => '',
            'account_title' => '', 'preferred_payment_method' => 'raast', 'markup_share_pct' => '', 'pricing_notes' => ''];
    }

    protected function formRules(): array
    {
        return [
            'form.business_name' => 'required|string|max:150', 'form.owner_name' => 'nullable|string|max:120',
            'form.cnic' => ['nullable', 'regex:/^\d{5}-\d{7}-\d$/'], 'form.ntn' => 'nullable|string|max:20', 'form.registration_no' => 'nullable|string|max:60',
            'form.category' => 'required|in:'.implode(',', array_keys(VendorCategories::ALL)), 'form.kind' => 'required|in:asset,operational',
            'form.merchant_code' => 'nullable|alpha_dash|max:30', 'form.phone' => 'nullable|string|max:20', 'form.email' => 'nullable|email|max:150',
            'form.address' => 'nullable|string|max:255', 'form.city' => 'nullable|string|max:60', 'form.bank_name' => 'nullable|string|max:80',
            'form.iban' => ['nullable', 'regex:/^PK\d{2}[A-Z]{4}[0-9A-Z]{16}$/'], 'form.account_title' => 'nullable|string|max:120',
            'form.preferred_payment_method' => 'required|in:raast,bank_transfer,cheque', 'form.markup_share_pct' => 'nullable|numeric|min:0|max:100',
            'form.pricing_notes' => 'nullable|string|max:1000',
        ];
    }

    protected function formMessages(): array
    {
        return ['form.iban.regex' => __('IBAN must look like PK36ABCD0000001123456702 (24 characters).'), 'form.cnic.regex' => __('CNIC format: 12345-1234567-1.')];
    }

    protected function formPayload(): array
    {
        $d = $this->form;
        $d['is_merchant'] = (bool) $d['is_merchant'];
        $d['iban'] = $d['iban'] ? strtoupper(str_replace(' ', '', $d['iban'])) : null;
        foreach (['merchant_code', 'markup_share_pct', 'cnic', 'email'] as $k) {
            $d[$k] = $d[$k] === '' ? null : $d[$k];
        }

        return $d;
    }
}
