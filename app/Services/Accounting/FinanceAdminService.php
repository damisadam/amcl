<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Exceptions\DomainException;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\Budget;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\Fund;
use App\Models\JournalLine;
use App\Models\OtherReceivable;
use App\Models\PostingRule;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Support\Numbering;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Finance master data maintenance (chart of accounts, cost centers, posting rules, funds, budgets,
 * bank account limits, non-loan receivables). Every change is audited.
 */
class FinanceAdminService
{
    public function __construct(private GeneralLedger $gl) {}

    public function saveAccount(array $data, ?Account $account, User $actor): Account
    {
        $code = trim((string) ($data['code'] ?? ''));
        if (! preg_match('/^[0-9A-Z-]{2,20}$/', $code)) {
            throw new DomainException('Account code must be 2–20 characters (digits, capitals, hyphen).');
        }
        if (Account::where('code', $code)->when($account, fn ($q) => $q->whereKeyNot($account->id))->exists()) {
            throw new DomainException("Account code {$code} already exists in this chart.");
        }
        $type = AccountType::tryFrom($data['type'] ?? '') ?? throw new DomainException('Choose an account type.');
        $parent = ! empty($data['parent_id']) ? Account::findOrFail($data['parent_id']) : null;
        if ($parent && $parent->type !== $type) {
            throw new DomainException('A child account must have the same type as its parent.');
        }
        if ($parent && $account && $parent->id === $account->id) {
            throw new DomainException('An account cannot be its own parent.');
        }
        $hasLines = $account && JournalLine::where('account_id', $account->id)->exists();
        if ($account && $hasLines && $account->type !== $type) {
            throw new DomainException('The type of an account with postings cannot be changed.');
        }
        $postable = (bool) ($data['is_postable'] ?? true);
        $active = (bool) ($data['is_active'] ?? true);
        if ($account && ! $active && PostingRule::where('account_id', $account->id)->where('is_active', true)->exists()) {
            throw new DomainException('This account is used by active posting rules. Re-map those rules before deactivating it.');
        }
        if ($account && ! $postable && $hasLines) {
            throw new DomainException('An account with postings must remain postable.');
        }
        $attrs = [
            'code' => $code, 'name' => trim((string) $data['name']), 'type' => $type, 'parent_id' => $parent?->id,
            'level' => $data['level'] ?? ($postable ? 'account' : ($parent ? 'sub_head' : 'head')),
            'is_postable' => $postable, 'is_active' => $active,
            'cash_flow_category' => in_array($data['cash_flow_category'] ?? null, ['operating', 'investing', 'financing'], true) ? $data['cash_flow_category'] : null,
            'description' => $data['description'] ?? null,
        ];
        if (blank($attrs['name'])) {
            throw new DomainException('Account name is required.');
        }
        if ($account) {
            $account->fill($attrs);
            Auditor::changes('fms.account_updated', $account);
            $account->save();

            return $account;
        }
        $account = Account::create($attrs);
        Auditor::record('fms.account_created', $account, [], ['code' => $code, 'name' => $attrs['name']]);

        return $account;
    }

