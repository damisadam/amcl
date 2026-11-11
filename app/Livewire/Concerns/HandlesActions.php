<?php

namespace App\Livewire\Concerns;

use App\Exceptions\DomainException;
use Illuminate\Support\Str;

/**
 * Standard action handling for Livewire screens: permission guard, business-rule errors shown
 * to the user (never stack traces), toasts, and per-form idempotency keys for money actions.
 */
trait HandlesActions
{
    public string $idem = '';

    public function bootHandlesActions(): void
    {
        if ($this->idem === '') {
            $this->idem = (string) Str::uuid();
        }
    }

    /** Abort 403 unless the current user holds at least one of the permissions. */
    protected function need(string ...$permissions): void
    {
        abort_unless(auth()->user()?->hasAnyPermission($permissions), 403, __('You are not authorized to perform this action.'));
    }

    /** Run a business action; DomainException messages are shown to the user. Returns the callback's result or null. */
    protected function attempt(callable $fn, ?string $success = null): mixed
    {
        try {
            $result = $fn();
            if ($success) {
                $this->toast($success);
            }
            $this->idem = (string) Str::uuid();

            return $result ?? true;
        } catch (DomainException $e) {
            $this->addError('action', $e->getMessage());
            $this->toast($e->getMessage(), 'red');

            return null;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->toast(__('The record no longer exists or is not accessible.'), 'red');

            return null;
        }
    }

    /**
     * Step-up authentication for high-risk actions (payout release, journal reversal, payment release):
     * requires an authenticator confirmation within the last few minutes. Redirects to the challenge
     * and returns false when one is needed; the user comes back to this page afterwards.
     */
    protected function stepUp(): bool
    {
        if (\App\Services\Identity\Mfa::recentlyConfirmed(auth()->user())) {
            return true;
        }
        $this->toast(__('Security check: confirm with your authenticator code to continue.'), 'blue');
        $prev = parse_url(url()->previous());
        $back = ($prev['path'] ?? '/').(isset($prev['query']) ? '?'.$prev['query'] : ''); // relative path only (open-redirect guard)
        $this->redirect(route('mfa.challenge', ['stepup' => 1, 'return' => $back]));

        return false;
    }

    protected function toast(string $message, string $tone = 'green'): void
    {
        $this->dispatch('toast', message: $message, tone: $tone);
    }
}
