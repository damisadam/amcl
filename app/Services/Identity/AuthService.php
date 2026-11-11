<?php

namespace App\Services\Identity;

use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\DomainException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Tenancy\TenantSettings;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Authentication with lockout and audit. Tenant users authenticate against a specific tenant
 * (organisation code / tenant entry point); platform users authenticate with tenant_id = null.
 */
class AuthService
{
    public function attempt(?Tenant $tenant, string $login, string $password, array $types, bool $remember = false): User
    {
        if ($tenant && $tenant->status !== TenantStatus::Active) {
            throw new DomainException('This institution is currently unavailable.');
        }
        $login = trim($login);
        $user = TenantContext::bypass(fn () => User::query()
            ->where('tenant_id', $tenant?->id)
            ->whereIn('type', $types)
            ->where(fn ($q) => $q->where('email', $login)->orWhere('mobile', \App\Services\Kyc\OnboardingService::normaliseMobile($login) ?: '-'))
            ->first());

        $generic = 'These credentials do not match our records.';
        if (! $user) {
            Auditor::record('auth.login_failed', null, [], ['login' => substr($login, 0, 3).'***', 'reason' => 'unknown'], tenantId: $tenant?->id);
            throw new DomainException($generic);
        }
        $max = (int) TenantSettings::get('security', 'max_failed_logins', 5, $tenant?->id);
        $lockMinutes = (int) TenantSettings::get('security', 'lockout_minutes', 30, $tenant?->id);

        if ($user->locked_until && $user->locked_until->isFuture()) {
            throw new DomainException('Account locked after repeated failed attempts. Try again after '.$user->locked_until->format('H:i').' or contact your administrator.');
        }
        if (! Hash::check($password, $user->password)) {
            $user->failed_login_attempts++;
            if ($user->failed_login_attempts >= $max) {
                $user->locked_until = now()->addMinutes($lockMinutes);
                Auditor::record('auth.account_locked', $user, [], ['attempts' => $user->failed_login_attempts], tenantId: $tenant?->id);
            }
            $user->save();
            Auditor::record('auth.login_failed', $user, [], ['attempts' => $user->failed_login_attempts], tenantId: $tenant?->id);
            throw new DomainException($generic);
        }
        if (! in_array($user->status, [UserStatus::Active], true)) {
            throw new DomainException('Your account is '.strtolower($user->status->label()).'. Contact your administrator.');
        }

        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_login_ip' => request()->ip()]);
        $expiry = (int) TenantSettings::get('security', 'password_expiry_days', 90, $tenant?->id);
        if ($user->type === UserType::Staff && $user->password_changed_at && $user->password_changed_at->lt(now()->subDays($expiry))) {
            $user->must_change_password = true;
        }
        $user->save();
        Auth::login($user, $remember);
        request()->session()->regenerate();
        Auditor::record('auth.login', $user, [], ['type' => $user->type->value], tenantId: $tenant?->id);

        return $user;
    }

    public static function passwordRules(?int $tenantId = null): array
    {
        $min = (int) TenantSettings::get('security', 'password_min_length', 10, $tenantId);

        return ['required', 'string', 'confirmed', \Illuminate\Validation\Rules\Password::min($min)->mixedCase()->numbers()->symbols()];
    }

    public function changePassword(User $user, string $current, string $new): void
    {
        if (! Hash::check($current, $user->password)) {
            throw new DomainException('Your current password is incorrect.');
        }
        if (Hash::check($new, $user->password)) {
            throw new DomainException('Choose a password different from your current one.');
        }
        $user->forceFill(['password' => $new, 'must_change_password' => false, 'password_changed_at' => now()])->save();
        Auditor::record('auth.password_changed', $user);
    }

    public function adminReset(User $target, User $admin): string
    {
        if (! $admin->hasPermission('users.reset_password') || (int) $admin->tenant_id !== (int) $target->tenant_id) {
            throw new DomainException('Not authorized.');
        }
        $temp = 'Tmp#'.random_int(100000, 999999).'aA';
        $target->forceFill(['password' => $temp, 'must_change_password' => true, 'failed_login_attempts' => 0, 'locked_until' => null,
            'status' => $target->status === UserStatus::Locked ? UserStatus::Active : $target->status])->save();
        Auditor::record('users.password_reset_forced', $target);

        return $temp;
    }
}
