<?php

return [
    /*
    | Multi-factor authentication (TOTP, RFC 6238) for platform users and privileged staff.
    | `enforce` can be switched off ONLY for automated tests (phpunit.xml sets AMCL_MFA_ENFORCE=false;
    | the security tests switch it back on).
    */
    'mfa' => [
        'enforce' => env('AMCL_MFA_ENFORCE', true),
        'issuer' => env('AMCL_MFA_ISSUER', 'AMCL Platform'),
        'step_up_minutes' => 10,
        // Staff holding any of these permissions (or the tenant_admin role) must use MFA when the
        // tenant setting security.staff_mfa_required is on (default on).
        'privileged_permissions' => ['approvals.act', 'disbursements.initiate', 'fms.journals.post', 'fms.journals.reverse', 'fms.payables.execute', 'kyc.review', 'aml.review'],
        // Fixed TOTP secret given to seeded demo users so demos are repeatable (never used outside demo mode).
        'demo_secret' => 'JBSWY3DPEHPK3PXPAMCLDEMO',
    ],

    /* Content-Security-Policy extra sources (fonts, websocket). */
    'csp' => [
        'enabled' => env('AMCL_CSP_ENABLED', true),
        'font_hosts' => ['https://fonts.bunny.net'],
    ],
];
