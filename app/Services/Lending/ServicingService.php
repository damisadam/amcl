<?php

namespace App\Services\Lending;

use App\Enums\LoanAccountStatus;
use App\Exceptions\DomainException;
use App\Models\Instalment;
use App\Models\LoanAccount;
use App\Models\PortfolioTransfer;
use App\Models\RecoveryContact;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Documents\DocumentStore;
use App\Services\Notifications\Notifier;
use App\Services\Support\Numbering;
use App\Services\Tenancy\TenantSettings;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Post-disbursement servicing: daily delinquency run, reminders, recovery workflow with
 * collections conduct guardrails (DFS §6.5), NOC issuance (DFS §5.2 / LMS §6.7) and portfolio transfer (LMS §9.13).
 */
class ServicingService
{
    public function __construct(private RepaymentService $repayments, private Notifier $notifier) {}

    /**
     * Daily delinquency run for the current tenant. Late payment amounts accrue per day after the grace
     * period (capped at 90 days) and, under the Shariah model, are routed to the charity fund when collected.
     */
    public function runDelinquency(?Carbon $asOf = null): array
    {
        $asOf ??= today();
        $stats = ['loans' => 0, 'overdue' => 0];
        $dpdMap = TenantSettings::get('lending', 'classification_dpd');
        LoanAccount::whereIn('status', ['active', 'overdue'])->chunkById(200, function ($loans) use ($asOf, &$stats, $dpdMap) {
            foreach ($loans as $loan) {
                DB::transaction(function () use ($loan, $asOf, &$stats, $dpdMap) {
                    $loan = LoanAccount::whereKey($loan->id)->lockForUpdate()->first();
                    $perDay = Money::of($loan->terms_snapshot['late_charge_per_day'] ?? 0);
                    $grace = (int) ($loan->terms_snapshot['grace_days'] ?? 0);
                    $charity = ($loan->terms_snapshot['late_payment_treatment'] ?? 'charity') === 'charity';
                    foreach (Instalment::where('loan_account_id', $loan->id)->where('status', '!=', 'paid')->where('due_date', '<', $asOf)->get() as $inst) {
                        $daysLate = $inst->due_date->diffInDays($asOf) - $grace;
                        $late = ($charity && $daysLate > 0) ? Money::mul($perDay, min($daysLate, 90)) : '0.00';
                        if (! Money::eq($late, $inst->late_charge_due)) {
                            $inst->late_charge_due = Money::max($late, $inst->late_charge_paid);
                            $inst->save();
                        }
                    }
                    $this->repayments->recalculate($loan);
                    $loan->refresh();
                    $loan->classification = match (true) {
                        $loan->dpd >= $dpdMap['loss'] => 'loss',
                        $loan->dpd >= $dpdMap['doubtful'] => 'doubtful',
                        $loan->dpd >= $dpdMap['substandard'] => 'substandard',
                        $loan->dpd >= $dpdMap['oaem'] => 'oaem',
                        default => 'regular',
                    };
                    $loan->save();
                    $stats['loans']++;
                    $stats['overdue'] += $loan->dpd > 0 ? 1 : 0;
                });
            }
        });

        return $stats;
    }

    /** Payment reminders N days before, on, and after the due date (LMS §8 items 6 / 6.1). */
    public function sendReminders(?Carbon $asOf = null): int
    {
        $asOf ??= today();
        $count = 0;
        $days = (array) TenantSettings::get('lending', 'reminder_days_before_due');
        $dates = collect($days)->map(fn ($d) => $asOf->copy()->addDays((int) $d)->toDateString())->push($asOf->copy()->subDay()->toDateString());
        Instalment::with('loanAccount.customer')->whereIn('due_date', $dates)->where('status', '!=', 'paid')->chunkById(200, function ($rows) use ($asOf, &$count) {
            foreach ($rows as $inst) {
                $loan = $inst->loanAccount;
                if (! $loan->isOpen()) {
                    continue;
                }
                $diff = (int) $asOf->diffInDays($inst->due_date, false);
                $when = $diff > 0 ? "due in {$diff} day(s)" : ($diff === 0 ? 'due today' : 'overdue');
                $this->notifier->toCustomer($loan->customer, 'instalment_reminder', [
                    'title' => 'Instalment '.$when,
                    'body' => 'Instalment #'.$inst->number.' of '.Money::format(Money::sub(Money::add($inst->total_due, $inst->late_charge_due), $inst->total_paid)).' for '.$loan->account_no.' is '.$when.' ('.$inst->due_date->format('d M Y').').',
                    'url' => '/app/financing/'.$loan->id,
                ], ['in_app', 'sms', 'whatsapp']);
                $count++;
            }
        });

        return $count;
    }

