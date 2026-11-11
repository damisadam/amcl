<?php

namespace App\Services\Identity;

use App\Exceptions\DomainException;
use App\Models\OtpCode;
use App\Services\Integrations\IntegrationManager;
use App\Services\Tenancy\TenantSettings;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Hash;

/**
 * One-time passwords (simulated SMS). Codes are stored hashed and are never logged. In demo mode
 * the code is returned to the caller so the UI can show it in a clearly-labelled demo banner.
 */
class OtpService
{
    public function issue(string $identifier, string $purpose): ?string
    {
        $recent = OtpCode::where('tenant_id', TenantContext::id())->where('identifier', $identifier)->where('purpose', $purpose)
            ->where('created_at', '>', now()->subSeconds(30))->exists();
        if ($recent) {
            throw new DomainException('Please wait 30 seconds before requesting another code.');
        }
        $code = (string) random_int(100000, 999999);
        OtpCode::where('tenant_id', TenantContext::id())->where('identifier', $identifier)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        OtpCode::create([
            'tenant_id' => TenantContext::id(), 'identifier' => $identifier, 'purpose' => $purpose, 'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) TenantSettings::get('security', 'otp_ttl_minutes', 5)),
        ]);
        // The SMS body sent to the (simulated) gateway is masked in integration logs by IntegrationManager.
        app(IntegrationManager::class)->call('sms', 'send_otp', ['to' => $identifier, 'purpose' => $purpose, 'otp' => $code],
            fn () => new \App\Services\Integrations\ProviderResponse('success', ['queued' => true], providerRef: 'SMS-'.strtoupper(bin2hex(random_bytes(4)))));

        return config('integrations.demo_mode') ? $code : null;
    }

    public function verify(string $identifier, string $purpose, string $code): void
    {
        $otp = OtpCode::where('tenant_id', TenantContext::id())->where('identifier', $identifier)->where('purpose', $purpose)
            ->whereNull('consumed_at')->latest('id')->first();
        if (! $otp || $otp->expires_at->isPast()) {
            throw new DomainException('The code has expired. Request a new one.');
        }
        if ($otp->attempts >= 5) {
            throw new DomainException('Too many incorrect attempts. Request a new code.');
        }
        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            $left = self::MAX_ATTEMPTS - $otp->attempts;
            throw new DomainException($left > 0
                ? __('Incorrect code. :n attempt(s) left.', ['n' => $left])
                : __('Incorrect code. No attempts left – request a new code.'));
        }
        $otp->update(['consumed_at' => now()]);
    }

    public const MAX_ATTEMPTS = 5;

    /** Attempts left on the current unexpired code (0 when none is active). */
    public function remainingAttempts(string $identifier, string $purpose): int
    {
        $otp = OtpCode::where('tenant_id', TenantContext::id())->where('identifier', $identifier)->where('purpose', $purpose)
            ->whereNull('consumed_at')->latest('id')->first();

        return $otp && $otp->expires_at->isFuture() ? max(0, self::MAX_ATTEMPTS - $otp->attempts) : 0;
    }
}
