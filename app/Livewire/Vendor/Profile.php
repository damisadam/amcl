<?php

namespace App\Livewire\Vendor;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Vendor\Concerns\ResolvesVendor;
use App\Services\Audit\Auditor;
use App\Services\Lending\VendorOriginationService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Vendor profile. Contact details are self-service. Bank details are NOT changed directly: the
 * request is audited and sent to the institution's vendor approvers, who update the record after
 * verification (prevents payout redirection fraud).
 */
#[Layout('layouts.vendor')]
class Profile extends Component
{
    use HandlesActions, ResolvesVendor;

    public array $contact = [];

    public array $bank = ['bank_name' => '', 'iban' => '', 'account_title' => '', 'reason' => ''];

    public bool $bankRequested = false;

    public function mount(): void
    {
        $v = $this->vendor();
        $this->contact = ['owner_name' => (string) $v->owner_name, 'phone' => (string) $v->phone, 'email' => (string) $v->email, 'address' => (string) $v->address, 'city' => (string) $v->city];
    }

    public function saveContact(): void
    {
        $v = $this->vendor();
        $this->validate(['contact.owner_name' => 'nullable|string|max:120', 'contact.phone' => ['nullable', 'regex:/^[0-9+\-\s]{7,20}$/'],
            'contact.email' => 'nullable|email|max:150', 'contact.address' => 'nullable|string|max:190', 'contact.city' => 'nullable|string|max:60']);
        $this->attempt(function () use ($v) {
            $v->fill(array_map(fn ($x) => $x === '' ? null : $x, $this->contact));
            Auditor::changes('vendor.profile_updated', $v);
            $v->save();
        }, __('Contact details updated.'));
    }

    public function requestBankChange(): void
    {
        $v = $this->vendor();
        $this->validate(['bank.bank_name' => 'required|string|max:100', 'bank.iban' => ['required', 'regex:/^PK\d{2}[A-Z]{4}\d{16}$/'],
            'bank.account_title' => 'required|string|max:120', 'bank.reason' => 'required|string|min:10|max:300'],
            ['bank.iban.regex' => __('Enter a valid Pakistani IBAN, e.g. PK36SCBL0000001123456702.')]);
        $this->attempt(function () use ($v) {
            Auditor::record('vendor.bank_change_requested', $v,
                ['bank_name' => $v->bank_name, 'iban' => $v->iban ? '****'.substr($v->iban, -4) : null, 'account_title' => $v->account_title],
                ['bank_name' => $this->bank['bank_name'], 'iban' => $this->bank['iban'], 'account_title' => $this->bank['account_title']], $this->bank['reason']);
            VendorOriginationService::notifyStaff('vendors.approve', 'Bank detail change requested by '.$v->business_name,
                'New IBAN ending '.substr($this->bank['iban'], -4).'. Verify with the bank letter before updating the vendor record.', '/ops/vendors/'.$v->id);
            $this->bankRequested = true;
            $this->bank = ['bank_name' => '', 'iban' => '', 'account_title' => '', 'reason' => ''];
        }, __('Bank change request sent to the institution for verification.'));
    }

    public function render()
    {
        return view('livewire.vendor.profile', ['vendor' => $this->vendor()]);
    }
}
