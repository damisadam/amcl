<?php

namespace App\Services\Identity;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Tenancy\TenantSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * MFA policy and state (S1).
 *  - Required for every platform user, and for staff with the tenant_admin role or any privileged
 *    permission (config security.mfa.privileged_permissions) when the tenant setting
 *    security.staff_mfa_required is on (default on).
 *  - A successful challenge is remembered for the session; sensitive actions (payout release,
 *    journal reversal) additionally call Mfa::recentlyConfirmed() (step-up within 10 minutes).
 */
class Mfa
{
    public const SESSION_KEY = 'mfa_confirmed_at';

    public static function enforced(): bool
    {
        return (bool) config('security.mfa.enforce', true);
    }

    public static function requiredFor(User $user): bool
    {
        if (! self::enforced()) {
            return false;
        }
        if ($user->isPlatform()) {
            return true;
        }
        if (! $user->isStaff()) {
            return false;
        }
        if (! TenantSettings::get('security', 'staff_mfa_required', true, $user->tenant_id)) {
            return false;
        }

        return $user->hasRole('tenant_admin') || $user->hasAnyPermission(config('security.mfa.privileged_permissions', []));
    }

    public static function enrolled(User $user): bool
    {
        return $user->mfa_enabled && $user->mfa_confirmed_at !== null && $user->getRawOriginal('mfa_secret') !== null;
    }

    public static function passedThisSession(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    /** Step-up check for sensitive actions. True when MFA is not required for this user. */
    public static function recentlyConfirmed(User $user, ?int $minutes = null): bool
    {
        if (! self::requiredFor($user)) {
            return true;
        }
        $at = session(self::SESSION_KEY);
        $minutes ??= (int) config('security.mfa.step_up_minutes', 10);

        return $at && now()->timestamp - (int) $at <= $minutes * 60;
    }

    /** Throws a user-safe error when a step-up confirmation is needed. */
    public static function requireRecent(User $user): void
    {
        if (! self::recentlyConfirmed($user)) {
            throw new DomainException('Please re-confirm with your authenticator code (Security check) before this action. Open '.route('mfa.challenge', ['stepup' => 1]).'.');
        }
    }

    public static function secret(User $user): ?string
    {
        $raw = $user->getRawOriginal('mfa_secret');

        return $raw ? Crypt::decryptString($raw) : null;
    }

    /** Start enrolment: store an unconfirmed secret and return it. */
    public static function beginEnrolment(User $user): string
    {
        $secret = Totp::generateSecret();
        $user->forceFill(['mfa_secret' => Crypt::encryptString($secret), 'mfa_enabled' => false, 'mfa_confirmed_at' => null])->save();

        return $secret;
    }

    /** Confirm enrolment with a first valid code; returns plain recovery codes (shown once). */
    public static function confirmEnrolment(User $user, string $code): array
    {
        $secret = self::secret($user) ?? throw new DomainException('Start the set-up again.');
        $step = Totp::verify($secret, $code);
        if ($step === null) {
            throw new DomainException('That code is not valid. Check the time on your phone and try the newest code.');
        }
        $codes = collect(range(1, 8))->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill([
            'mfa_enabled' => true, 'mfa_confirmed_at' => now(), 'mfa_last_timestep' => $step,
            'mfa_recovery_codes' => json_encode(array_map(fn ($c) => Hash::make($c), $codes)),
        ])->save();
        Auditor::record('auth.mfa_enrolled', $user, tenantId: $user->tenant_id);
        self::markPassed();

        return $codes;
    }

    /** Verify a TOTP or a one-time recovery code for the challenge / step-up. */
    public static function verify(User $user, string $code): void
    {
        $code = trim($code);
        $secret = self::secret($user) ?? throw new DomainException('MFA is not set up for this account.');
        $step = Totp::verify($secret, $code);
        if ($step !== null) {
            if ($user->mfa_last_timestep !== null && $step <= (int) $user->mfa_last_timestep) {
                throw new DomainException('This code was already used. Wait for the next code.');
            }
            $user->forceFill(['mfa_last_timestep' => $step])->save();
            self::markPassed();
            Auditor::record('auth.mfa_passed', $user, tenantId: $user->tenant_id);

            return;
        }
        $hashes = json_decode((string) $user->mfa_recovery_codes, true) ?: [];
        foreach ($hashes as $i => $hash) {
            if (Hash::check(strtoupper($code), $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['mfa_recovery_codes' => json_encode(array_values($hashes))])->save();
                self::markPassed();
                Auditor::record('auth.mfa_recovery_code_used', $user, [], ['remaining' => count($hashes)], tenantId: $user->tenant_id);

                return;
            }
        }
        Auditor::record('auth.mfa_failed', $user, tenantId: $user->tenant_id);
        throw new DomainException('Invalid authentication code.');
    }

    public static function markPassed(): void
    {
        session([self::SESSION_KEY => now()->timestamp]);
    }

    /** Admin reset (e.g. lost phone): the user must enrol again at next sign-in. */
    public static function reset(User $user, User $by, string $reason): void
    {
        $user->forceFill(['mfa_enabled' => false, 'mfa_secret' => null, 'mfa_confirmed_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_timestep' => null])->save();
        Auditor::record('auth.mfa_reset', $user, [], ['by' => $by->id], $reason, $user->tenant_id);
    }

    /** Seeded demo users get a fixed secret so demonstrations are repeatable (demo mode only). */
    public static function enrolDemo(User $user): void
    {
        if (app()->isProduction() || ! config('integrations.demo_mode')) {
            return;
        }
        $user->forceFill(['mfa_secret' => Crypt::encryptString(config('security.mfa.demo_secret')), 'mfa_enabled' => true, 'mfa_confirmed_at' => now(),
            'mfa_recovery_codes' => json_encode([])])->save();
    }

    public static function isDemoSecret(User $user): bool
    {
        return config('integrations.demo_mode') && ! app()->isProduction() && self::secret($user) === config('security.mfa.demo_secret');
    }
}