    public function saveCostCenter(array $data, ?CostCenter $cc): CostCenter
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '' || blank($data['name'] ?? null)) {
            throw new DomainException('Code and name are required.');
        }
        if (CostCenter::where('code', $code)->when($cc, fn ($q) => $q->whereKeyNot($cc->id))->exists()) {
            throw new DomainException('Cost center code already exists.');
        }
        $attrs = ['code' => $code, 'name' => $data['name'], 'business_line' => $data['business_line'] ?: null,
            'branch_id' => $data['branch_id'] ?: null, 'is_active' => (bool) ($data['is_active'] ?? true)];
        if ($cc) {
            $cc->fill($attrs);
            Auditor::changes('fms.cost_center_updated', $cc);
            $cc->save();

            return $cc;
        }
        $cc = CostCenter::create($attrs);
        Auditor::record('fms.cost_center_created', $cc, [], $attrs);

        return $cc;
    }

    /**
     * Replace the lines of an event's posting rule. Structural balance is enforced:
     *  - system events keep the same multiset of (side, amount key) as the template (only accounts /
     *    descriptions / activation can change), so generated vouchers still balance;
     *  - custom events must use the same amount keys on the debit and credit sides.
     *
     * @param  array<int, array{side:string, account_id:int, amount_key:string, description?:string, is_active?:bool}>  $lines
     */
    public function savePostingRules(string $event, array $lines, User $actor): void
    {
        if (! preg_match('/^[a-z0-9_]{3,50}$/', $event)) {
            throw new DomainException('Event type must be lowercase letters, digits and underscores.');
        }
        $lines = array_values(array_filter($lines, fn ($l) => ! empty($l['account_id']) || ! empty($l['amount_key'])));
        if (count($lines) < 2) {
            throw new DomainException('A posting rule needs at least one debit and one credit line.');
        }
        foreach ($lines as $l) {
            if (! in_array($l['side'] ?? null, ['debit', 'credit'], true) || blank($l['amount_key'] ?? null)) {
                throw new DomainException('Each line needs a side and an amount key.');
            }
            $acc = Account::find($l['account_id'] ?? 0);
            if (! $acc || ! $acc->is_postable || ! $acc->is_active) {
                throw new DomainException('Each line needs an active, postable account.');
            }
        }
        $sig = fn (array $rows) => collect($rows)->map(fn ($l) => $l['side'].':'.$l['amount_key'])->sort()->values()->all();
        $template = config("posting_rules.$event");
        if ($template) {
            $expected = collect($template)->map(fn ($t) => $t[0].':'.$t[2])->sort()->values()->all();
            if ($sig($lines) !== $expected) {
                throw new DomainException('System event lines must keep the same sides and amount keys ('.implode(', ', $expected).'); only accounts and descriptions can be re-mapped.');
            }
        } else {
            $dr = collect($lines)->where('side', 'debit')->pluck('amount_key')->sort()->values()->all();
            $cr = collect($lines)->where('side', 'credit')->pluck('amount_key')->sort()->values()->all();
            if ($dr !== $cr) {
                throw new DomainException('Custom events must use the same amount keys on the debit and credit sides so vouchers always balance.');
            }
        }

        DB::transaction(function () use ($event, $lines) {
            $old = PostingRule::where('event_type', $event)->orderBy('line_no')->get(['line_no', 'side', 'account_id', 'amount_key', 'is_active'])->toArray();
            PostingRule::where('event_type', $event)->delete();
            foreach ($lines as $i => $l) {
                PostingRule::create(['event_type' => $event, 'line_no' => $i + 1, 'side' => $l['side'], 'account_id' => (int) $l['account_id'],
                    'amount_key' => $l['amount_key'], 'description' => $l['description'] ?? null, 'is_active' => (bool) ($l['is_active'] ?? true)]);
            }
            Auditor::record('fms.posting_rules_updated', null, ['event' => $event, 'lines' => $old], ['event' => $event, 'lines' => $lines]);
        });
    }

    public function updateBankAccount(BankAccount $bank, array $data): BankAccount
    {
        $bank->fill(['name' => $data['name'] ?? $bank->name, 'minimum_balance' => Money::of($data['minimum_balance'] ?? $bank->minimum_balance),
            'is_active' => (bool) ($data['is_active'] ?? $bank->is_active)]);
        Auditor::changes('treasury.bank_account_updated', $bank);
        $bank->save();

        return $bank;
    }

    public function saveFund(array $data, ?Fund $fund): Fund
    {
        if (blank($data['code'] ?? null) || blank($data['name'] ?? null)) {
            throw new DomainException('Code and name are required.');
        }
        if (! in_array($data['type'] ?? null, ['own_equity', 'investor', 'bank_line', 'donor'], true)) {
            throw new DomainException('Choose a funding source type.');
        }
        if (! Money::isPositive($data['committed_amount'] ?? 0)) {
            throw new DomainException('Committed amount must be greater than zero.');
        }
        if (Fund::where('code', $data['code'])->when($fund, fn ($q) => $q->whereKeyNot($fund->id))->exists()) {
            throw new DomainException('Fund code already exists.');
        }
        $attrs = collect($data)->only(['code', 'name', 'type', 'provider_name', 'committed_amount', 'structure', 'investor_profit_share_pct', 'start_date', 'end_date', 'terms', 'status'])
            ->map(fn ($v) => $v === '' ? null : $v)->all();
        if ($fund) {
            $fund->fill($attrs);
            Auditor::changes('fms.fund_updated', $fund);
            $fund->save();

            return $fund;
        }
        $fund = Fund::create($attrs + ['status' => 'active']);
        Auditor::record('fms.fund_created', $fund, [], $attrs);

        return $fund;
    }

    /** Non-loan receivable (staff advance, insurance claim…): cash paid out now, receivable recorded. */
    public function createReceivable(array $data, User $actor): OtherReceivable
    {
        $amount = Money::of($data['amount'] ?? 0);
        if (! Money::isPositive($amount) || blank($data['party_name'] ?? null)) {
            throw new DomainException('Party and a positive amount are required.');
        }

        return DB::transaction(function () use ($data, $amount, $actor) {
            $r = OtherReceivable::create(['reference' => Numbering::next('receivable'), 'party_name' => $data['party_name'], 'kind' => $data['kind'] ?? 'other',
                'amount' => $amount, 'due_date' => $data['due_date'] ?? today()->addDays(30), 'status' => 'open', 'notes' => $data['notes'] ?? null]);
            $this->systemVoucher($r, "Receivable {$r->reference} – {$r->party_name}", 'other_receivables', 'operating_bank', $amount, $actor, 'other_receivable');

            return $r;
        });
    }

    public function receiveReceivable(OtherReceivable $r, string $amount, User $actor): OtherReceivable
    {
        $amount = Money::of($amount);
        $open = Money::sub($r->amount, $r->received_amount);
        if (! Money::isPositive($amount) || Money::gt($amount, $open)) {
            throw new DomainException('Amount must be positive and not exceed the open balance ('.Money::format($open).').');
        }

        return DB::transaction(function () use ($r, $amount, $actor) {
            $r->received_amount = Money::add($r->received_amount, $amount);
            $r->status = Money::gte($r->received_amount, $r->amount) ? 'settled' : 'partially_received';
            $r->save();
            $this->systemVoucher($r, "Receipt against {$r->reference} – {$r->party_name}", 'operating_bank', 'other_receivables', $amount, $actor, 'other_receivable_receipt');

            return $r;
        });
    }

    /** @param array<int, array{account_id:int, accounting_period_id:int, amount:string, cost_center_id?:int}> $lines */
    public function saveBudget(FiscalYear $fy, string $name, array $lines, ?Budget $budget, User $actor): Budget
    {
        if (blank($name)) {
            throw new DomainException('Budget name is required.');
        }
        if ($budget && $budget->status === 'approved') {
            throw new DomainException('Approved budgets cannot be edited.');
        }
        $periodIds = AccountingPeriod::where('fiscal_year_id', $fy->id)->pluck('id')->all();

        return DB::transaction(function () use ($fy, $name, $lines, $budget, $periodIds, $actor) {
            $budget ??= Budget::create(['fiscal_year_id' => $fy->id, 'name' => $name, 'status' => 'draft']);
            $budget->update(['name' => $name]);
            $budget->lines()->delete();
            foreach ($lines as $l) {
                if (empty($l['account_id']) || empty($l['accounting_period_id']) || Money::isZero($l['amount'] ?? 0)) {
                    continue;
                }
                if (! in_array((int) $l['accounting_period_id'], $periodIds, true)) {
                    throw new DomainException('Budget periods must belong to the selected fiscal year.');
                }
                Account::where('is_postable', true)->findOrFail($l['account_id']);
                $budget->lines()->create(['account_id' => $l['account_id'], 'accounting_period_id' => $l['accounting_period_id'],
                    'cost_center_id' => $l['cost_center_id'] ?? null, 'amount' => Money::of($l['amount'])]);
            }
            Auditor::record('fms.budget_saved', $budget, [], ['lines' => $budget->lines()->count(), 'by' => $actor->id]);

            return $budget;
        });
    }

    public function approveBudget(Budget $budget, User $actor): void
    {
        $saver = \App\Models\AuditLog::where('auditable_type', $budget->getMorphClass())->where('auditable_id', $budget->id)
            ->where('action', 'fms.budget_saved')->latest('id')->value('actor_id');
        if ((int) $saver === (int) $actor->id) {
            throw new DomainException('Segregation of duties: the budget must be approved by someone other than its preparer.');
        }
        if (! $budget->lines()->exists()) {
            throw new DomainException('Add budget lines before approval.');
        }
        $budget->update(['status' => 'approved']);
        Auditor::record('fms.budget_approved', $budget);
    }

    private function systemVoucher($source, string $narration, string $debitRole, string $creditRole, string $amount, User $actor, string $event): void
    {
        $dr = Account::where('system_role', $debitRole)->value('id');
        $cr = Account::where('system_role', $creditRole)->value('id');
        $jv = $this->gl->createManual(['voucher_date' => today()->toDateString(), 'narration' => $narration], [
            ['account_id' => $dr, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $cr, 'debit' => 0, 'credit' => $amount],
        ], $actor);
        $jv->forceFill(['origin' => 'system', 'source_type' => $source->getMorphClass(), 'source_id' => $source->getKey(), 'event_type' => $event])->save();
        $this->gl->post($jv);
    }
}
