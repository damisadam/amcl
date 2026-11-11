<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Support\Money;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Financial statements computed directly from posted journal lines of the current tenant.
 * Vouchers in status "posted" and "reversed" both count: a reversed voucher stays in the books
 * alongside its (posted) reversal voucher, which nets it to zero.
 */
class FinancialReports
{
    private const POSTED = ['posted', 'reversed'];

    private function lines(?string $from = null, ?string $to = null, ?int $branchId = null): Builder
    {
        return DB::table('journal_lines')
            ->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_lines.journal_voucher_id')
            ->where('journal_lines.tenant_id', TenantContext::id() ?? 0)
            ->whereIn('journal_vouchers.status', self::POSTED)
            ->when($from, fn ($q) => $q->where('journal_vouchers.voucher_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('journal_vouchers.voucher_date', '<=', $to))
            ->when($branchId, fn ($q) => $q->where('journal_lines.branch_id', $branchId));
    }

    /** Balance in the account's natural direction (debit-normal for assets/expenses). */
    public function accountBalance(Account $account, ?string $to = null, ?string $from = null): string
    {
        $row = $this->lines($from, $to)->where('journal_lines.account_id', $account->id)
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) dr, COALESCE(SUM(journal_lines.credit),0) cr')->first();

        return $account->isDebitNormal() ? Money::sub($row->dr, $row->cr) : Money::sub($row->cr, $row->dr);
    }

    public function roleBalance(string $role, ?string $to = null): string
    {
        $account = Account::where('system_role', $role)->first();

        return $account ? $this->accountBalance($account, $to) : '0.00';
    }

    /**
     * Financing receivable NET of deferred (unearned) profit and financed charges. Under the Murabaha posting rules the
     * gross receivable carries the contract value; the net equals the principal outstanding in the loan sub-ledger.
     */
    public function netFinancingReceivable(?string $to = null): string
    {
        return Money::add($this->roleBalance('financing_receivable', $to), $this->roleBalance('deferred_profit', $to), $this->roleBalance('deferred_charges', $to));
    }

    /** @return array{rows: array, total_debit: string, total_credit: string, balanced: bool} */
    public function trialBalance(?string $from, string $to, ?int $branchId = null): array
    {
        $sums = $this->lines($from, $to, $branchId)->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) dr, SUM(journal_lines.credit) cr')->get()->keyBy('account_id');
        $rows = [];
        $tdr = $tcr = '0.00';
        foreach (Account::where('is_postable', true)->orderBy('code')->get() as $a) {
            $s = $sums[$a->id] ?? null;
            if (! $s) {
                continue;
            }
            $net = Money::sub($s->dr, $s->cr);
            $dr = Money::isPositive($net) ? $net : '0.00';
            $cr = Money::lt($net, 0) ? ltrim($net, '-') : '0.00';
            if (Money::isZero($dr) && Money::isZero($cr)) {
                continue;
            }
            $rows[] = ['account' => $a, 'debit' => $dr, 'credit' => $cr, 'period_debit' => Money::of($s->dr), 'period_credit' => Money::of($s->cr)];
            $tdr = Money::add($tdr, $dr);
            $tcr = Money::add($tcr, $cr);
        }

        return ['rows' => $rows, 'total_debit' => $tdr, 'total_credit' => $tcr, 'balanced' => Money::eq($tdr, $tcr)];
    }

    public function profitAndLoss(string $from, string $to, ?int $branchId = null): array
    {
        $sums = $this->lines($from, $to, $branchId)->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) dr, SUM(journal_lines.credit) cr')->get()->keyBy('account_id');
        $income = $expense = [];
        $ti = $te = '0.00';
        foreach (Account::whereIn('type', ['income', 'expense'])->where('is_postable', true)->orderBy('code')->get() as $a) {
            $s = $sums[$a->id] ?? null;
            if (! $s) {
                continue;
            }
            if ($a->type->value === 'income') {
                $v = Money::sub($s->cr, $s->dr);
                $income[] = ['account' => $a, 'amount' => $v];
                $ti = Money::add($ti, $v);
            } else {
                $v = Money::sub($s->dr, $s->cr);
                $expense[] = ['account' => $a, 'amount' => $v];
                $te = Money::add($te, $v);
            }
        }

