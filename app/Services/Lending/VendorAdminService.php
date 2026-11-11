<?php

namespace App\Services\Lending;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Enums\VendorStatus;
use App\Exceptions\DomainException;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\Audit\Auditor;
use App\Services\Support\Numbering;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

/** Vendor master lifecycle (LMS §4): onboarding approval, status, merchant wallet and portal users. */
class VendorAdminService
{
    public const TRANSITIONS = [
        'pending' => ['active', 'rejected'],
        'active' => ['inactive', 'blacklisted'],
        'inactive' => ['active', 'blacklisted'],
        'rejected' => ['pending'],
        'blacklisted' => [],
    ];

    public function create(array $data, User $by): Vendor
    {
        $this->validateMerchant($data);
        $v = Vendor::create($data + ['code' => Numbering::next('vendor', null, false, 5), 'source' => 'internal']);
        $v->forceFill(['status' => VendorStatus::Pending])->save();
        Auditor::record('vendor.created', $v, [], ['business_name' => $v->business_name, 'by' => $by->id]);

        return $v;
    }

    public function update(Vendor $v, array $data, User $by): Vendor
    {
        $this->validateMerchant($data, $v);
        $bankChanged = (isset($data['iban']) && $data['iban'] !== $v->iban);
        $v->fill($data);
        Auditor::changes('vendor.updated', $v, $bankChanged ? 'Bank details changed' : null);
        $v->save();

        return $v;
    }

    public function approve(Vendor $v, User $by): Vendor
    {
        if (! $by->hasPermission('vendors.approve')) {
            throw new DomainException('You are not authorized to approve vendors.');
        }
        if (! $v->iban && $v->kind === 'asset') {
            throw new DomainException('An asset vendor needs a bank account (IBAN) before approval because disbursements are paid to the vendor.');
        }

        return $this->setStatus($v, VendorStatus::Active, 'Approved', $by, ['approved_by' => $by->id, 'approved_at' => now(), 'rejection_reason' => null]);
    }

    public function reject(Vendor $v, string $reason, User $by): Vendor
    {
        if (! $by->hasPermission('vendors.approve')) {
            throw new DomainException('You are not authorized to reject vendor requests.');
        }
        if (blank($reason)) {
            throw new DomainException('A rejection reason is required.');
        }

        return $this->setStatus($v, VendorStatus::Rejected, $reason, $by, ['rejection_reason' => $reason]);
    }

    public function setStatus(Vendor $v, VendorStatus $to, string $reason, User $by, array $extra = []): Vendor
    {
        $from = $v->status;
        if (! in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true)) {
            throw new DomainException("Vendor cannot move from {$from->label()} to {$to->label()}.");
        }
        if (blank($reason)) {
            throw new DomainException('A reason is required.');
        }
        return DB::transaction(function () use ($v, $to, $reason, $extra, $from) {
            $v->forceFill($extra + ['status' => $to])->save();
            if ($to !== VendorStatus::Active) {
                User::where('vendor_id', $v->id)->where('type', UserType::Vendor->value)->where('status', UserStatus::Active->value)
                    ->update(['status' => UserStatus::Suspended->value]);
            }
            Auditor::record('vendor.status_changed', $v, ['status' => $from->value], ['status' => $to->value], $reason);

            return $v;
        });
    }

    /** Opens & activates the vendor's merchant wallet so internal QR payments credit it directly. */
    public function enableMerchantWallet(Vendor $v, User $by): Wallet
    {
        if (! $v->isActive() || ! $v->is_merchant || ! $v->merchant_code) {
            throw new DomainException('Only active vendors flagged as merchants with a merchant code can have a merchant wallet.');
        }
        $ws = app(WalletService::class);
        $w = $ws->open($v);
        if ($w->status->value === 'pending') {
            $w = $ws->activate($w, $by);
        }
        Auditor::record('vendor.merchant_wallet_enabled', $v, [], ['wallet' => $w->wallet_no]);

        return $w;
    }

    /** Creates a vendor portal login with a temporary password (must change at first login). Returns the temp password. */
    public function createPortalUser(Vendor $v, string $name, string $email, User $by): string
    {
        if (! $v->isActive()) {
            throw new DomainException('Portal access can only be granted to active vendors.');
        }
        if (User::where('tenant_id', $v->tenant_id)->where('email', $email)->exists()) {
            throw new DomainException('A user with this email already exists at this institution.');
        }
        $temp = 'Vnd#'.random_int(100000, 999999).'aA';
        $u = new User(['name' => $name, 'email' => $email, 'designation' => 'Vendor user']);
        $u->forceFill(['tenant_id' => $v->tenant_id, 'type' => UserType::Vendor, 'status' => UserStatus::Active, 'vendor_id' => $v->id,
            'password' => $temp, 'must_change_password' => true, 'created_by' => $by->id])->save();
        Auditor::record('vendor.portal_user_created', $v, [], ['user' => $email]);

        return $temp;
    }

    private function validateMerchant(array $data, ?Vendor $v = null): void
    {
        $code = $data['merchant_code'] ?? null;
        if (! empty($data['is_merchant']) && blank($code)) {
            throw new DomainException('Merchants need a merchant code (printed on their QR).');
        }
        if ($code && Vendor::where('merchant_code', $code)->when($v, fn ($q) => $q->whereKeyNot($v->id))->exists()) {
            throw new DomainException('This merchant code is already in use.');
        }
    }
}
