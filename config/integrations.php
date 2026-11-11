<?php

/*
| Integration catalogue (API Management Layer, Agreement §9.2). Each provider lists only the fields
| relevant to it. Credential fields are encrypted at rest and never returned to the browser.
| All providers ship with a deterministic simulated adapter; no live adapter exists in release 1.
*/

$payment = ['base_url', 'merchant_id', 'client_id', 'client_secret', 'api_key', 'webhook_secret'];

return [
    'demo_mode' => env('AMCL_DEMO_MODE', false),

    'providers' => [
        'nadra_verisys' => ['name' => 'NADRA Verisys', 'category' => 'identity', 'fields' => ['base_url', 'client_id', 'client_secret', 'api_key'], 'description' => 'CNIC verification against NADRA records.'],
        'cnic_ocr' => ['name' => 'CNIC OCR', 'category' => 'identity', 'fields' => ['base_url', 'api_key'], 'description' => 'Extracts identity fields from CNIC images.'],
        'liveness' => ['name' => 'Face Match / Liveness', 'category' => 'identity', 'fields' => ['base_url', 'api_key'], 'description' => 'Selfie-to-CNIC face match with liveness detection.'],
        'aml_screening' => ['name' => 'Sanctions / PEP Screening', 'category' => 'compliance', 'fields' => ['base_url', 'api_key'], 'description' => 'Screens against UN, NACTA proscribed persons and PEP lists.'],
        'tasdeeq' => ['name' => 'Tasdeeq Credit Bureau', 'category' => 'credit', 'fields' => ['base_url', 'client_id', 'client_secret', 'member_code'], 'description' => 'Credit reports for applicants and guarantors.'],
        'datacheck' => ['name' => 'Data Check', 'category' => 'credit', 'fields' => ['base_url', 'client_id', 'client_secret', 'member_code'], 'description' => 'Alternate credit bureau.'],
        'raast' => ['name' => 'Raast (via Sponsor Bank)', 'category' => 'payments', 'fields' => array_merge($payment, ['participant_code', 'iban_alias']), 'description' => 'Instant payments: disbursement, collections, top-ups, withdrawals.'],
        'sponsor_bank' => ['name' => 'Sponsor Bank (e.g. Bank Alfalah / UBL)', 'category' => 'payments', 'fields' => array_merge($payment, ['settlement_account_iban']), 'description' => 'Sponsor bank for safeguarded account, settlement reports and IBFT.'],
        'easypaisa' => ['name' => 'Easypaisa', 'category' => 'payments', 'fields' => array_merge($payment, ['store_id']), 'description' => 'Mobile wallet collections and top-ups.'],
        'jazzcash' => ['name' => 'JazzCash', 'category' => 'payments', 'fields' => array_merge($payment, ['integrity_salt']), 'description' => 'Mobile wallet collections and top-ups.'],
        'one_bill' => ['name' => '1Bill / 1LINK', 'category' => 'payments', 'fields' => array_merge($payment, ['biller_id']), 'description' => 'Instalment biller collections and utility bill payments.'],
        'nift_esign' => ['name' => 'NIFT eSign', 'category' => 'esign', 'fields' => ['base_url', 'client_id', 'client_secret', 'certificate_alias'], 'description' => 'Accredited e-signature CSP.'],
        'sms' => ['name' => 'SMS Gateway (PTA sender ID)', 'category' => 'messaging', 'fields' => ['base_url', 'api_key', 'sender_id'], 'description' => 'OTP and notifications.'],
        'email' => ['name' => 'Email (SPF/DKIM)', 'category' => 'messaging', 'fields' => ['host', 'username', 'password', 'from_address'], 'description' => 'Transactional email.'],
        'whatsapp' => ['name' => 'WhatsApp Business API', 'category' => 'messaging', 'fields' => ['base_url', 'api_key', 'phone_number_id'], 'description' => 'Reminders and servicing notifications.'],
        'push' => ['name' => 'Push (FCM / APNs)', 'category' => 'messaging', 'fields' => ['server_key'], 'description' => 'Reserved for future native apps; web uses in-app notifications.'],
    ],

    /* Fields considered secret: masked in UI, encrypted at rest, redacted in logs. */
    'secret_fields' => ['client_secret', 'api_key', 'webhook_secret', 'password', 'integrity_salt', 'server_key'],

    /* Selectable simulated outcomes for payment providers (see docs/API_SIMULATION.md). */
    'payment_scenarios' => [
        'success' => 'Success (instant callback)',
        'delayed_success' => 'Success via delayed callback',
        'pending' => 'Stays pending until a callback is simulated',
        'failure' => 'Failure (declined)',
        'timeout' => 'Timeout, resolved later by inquiry',
        'duplicate_callback' => 'Success + duplicate callback',
        'reversal' => 'Success, then reversed by provider',
        'invalid_reference' => 'Callback with unknown reference',
        'amount_mismatch' => 'Callback amount differs from request',
    ],

    'kyc_scenarios' => [
        'auto' => 'Deterministic by CNIC last digit (default)',
        'pass' => 'Force pass',
        'fail' => 'Force fail',
        'manual_review' => 'Force manual review',
        'aml_match' => 'Force AML potential match',
    ],
];
