<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Document;
use App\Models\FieldVisit;
use App\Models\LoanAccount;
use App\Models\LoanApplication;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;

/**
 * Record-level access rules layered on top of tenant isolation (global scopes) and permissions.
 * Used by components via RecordAccess::xxx() and as query scopes for list screens.
 */
class RecordAccess
{
    /** Staff branch scoping: without "applications.view_all" staff see their own branch only. */
    public static function applicationsQuery(Builder $q, User $user): Builder
    {
        if ($user->isCustomer()) {
            return $q->where('customer_id', $user->customer_id);
        }
        if ($user->isVendor()) {
            return $q->where('vendor_id', $user->vendor_id);
        }
        if (! $user->hasPermission('applications.view')) {
            return $q->whereRaw('1=0');
        }
        if ($user->hasPermission('applications.view_all') || ! $user->branch_id || $user->branch?->is_head_office) {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('branch_id', $user->branch_id)->orWhere('loan_officer_id', $user->id));
    }

    public static function loansQuery(Builder $q, User $user): Builder
    {
        if ($user->isCustomer()) {
            return $q->where('customer_id', $user->customer_id);
        }
        if ($user->hasPermission('loans.view') || $user->hasPermission('recovery.view')) {
            if ($user->hasPermission('applications.view_all') || ! $user->branch_id || $user->branch?->is_head_office) {
                return $q;
            }

            return $q->where('branch_id', $user->branch_id);
        }
        if ($user->hasPermission('recovery.work')) {
            return $q->where('recovery_agent_id', $user->id);
        }

        return $q->whereRaw('1=0');
    }

    public static function canViewApplication(User $user, LoanApplication $app): bool
    {
        return self::applicationsQuery(LoanApplication::query()->whereKey($app->id), $user)->exists();
    }

    public static function canViewLoan(User $user, LoanAccount $loan): bool
    {
        return self::loansQuery(LoanAccount::query()->whereKey($loan->id), $user)->exists();
    }

    public static function canViewCustomer(User $user, Customer $c): bool
    {
        return match (true) {
            $user->isCustomer() => (int) $user->customer_id === (int) $c->id,
            $user->isVendor() => (int) $c->origin_vendor_id === (int) $user->vendor_id,
            $user->isStaff() => $user->hasPermission('customers.view'),
            default => false,
        };
    }

    public static function canViewVisit(User $user, FieldVisit $v): bool
    {
        return $user->hasPermission('field_visits.view') || (int) $v->assigned_to === (int) $user->id;
    }

    /** Identity documents (CNIC, selfie, guarantor CNIC) additionally require the sensitive-data permission for staff. */
    public static function canDownload(User $user, Document $doc): bool
    {
        $owner = $doc->documentable;
        if (! $owner) {
            return false;
        }
        if ($user->isCustomer()) {
            $cid = (int) $user->customer_id;

            return match (true) {
                $owner instanceof Customer => (int) $owner->id === $cid,
                $owner instanceof LoanApplication, $owner instanceof LoanAccount => (int) $owner->customer_id === $cid,
                default => false,
            };
        }
        if ($user->isVendor()) {
            return match (true) {
                $owner instanceof Vendor => (int) $owner->id === (int) $user->vendor_id,
                $owner instanceof LoanApplication => (int) $owner->vendor_id === (int) $user->vendor_id,
                default => false,
            };
        }
        if (! $user->isStaff()) {
            return false;
        }
        if ($owner instanceof FieldVisit && (int) $owner->assigned_to === (int) $user->id) {
            return true;
        }
        if (! $user->hasPermission('documents.view')) {
            return false;
        }
        $sensitive = in_array($doc->type?->code, ['CNIC_FRONT', 'CNIC_BACK', 'SELFIE', 'GUARANTOR_CNIC'], true);

        return ! $sensitive || $user->hasPermission('customers.view_sensitive');
    }
}
