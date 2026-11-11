<?php

namespace App\Livewire\Customer\Wallet;

use App\Models\UserDevice;
use App\Services\Audit\Auditor;
use App\Services\Wallet\LinkedAccountService;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Wallet settings (Wallet §9): PIN, biometric (simulated), limits, linked banks, devices. */
#[Layout('layouts.customer')]
class Settings extends Component
{
    use WalletScreen;

    public string $currentPin = '';

    public string $newPin = '';

    public string $newPin_confirmation = '';

    public string $dailyLimit = '';

    public string $newBank = 'Meezan Bank';

    public string $newIban = '';

    public function mount(): void
    {
        $this->dailyLimit = (string) (float) $this->myWallet()->daily_limit;
    }

    public function savePin(WalletService $wallets): void
    {
        $w = $this->myWallet();
        $this->validate(['newPin' => 'required|digits_between:4,6|confirmed', 'currentPin' => $w->pin_hash ? 'required' : 'nullable']);
        if ($w->pin_hash && ! Hash::check($this->currentPin, $w->pin_hash)) {
            $this->addError('currentPin', __('Current PIN is incorrect.'));

            return;
        }
        if ($this->attempt(fn () => $wallets->setPin($w, $this->newPin), __('Wallet PIN saved.'))) {
            $this->reset('currentPin', 'newPin', 'newPin_confirmation');
        }
    }

    public function toggleBiometric(): void
    {
        $w = $this->myWallet();
        $w->forceFill(['biometric_enabled' => ! $w->biometric_enabled])->save();
        Auditor::record('wallet.biometric_toggled', $w, [], ['enabled' => $w->biometric_enabled]);
        $this->toast($w->biometric_enabled ? __('Biometric sign-in enabled on supported devices (simulated in web demo).') : __('Biometric disabled.'));
    }

    /** Customers may only lower their daily limit; raising it requires the institution. */
    public function saveLimit(WalletService $wallets): void
    {
        $w = $this->myWallet();
        $this->validate(['dailyLimit' => 'required|numeric|min:1000']);
        if (Money::gt($this->dailyLimit, $w->daily_limit) && ! Money::eq($this->dailyLimit, $w->daily_limit)) {
            $max = \App\Services\Tenancy\TenantSettings::get('wallet', 'default_daily_limit');
            if (Money::gt($this->dailyLimit, $max)) {
                $this->addError('dailyLimit', __('Maximum daily limit is :m. Contact support for higher limits.', ['m' => Money::format($max)]));

                return;
            }
        }
        $this->attempt(fn () => $wallets->updateLimits($w, ['daily_limit' => Money::of($this->dailyLimit)], 'Changed by customer'), __('Daily limit updated.'));
    }

    public function link(LinkedAccountService $svc): void
    {
        $this->validate(['newIban' => ['required', 'regex:/^PK\d{2}[A-Za-z]{4}\d{16}$/']], ['newIban.regex' => __('Enter a 24-character Pakistani IBAN.')]);
        if ($this->attempt(fn () => $svc->link($this->customer(), $this->newBank, $this->newIban), __('Bank account verified and linked.'))) {
            $this->newIban = '';
        }
    }

    public function unlink(int $id, LinkedAccountService $svc): void
    {
        $this->attempt(fn () => $svc->unlink($this->customer()->linkedBankAccounts()->findOrFail($id)), __('Bank account removed.'));
    }

    public function makeDefault(int $id): void
    {
        $c = $this->customer();
        $acc = $c->linkedBankAccounts()->findOrFail($id);
        $c->linkedBankAccounts()->update(['is_default' => false]);
        $acc->update(['is_default' => true]);
    }

    public function revokeDevice(int $id): void
    {
        $d = UserDevice::where('user_id', $this->user()->id)->findOrFail($id);
        $d->update(['revoked_at' => now()]);
        Auditor::record('auth.device_revoked', $this->user(), [], ['device' => $d->label]);
        $this->toast(__('Device signed out.'));
    }

    public function render()
    {
        return view('livewire.customer.wallet.settings', [
            'w' => $this->myWallet(), 'accounts' => $this->customer()->linkedBankAccounts()->get(), 'banks' => LinkedAccountService::BANKS,
            'devices' => UserDevice::where('user_id', $this->user()->id)->latest('last_seen_at')->get(),
            'currentFp' => hash('sha256', substr((string) request()->userAgent(), 0, 300).'|'.$this->user()->id),
        ])->title(__('Wallet settings'));
    }
}
