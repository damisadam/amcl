<?php

namespace App\Models;

use App\Enums\JournalStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/** Posted vouchers are immutable; corrections happen through linked reversal vouchers. */
class JournalVoucher extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'status', 'posted_by', 'posted_at'];

    protected function casts(): array
    {
        return [
            'status' => JournalStatus::class,
            'voucher_date' => 'date',
            'total_debit' => 'decimal:2', 'total_credit' => 'decimal:2',
            'submitted_at' => 'datetime', 'posted_at' => 'datetime', 'reversal_requested_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (JournalVoucher $jv) {
            $original = $jv->getOriginal('status');
            $originalStatus = $original instanceof JournalStatus ? $original : JournalStatus::tryFrom((string) $original);
            if ($originalStatus === JournalStatus::Posted) {
                // Only the posted -> reversed status flag may change on a posted voucher.
                $dirty = array_keys($jv->getDirty());
                $requestOnly = ! array_diff($dirty, ['reversal_requested_by', 'reversal_requested_at', 'reversal_reason', 'updated_at']);
                if ($requestOnly) {
                    return; // recording / clearing a reversal REQUEST does not change the posted entry
                }
                if (array_diff($dirty, ['status', 'updated_at']) || $jv->status !== JournalStatus::Reversed) {
                    throw new LogicException('Posted journal vouchers cannot be edited. Create a reversal instead.');
                }
            }
        });
        static::deleting(function (JournalVoucher $jv) {
            if ($jv->status === JournalStatus::Posted || $jv->status === JournalStatus::Reversed) {
                throw new LogicException('Posted journal vouchers cannot be deleted.');
            }
        });
    }

    public function lines(): HasMany { return $this->hasMany(JournalLine::class)->orderBy('id'); }
    public function period(): BelongsTo { return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id'); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function source(): MorphTo { return $this->morphTo(); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function poster(): BelongsTo { return $this->belongsTo(User::class, 'posted_by'); }
    public function reversalOf(): BelongsTo { return $this->belongsTo(JournalVoucher::class, 'reversal_of_id'); }
    public function reversedBy(): HasOne { return $this->hasOne(JournalVoucher::class, 'reversal_of_id'); }
    public function reversalRequester(): BelongsTo { return $this->belongsTo(User::class, 'reversal_requested_by'); }
    public function approvalRequest(): MorphOne { return $this->morphOne(ApprovalRequest::class, 'approvable')->latestOfMany(); }
    public function documents(): MorphMany { return $this->morphMany(Document::class, 'documentable'); }

    public function isBalanced(): bool
    {
        return bccomp((string) $this->total_debit, (string) $this->total_credit, 2) === 0 && bccomp((string) $this->total_debit, '0', 2) > 0;
    }
}
