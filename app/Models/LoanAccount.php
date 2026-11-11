<?php

namespace App\Models;

use App\Enums\LoanAccountStatus;
use App\Enums\ShariahStructure;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Balances here are maintained only by RepaymentService / LoanAccountService. */
class LoanAccount extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'status' => LoanAccountStatus::class,
            'shariah_structure' => ShariahStructure::class,
            'profit_rate' => 'decimal:3',
            'principal' => 'decimal:2', 'profit_total' => 'decimal:2', 'charges_total' => 'decimal:2',
            'total_receivable' => 'decimal:2', 'principal_outstanding' => 'decimal:2', 'profit_outstanding' => 'decimal:2',
            'charges_outstanding' => 'decimal:2', 'late_charges_outstanding' => 'decimal:2', 'total_paid' => 'decimal:2',
            'terms_snapshot' => 'array',
            'first_due_date' => 'date', 'maturity_date' => 'date',
            'disbursed_at' => 'datetime', 'closed_at' => 'datetime', 'noc_issued_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo { return $this->belongsTo(LoanApplication::class, 'loan_application_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function productVersion(): BelongsTo { return $this->belongsTo(ProductVersion::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function fund(): BelongsTo { return $this->belongsTo(Fund::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function officer(): BelongsTo { return $this->belongsTo(User::class, 'loan_officer_id'); }
    public function recoveryAgent(): BelongsTo { return $this->belongsTo(User::class, 'recovery_agent_id'); }
    public function instalments(): HasMany { return $this->hasMany(Instalment::class)->orderBy('number'); }
    public function payments(): HasMany { return $this->hasMany(LoanPayment::class)->latest('id'); }
    public function recoveryContacts(): HasMany { return $this->hasMany(RecoveryContact::class)->latest('contacted_at'); }
    public function provisions(): HasMany { return $this->hasMany(LoanProvision::class); }
    public function documents(): MorphMany { return $this->morphMany(Document::class, 'documentable'); }

    public function totalOutstanding(): string
    {
        return Money::add($this->principal_outstanding, $this->profit_outstanding, $this->charges_outstanding, $this->late_charges_outstanding);
    }

    public function overdueAmount(): string
    {
        return Money::of($this->instalments()
            ->where('due_date', '<', today())
            ->whereIn('status', ['due', 'partially_paid', 'overdue'])
            ->selectRaw('COALESCE(SUM(total_due + late_charge_due - total_paid), 0) as v')->value('v'));
    }

    public function nextInstalment(): ?Instalment
    {
        return $this->instalments()->whereIn('status', ['pending', 'due', 'partially_paid', 'overdue'])->orderBy('number')->first();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [LoanAccountStatus::Active, LoanAccountStatus::Overdue], true);
    }
}
