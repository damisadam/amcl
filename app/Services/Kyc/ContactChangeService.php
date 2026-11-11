<?php

namespace App\Services\Kyc;

use App\Exceptions\DomainException;
use App\Models\Customer;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Audit\Auditor;
use App\Services\Identity\OtpService;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Changing a customer's login identifiers (email, mobile). Both are login ids, so the new value must be
 * unique in the institution and is proven by an OTP sent to the NEW contact; the old contact is notified.
 */
class ContactChangeService
{
    public const PURPOSE = 'contact_change';

    public function __construct(private OtpService $otp, private Notifier $notifier) {}

    public function normalise(string $field, string $value): string
    {
        return $field === 'mobile' ? OnboardingService::normaliseMobile($value) : strtolower(trim($value));
    }

    /** Validates uniqueness and sends the code. Returns the demo OTP (demo mode only). */
    public function start(User $user, string $field, string $value): ?string
    {
        $this->assertField($field);
        $value = $this->normalise($field, $value);
        $c = Customer::findOrFail($user->customer_id);
        if ($value === (string) $c->{$field}) {
            throw new DomainException(__('This is already your :f.', ['f' => $field === 'mobile' ? __('mobile number') : __('email')]));
        }
        $this->assertAvailable($user, $field, $value);

        return $this->otp->issue($value, self::PURPOSE);
    }

    public function confirm(User $user, string $field, string $value, string $code): Customer
    {
        $this->assertField($field);
        $value = $this->normalise($field, $value);
        $this->assertAvailable($user, $field, $value);
        $this->otp->verify($value, self::PURPOSE, $code);

        return DB::transaction(function () use ($user, $field, $value) {
            $c = Customer::whereKey($user->customer_id)->lockForUpdate()->firstOrFail();
            $old = $c->{$field};
            $label = $field === 'mobile' ? __('mobile number') : __('email address');
            // Tell the OLD contact first, so a hijacked session is noticed.
            if ($old) {
                $this->notifier->toCustomer($c, 'contact_changed', [
                    'title' => __('Your :f was changed', ['f' => $label]),
                    'body' => __('Your :f on this account was changed. If this was not you, contact us immediately.', ['f' => $label]),
                ], [$field === 'mobile' ? 'sms' : 'email', 'in_app']);
            }
            $c->{$field} = $value;
            $c->save();
            $user->forceFill([$field => $value] + ($field === 'mobile' ? ['mobile_verified_at' => now()] : ['email_verified_at' => now()]))->save();
            if ($field === 'mobile') {
                Wallet::where('owner_type', $c->getMorphClass())->where('owner_id', $c->id)->update(['mobile' => $value]);
            }
            Auditor::record('customer.contact_changed', $c, [$field => $old], [$field => $value], 'Verified by OTP to the new contact');

            return $c;
        });
    }

    public function remainingAttempts(string $field, string $value): int
    {
        return $this->otp->remainingAttempts($this->normalise($field, $value), self::PURPOSE);
    }

    private function assertField(string $field): void
    {
        if (! in_array($field, ['email', 'mobile'], true)) {
            throw new DomainException('Unsupported field.');
        }
    }

    private function assertAvailable(User $user, string $field, string $value): void
    {
        $taken = User::where('tenant_id', $user->tenant_id)->where($field, $value)->whereKeyNot($user->id)->exists()
            || Customer::where($field, $value)->whereKeyNot($user->customer_id)->exists();
        if ($taken) {
            throw new DomainException(__('This :f is already registered to another account.', ['f' => $field === 'mobile' ? __('mobile number') : __('email')]));
        }
    }
}
