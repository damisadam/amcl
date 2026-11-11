<?php

namespace App\Services\Kyc;

use App\Enums\AmlStatus;
use App\Enums\CustomerStatus;
use App\Enums\KycStatus;
use App\Exceptions\DomainException;
use App\Models\BlacklistEntry;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Support\Numbering;
use Illuminate\Support\Facades\DB;

/** Staff-assisted customer management (LMS §2): branch onboarding, notes, blacklist, archival. */
class CustomerAdminService
{
    public function create(array $data, User $by, string $channel = 'branch'): Customer
    {
        if (! $by->hasPermission('customers.create')) {
            throw new DomainException('You are not authorized to create customers.');
        }
        $data['mobile'] = OnboardingService::normaliseMobile($data['mobile']);
        foreach (['monthly_income', 'other_income', 'existing_obligations'] as $money) {
            $data[$money] = ($data[$money] ?? null) === null || $data[$money] === '' ? '0' : $data[$money];
        }
        if (Customer::where('mobile', $data['mobile'])->exists()) {
            throw new DomainException('A customer with this mobile number already exists.');
        }
        if (! empty($data['cnic']) && Customer::where('cnic', $data['cnic'])->exists()) {
            throw new DomainException('A customer with this CNIC already exists at this institution.');
        }

        return DB::transaction(function () use ($data, $by, $channel) {
            $nok = $data['next_of_kin'] ?? null;
            unset($data['next_of_kin']);
            $c = new Customer(array_merge($data, [
                'cif_no' => Numbering::next('customer', null, false, 7),
                'onboarding_channel' => $channel,
                'onboarding_step' => 'kyc',
                'branch_id' => $data['branch_id'] ?? $by->branch_id,
                'assigned_officer_id' => $by->id,
                'created_by' => $by->id,
            ]));
            $c->forceFill(['status' => CustomerStatus::Pending, 'kyc_status' => KycStatus::NotStarted, 'aml_status' => AmlStatus::NotScreened])->save();
            if ($nok && ! empty($nok['name'])) {
                $c->nextOfKins()->create($nok);
            }
            Auditor::record('customer.created_by_staff', $c, [], ['cif' => $c->cif_no, 'channel' => $channel]);

            return $c;
        });
    }

    public function addNote(Customer $c, User $by, string $body, string $category = 'general'): CustomerNote
    {
        if (blank($body)) {
            throw new DomainException('Note cannot be empty.');
        }

        return $c->notes()->create(['user_id' => $by->id, 'body' => $body, 'category' => $category]);
    }

    public function setBlacklist(Customer $c, User $by, bool $on, string $reason): Customer
    {
        if (! $by->hasPermission('config.blacklist')) {
            throw new DomainException('You are not authorized to manage the blacklist.');
        }
        if (blank($reason)) {
            throw new DomainException('A reason is required.');
        }
        DB::transaction(function () use ($c, $by, $on, $reason) {
            $c->forceFill(['is_blacklisted' => $on, 'blacklist_reason' => $on ? $reason : null])->save();
            if ($on) {
                BlacklistEntry::create(['cnic' => $c->cnic, 'name' => $c->full_name, 'reason' => 'other', 'notes' => $reason, 'added_by' => $by->id]);
            } elseif ($c->cnic) {
                BlacklistEntry::where('cnic', $c->cnic)->update(['is_active' => false]);
            }
            Auditor::record($on ? 'customer.blacklisted' : 'customer.blacklist_removed', $c, [], [], $reason);
        });

        return $c;
    }

    public function archive(Customer $c, User $by, string $reason): void
    {
        if (! $by->hasPermission('customers.archive')) {
            throw new DomainException('You are not authorized to archive customers.');
        }
        if (blank($reason)) {
            throw new DomainException('A reason is required to archive a customer.');
        }
        if ($c->loanAccounts()->whereIn('status', ['active', 'overdue'])->exists()) {
            throw new DomainException('Customers with open financing cannot be archived.');
        }
        if ($c->wallet && ! \App\Support\Money::isZero($c->wallet->balance)) {
            throw new DomainException('Customers with a wallet balance cannot be archived.');
        }
        DB::transaction(function () use ($c, $reason) {
            $c->forceFill(['status' => CustomerStatus::Archived, 'archived_reason' => $reason])->save();
            Auditor::record('customer.archived', $c, [], [], $reason);
            $c->delete();
        });
    }
}