    public function assignRecovery(array $loanIds, User $agent, User $by): int
    {
        if (! $agent->hasPermission('recovery.work') || (int) $agent->tenant_id !== (int) $by->tenant_id) {
            throw new DomainException('Cases can only be assigned to Recovery Agents of this institution.');
        }
        $n = 0;
        foreach (LoanAccount::whereIn('id', $loanIds)->get() as $loan) {
            $old = $loan->recovery_agent_id;
            $loan->update(['recovery_agent_id' => $agent->id]);
            Auditor::record('recovery.assigned', $loan, ['agent_id' => $old], ['agent_id' => $agent->id]);
            $n++;
        }
        $this->notifier->toUser($agent, 'recovery_assigned', ['title' => "{$n} recovery case(s) assigned", 'body' => 'Open the Recovery portal to start working them.', 'url' => '/ops/recovery']);

        return $n;
    }

    /** Collections conduct guardrails: 07:00–22:00, borrower/guarantor only, recorded calls. */
    public function logContact(LoanAccount $loan, User $agent, array $data, ?Carbon $at = null): RecoveryContact
    {
        $at ??= now();
        $isAssigned = (int) $loan->recovery_agent_id === (int) $agent->id;
        if (! $isAssigned && ! $agent->hasPermission('recovery.assign')) {
            throw new DomainException('This case is not assigned to you.');
        }
        if (! in_array($data['contacted_party'] ?? 'borrower', ['borrower', 'guarantor'], true)) {
            throw new DomainException('Contacting third parties (family, neighbours, employer) about overdue status is prohibited.');
        }
        if (in_array($data['contact_type'], ['call', 'visit', 'sms', 'whatsapp'], true)) {
            $start = TenantSettings::get('lending', 'collection_contact_start', '07:00');
            $end = TenantSettings::get('lending', 'collection_contact_end', '22:00');
            $t = $at->format('H:i');
            if ($t < $start || $t > $end) {
                throw new DomainException("Collections contact is only permitted between {$start} and {$end}.");
            }
        }
        if (($data['contact_type'] ?? null) === 'call' && blank($data['call_recording_ref'] ?? null)) {
            $data['call_recording_ref'] = 'REC-SIM-'.strtoupper(substr(md5($loan->id.$at), 0, 8));
        }
        if (($data['outcome'] ?? null) === 'promise_to_pay' && (empty($data['ptp_date']) || empty($data['ptp_amount']))) {
            throw new DomainException('Promise-to-pay requires a date and amount.');
        }
        $contact = $loan->recoveryContacts()->create(array_intersect_key($data, array_flip([
            'contact_type', 'contacted_party', 'outcome', 'ptp_date', 'ptp_amount', 'call_recording_ref', 'notes',
        ])) + ['agent_id' => $agent->id, 'contacted_at' => $at, 'ptp_status' => ($data['outcome'] ?? null) === 'promise_to_pay' ? 'open' : null]);
        Auditor::record('recovery.contact_logged', $loan, [], ['outcome' => $contact->outcome, 'type' => $contact->contact_type]);

        return $contact;
    }

    public function sendDelayNotice(LoanAccount $loan, User $agent): RecoveryContact
    {
        $this->notifier->toCustomer($loan->customer, 'payment_delay_notice', [
            'title' => 'Overdue payment notice',
            'body' => 'Your financing '.$loan->account_no.' is '.$loan->dpd.' days overdue with '.Money::format($loan->overdueAmount()).' due. Please pay to avoid further late payment amounts (donated to charity).',
        ], ['in_app', 'sms']);

        return $this->logContact($loan, $agent, ['contact_type' => 'sms', 'contacted_party' => 'borrower', 'outcome' => 'other', 'notes' => 'Payment delay notice sent']);
    }

