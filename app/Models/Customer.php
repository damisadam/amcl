<?php

namespace App\Models;

use App\Enums\AmlStatus;
use App\Enums\CustomerStatus;
use App\Enums\KycStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Unified Customer Information File (CIF) record shared by LMS, DFS, Wallet and FMS. */
class Customer extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = ['id', 'tenant_id', 'kyc_status', 'aml_status', 'aml_risk', 'is_blacklisted', 'status'];

    protected function casts(): array
    {
        return [
            'kyc_status' => KycStatus::class,
            'aml_status' => AmlStatus::class,
            'status' => CustomerStatus::class,
            'date_of_birth' => 'date',
            'cnic_issue_date' => 'date',
            'cnic_expiry_date' => 'date',
            'monthly_income' => 'decimal:2',
            'other_income' => 'decimal:2',
            'existing_obligations' => 'decimal:2',
            'is_blacklisted' => 'boolean',
            'activated_at' => 'datetime',
        ];
    }

    public function user(): HasOne { return $this->hasOne(User::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function officer(): BelongsTo { return $this->belongsTo(User::class, 'assigned_officer_id'); }
    public function originVendor(): BelongsTo { return $this->belongsTo(Vendor::class, 'origin_vendor_id'); }
    public function corporateClient(): BelongsTo { return $this->belongsTo(CorporateClient::class); }
    public function nextOfKins(): HasMany { return $this->hasMany(NextOfKin::class); }
    public function notes(): HasMany { return $this->hasMany(CustomerNote::class)->latest(); }
    public function consents(): HasMany { return $this->hasMany(Consent::class); }
    public function kycVerifications(): HasMany { return $this->hasMany(KycVerification::class); }
    public function documents(): MorphMany { return $this->morphMany(Document::class, 'documentable'); }
    public function applications(): HasMany { return $this->hasMany(LoanApplication::class); }
    public function loanAccounts(): HasMany { return $this->hasMany(LoanAccount::class); }
    public function wallet(): MorphOne { return $this->morphOne(Wallet::class, 'owner'); }
    public function linkedBankAccounts(): HasMany { return $this->hasMany(LinkedBankAccount::class); }
    public function complaints(): HasMany { return $this->hasMany(Complaint::class); }
    public function changeRequests(): HasMany { return $this->hasMany(CustomerChangeRequest::class); }

    public function age(): ?int
    {
        return $this->date_of_birth?->age;
    }

    public function maskedCnic(): ?string
    {
        return $this->cnic ? substr($this->cnic, 0, 5).'-*******-'.substr($this->cnic, -1) : null;
    }

    public function isKycPassed(): bool
    {
        return $this->kyc_status === KycStatus::Passed;
    }

    public function totalIncome(): string
    {
        return \App\Support\Money::add($this->monthly_income, $this->other_income);
    }
}
