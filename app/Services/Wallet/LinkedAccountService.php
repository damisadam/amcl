<?php

namespace App\Services\Wallet;

use App\Exceptions\DomainException;
use App\Models\Customer;
use App\Models\LinkedBankAccount;
use App\Services\Audit\Auditor;
use App\Services\Integrations\Deterministic;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\ProviderResponse;

/** Customer linked bank accounts with a simulated Raast title fetch (IBAN → account title). */
class LinkedAccountService
{
    public const BANKS = ['Meezan Bank', 'HBL', 'UBL', 'MCB', 'Bank Alfalah', 'Allied Bank', 'Bank Al Habib', 'Faysal Bank', 'Standard Chartered', 'BankIslami'];

    public function __construct(private IntegrationManager $integrations) {}

    /** Deterministic: valid PK IBAN format → title is the customer's name; IBANs ending 0000 are "not found". */
    public function titleFetch(Customer $c, string $iban): ProviderResponse
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban));

        return $this->integrations->call('raast', 'title_fetch', ['iban' => substr($iban, 0, 8).'…'], function () use ($c, $iban) {
            if (! preg_match('/^PK\d{2}[A-Z]{4}\d{16}$/', $iban)) {
                return new ProviderResponse('failure', [], 'INVALID_IBAN', 'Invalid IBAN format.');
            }
            if (str_ends_with($iban, '0000')) {
                return new ProviderResponse('failure', [], 'ACCOUNT_NOT_FOUND', 'Account not found (simulated).');
            }

            return new ProviderResponse('success', ['title' => strtoupper($c->full_name), 'simulated' => true], providerRef: Deterministic::ref('TF', $iban));
        }, $c);
    }

    public function link(Customer $c, string $bank, string $iban, bool $default = false): LinkedBankAccount
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban));
        if (! in_array($bank, self::BANKS, true)) {
            throw new DomainException(__('Select a supported bank.'));
        }
        $res = $this->titleFetch($c, $iban);
        if (! $res->successful()) {
            throw new DomainException(__($res->message ?? 'Account verification failed.'));
        }
        if ($c->linkedBankAccounts()->where('iban', $iban)->exists()) {
            throw new DomainException(__('This account is already linked.'));
        }
        $first = ! $c->linkedBankAccounts()->exists();
        if ($default) {
            $c->linkedBankAccounts()->update(['is_default' => false]);
        }
        $acc = $c->linkedBankAccounts()->create(['bank_name' => $bank, 'iban' => $iban, 'account_title' => $res->get('title'), 'is_verified' => true, 'is_default' => $default || $first]);
        Auditor::record('wallet.bank_linked', $acc, [], ['bank' => $bank, 'iban' => '****'.substr($iban, -4)]);

        return $acc;
    }

    public function unlink(LinkedBankAccount $acc): void
    {
        Auditor::record('wallet.bank_unlinked', $acc, [], ['iban' => '****'.substr($acc->iban, -4)]);
        $acc->delete();
    }
}
