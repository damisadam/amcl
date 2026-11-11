<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanApplication extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** Status is never mass-assignable: it only changes through LoanApplicationWorkflow. */
    protected $guarded = ['id', 'tenant_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'asset_price' => 'decimal:2', 'down_payment' => 'decimal:2', 'requested_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2', 'indicative_instalment' => 'decimal:2', 'dbr' => 'decimal:2',
            'is_reevaluation' => 'boolean', 'cooling_off_waived' => 'boolean',
            'submitted_at' => 'datetime', 'first_submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime', 'kfs_acknowledged_at' => 'datetime', 'signed_at' => 'datetime',
            'cooling_off_ends_at' => 'datetime', 'cancelled_at' => 'datetime', 'disbursed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function productVersion(): BelongsTo { return $this->belongsTo(ProductVersion::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function vendorItem(): BelongsTo { return $this->belongsTo(VendorItem::class); }
    public function fund(): BelongsTo { return $this->belongsTo(Fund::class); }
    public function officer(): BelongsTo { return $this->belongsTo(User::class, 'loan_officer_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function previousApplication(): BelongsTo { return $this->belongsTo(LoanApplication::class, 'previous_application_id'); }
    public function histories(): HasMany { return $this->hasMany(ApplicationStatusHistory::class)->orderBy('id'); }
    public function guarantors(): HasMany { return $this->hasMany(Guarantor::class); }
    public function bureauChecks(): HasMany { return $this->hasMany(BureauCheck::class); }
    public function assessments(): HasMany { return $this->hasMany(CreditAssessment::class)->latest('id'); }
    public function latestAssessment(): HasOne { return $this->hasOne(CreditAssessment::class)->latestOfMany(); }
    public function fieldVisits(): HasMany { return $this->hasMany(FieldVisit::class); }
    public function objections(): HasMany { return $this->hasMany(Objection::class)->latest(); }
    public function kfs(): HasOne { return $this->hasOne(KeyFactStatement::class)->latestOfMany('version'); }
    public function signatureRequests(): HasMany { return $this->hasMany(SignatureRequest::class); }
    public function documents(): MorphMany { return $this->morphMany(Document::class, 'documentable'); }
    public function approvalRequest(): MorphOne { return $this->morphOne(ApprovalRequest::class, 'approvable')->latestOfMany(); }
    public function disbursements(): HasMany { return $this->hasMany(Disbursement::class); }
    public function loanAccount(): HasOne { return $this->hasOne(LoanAccount::class); }

    public function financedAmount(): string
    {
        return $this->approved_amount ?? $this->requested_amount;
    }

    public function isCoolingOffElapsed(): bool
    {
        return $this->cooling_off_waived || ($this->cooling_off_ends_at && $this->cooling_off_ends_at->isPast());
    }

    public function isEditableByCustomer(): bool
    {
        return in_array($this->status, [ApplicationStatus::Draft, ApplicationStatus::Returned], true);
    }
}