    /** Evaluate open promises to pay: kept if paid since the promise, otherwise broken after the date. */
    public function evaluatePromises(): void
    {
        RecoveryContact::where('ptp_status', 'open')->whereDate('ptp_date', '<', today())->with('loanAccount')->chunkById(200, function ($rows) {
            foreach ($rows as $c) {
                $paid = $c->loanAccount->payments()->where('status', 'succeeded')->where('received_at', '>=', $c->contacted_at)->sum('amount');
                $c->update(['ptp_status' => Money::gte(Money::of($paid), $c->ptp_amount) ? 'kept' : 'broken']);
            }
        });
    }

    public function issueNoc(LoanAccount $loan, User $actor): LoanAccount
    {
        if (! $actor->hasPermission('loans.issue_noc')) {
            throw new DomainException('You are not authorized to issue NOCs.');
        }

        return DB::transaction(function () use ($loan, $actor) {
            $loan = LoanAccount::whereKey($loan->id)->lockForUpdate()->first();
            if ($loan->status !== LoanAccountStatus::Closed || ! Money::isZero($loan->totalOutstanding())) {
                throw new DomainException('A NOC can only be issued for a fully repaid, closed financing.');
            }
            $loan->status->assertCanTransitionTo(LoanAccountStatus::NocIssued, 'Loan account');
            $loan->forceFill(['status' => LoanAccountStatus::NocIssued, 'noc_issued_at' => now(), 'noc_no' => Numbering::next('noc')])->save();
            $html = view('documents.noc', ['loan' => $loan, 'tenant' => $loan->tenant])->render();
            app(DocumentStore::class)->storeGenerated($loan, \App\Models\DocumentType::where('code', 'NOC')->first(), 'No Objection Certificate '.$loan->noc_no, $html, $actor);
            Auditor::record('loan.noc_issued', $loan, [], ['noc_no' => $loan->noc_no]);
            RepaymentService::lifecycleEvent($loan, 'No Objection Certificate '.$loan->noc_no.' issued – financing closed.');
            DB::afterCommit(fn () => $this->notifier->toCustomer($loan->customer, 'noc_issued', [
                'title' => 'NOC issued', 'body' => 'No Objection Certificate '.$loan->noc_no.' has been issued for '.$loan->account_no.'. You can download it from My Financing.',
                'url' => '/app/financing/'.$loan->id,
            ], ['in_app', 'sms', 'email']));

            return $loan;
        });
    }

    public function transferPortfolio(array $loanIds, ?int $toBranchId, ?int $toOfficerId, string $reason, User $by): PortfolioTransfer
    {
        if (! $by->hasPermission('loans.manage')) {
            throw new DomainException('You are not authorized to transfer portfolios.');
        }
        if (! $loanIds || (! $toBranchId && ! $toOfficerId) || blank($reason)) {
            throw new DomainException('Select loans, a destination branch or officer, and give a reason.');
        }
        if ($toOfficerId && ! User::whereKey($toOfficerId)->where('type', 'staff')->exists()) {
            throw new DomainException('Destination officer not found.');
        }

        return DB::transaction(function () use ($loanIds, $toBranchId, $toOfficerId, $reason, $by) {
            $loans = LoanAccount::whereIn('id', $loanIds)->lockForUpdate()->get();
            $first = $loans->first();
            $t = PortfolioTransfer::create([
                'reference' => Numbering::next('portfolio_transfer'), 'from_branch_id' => $first?->branch_id, 'to_branch_id' => $toBranchId,
                'from_officer_id' => $first?->loan_officer_id, 'to_officer_id' => $toOfficerId, 'loan_account_ids' => $loans->pluck('id')->all(),
                'reason' => $reason, 'transferred_by' => $by->id,
            ]);
            foreach ($loans as $loan) {
                $old = $loan->only(['branch_id', 'loan_officer_id']);
                $loan->update(array_filter(['branch_id' => $toBranchId, 'loan_officer_id' => $toOfficerId]));
                Auditor::record('loan.portfolio_transferred', $loan, $old, $loan->only(['branch_id', 'loan_officer_id']), $reason);
            }

            return $t;
        });
    }
}