        return ['income' => $income, 'expenses' => $expense, 'total_income' => $ti, 'total_expenses' => $te, 'net_profit' => Money::sub($ti, $te)];
    }

    public function balanceSheet(string $asOf, ?int $branchId = null): array
    {
        $sums = $this->lines(null, $asOf, $branchId)->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) dr, SUM(journal_lines.credit) cr')->get()->keyBy('account_id');
        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        $totals = ['asset' => '0.00', 'liability' => '0.00', 'equity' => '0.00'];
        $earnings = '0.00';
        foreach (Account::where('is_postable', true)->orderBy('code')->get() as $a) {
            $s = $sums[$a->id] ?? null;
            if (! $s) {
                continue;
            }
            $type = $a->type->value;
            if (in_array($type, ['income', 'expense'], true)) {
                $earnings = Money::add($earnings, Money::sub($s->cr, $s->dr));

                continue;
            }
            $v = $type === 'asset' ? Money::sub($s->dr, $s->cr) : Money::sub($s->cr, $s->dr);
            $sections[$type][] = ['account' => $a, 'amount' => $v];
            $totals[$type] = Money::add($totals[$type], $v);
        }
        $sections['equity'][] = ['account' => null, 'label' => 'Current period earnings (unappropriated)', 'amount' => $earnings];
        $totals['equity'] = Money::add($totals['equity'], $earnings);
        $le = Money::add($totals['liability'], $totals['equity']);

        return ['sections' => $sections, 'totals' => $totals, 'liabilities_and_equity' => $le, 'balanced' => Money::eq($totals['asset'], $le)];
    }

    /** Cash-flow statement: movement in cash & bank accounts classified by the counterpart line's category. */
    public function cashFlow(string $from, string $to): array
    {
        $cashIds = Account::whereIn('system_role', ['operating_bank', 'disbursement_bank', 'collection_bank', 'safeguarded_bank', 'petty_cash'])->pluck('id')->all();
        $accounts = Account::pluck('cash_flow_category', 'id');
        $flows = ['operating' => '0.00', 'investing' => '0.00', 'financing' => '0.00'];
        $vouchers = $this->lines($from, $to)->whereIn('journal_lines.account_id', $cashIds)->distinct()->pluck('journal_lines.journal_voucher_id');
        foreach ($vouchers->chunk(500) as $chunk) {
            $lines = DB::table('journal_lines')->whereIn('journal_voucher_id', $chunk)->get()->groupBy('journal_voucher_id');
            foreach ($lines as $jvLines) {
                $cash = '0.00';
                $biggest = null;
                foreach ($jvLines as $l) {
                    if (in_array($l->account_id, $cashIds)) {
                        $cash = Money::add($cash, Money::sub($l->debit, $l->credit));
                    } elseif (! $biggest || Money::gt(Money::add($l->debit, $l->credit), Money::add($biggest->debit, $biggest->credit))) {
                        $biggest = $l;
                    }
                }
                $cat = $biggest ? ($accounts[$biggest->account_id] ?? 'operating') : 'operating';
                $flows[$cat ?: 'operating'] = Money::add($flows[$cat ?: 'operating'], $cash);
            }
        }
        $opening = $this->cashTotal($cashIds, null, date('Y-m-d', strtotime($from.' -1 day')));
        $net = Money::add(...array_values($flows));

        return ['flows' => $flows, 'net_change' => $net, 'opening_cash' => $opening, 'closing_cash' => Money::add($opening, $net)];
    }

    private function cashTotal(array $ids, ?string $from, string $to): string
    {
        $row = $this->lines($from, $to)->whereIn('journal_lines.account_id', $ids)->selectRaw('COALESCE(SUM(debit - credit),0) v')->first();

        return Money::of($row->v);
    }

    /** Ledger for one account with running balance. */
    public function ledger(Account $account, string $from, string $to): array
    {
        $opening = $this->accountBalance($account, date('Y-m-d', strtotime($from.' -1 day')));
        $rows = $this->lines($from, $to)->where('journal_lines.account_id', $account->id)
            ->orderBy('journal_vouchers.voucher_date')->orderBy('journal_vouchers.id')
            ->select('journal_lines.*', 'journal_vouchers.voucher_no', 'journal_vouchers.voucher_date', 'journal_vouchers.narration',
                'journal_vouchers.source_type', 'journal_vouchers.source_id', 'journal_vouchers.event_type', 'journal_vouchers.id as jv_id')->get();
        $bal = $opening;
        $out = [];
        foreach ($rows as $r) {
            $delta = $account->isDebitNormal() ? Money::sub($r->debit, $r->credit) : Money::sub($r->credit, $r->debit);
            $bal = Money::add($bal, $delta);
            $out[] = ['line' => $r, 'balance' => $bal];
        }

        return ['opening' => $opening, 'rows' => $out, 'closing' => $bal];
    }

    /** Budget vs actual by account for a budget. */
    public function budgetVsActual(\App\Models\Budget $budget): array
    {
        $rows = [];
        foreach ($budget->lines()->with(['account', 'period'])->get()->groupBy('account_id') as $accountId => $lines) {
            $account = $lines->first()->account;
            $budgeted = Money::add(...$lines->pluck('amount')->all());
            $from = $lines->min(fn ($l) => $l->period->start_date)->toDateString();
            $to = $lines->max(fn ($l) => $l->period->end_date)->min(today())->toDateString();
            $actual = $this->accountBalance($account, $to, $from);
            $rows[] = ['account' => $account, 'budget' => $budgeted, 'actual' => $actual, 'variance' => Money::sub($budgeted, $actual)];
        }

        return $rows;
    }
}
