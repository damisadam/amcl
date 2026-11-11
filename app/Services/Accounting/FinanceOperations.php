<?php

namespace App\Services\Accounting;

use App\Enums\ApprovalType;
use App\Exceptions\DomainException;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AssetDepreciation;
use App\Models\BankAccount;
use App\Models\CharityDisbursement;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\FixedAsset;
use App\Models\Fund;
use App\Models\FundTransfer;
use App\Models\LoanAccount;
use App\Models\LoanProvision;
use App\Models\PayrollRun;
use App\Models\ProfitDistribution;
use App\Models\SuspenseItem;
use App\Models\User;
use App\Models\WriteOff;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Audit\Auditor;
use App\Services\Support\Numbering;
use App\Services\Tenancy\TenantSettings;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FMS back-office operations (FMS §4–§12). Every money-relevant action either goes through the
 * finance approval hierarchy (maker-checker) or posts directly via configured posting rules.
 */
class FinanceOperations
{
    public function __construct(private GeneralLedger $gl, private ApprovalEngine $approvals) {}

    // -------------------------------------------------------------- expenses (FMS §9)

    public function raiseExpense(array $data, User $maker): ExpenseClaim
    {
        $amount = Money::of($data['amount'] ?? 0);
        if (! Money::isPositive($amount)) {
            throw new DomainException('Amount must be greater than zero.');
        }
        $account = Account::where('type', 'expense')->where('is_postable', true)->findOrFail($data['expense_account_id']);

        return DB::transaction(function () use ($data, $maker, $amount, $account) {
            $claim = new ExpenseClaim([
                'reference' => Numbering::next('expense'), 'kind' => $data['kind'] ?? 'expense', 'requested_by' => $maker->id,
                'branch_id' => $data['branch_id'] ?? $maker->branch_id, 'cost_center_id' => $data['cost_center_id'] ?? null,
                'expense_account_id' => $account->id, 'vendor_id' => $data['vendor_id'] ?? null, 'expense_date' => $data['expense_date'] ?? today(),
                'amount' => $amount, 'description' => $data['description'] ?? '',
            ]);
            $claim->forceFill(['status' => 'pending_approval'])->save();
            $this->approvals->submit($claim, ApprovalType::Expense, (string) $amount, $maker, branchId: $claim->branch_id);
            Auditor::record('fms.expense_raised', $claim, [], ['amount' => $amount]);

            return $claim;
        });
    }

    public function payExpense(ExpenseClaim $claim): void
    {
        $jv = $this->gl->postEvent('expense_payment', $claim, ['amount' => $claim->amount], "{$claim->reference} – {$claim->description}", $claim->expense_date,
            ['branch_id' => $claim->branch_id, 'cost_center_id' => $claim->cost_center_id], ['line:1' => $claim->expense_account_id], module: 'fms');
        $claim->forceFill(['status' => 'paid', 'journal_voucher_id' => $jv->id])->save();
    }

    // -------------------------------------------------------------- treasury (FMS §5)

    public function requestFundTransfer(BankAccount $from, BankAccount $to, string $amount, string $purpose, User $maker): FundTransfer
    {
        if ($from->id === $to->id) {
            throw new DomainException('Choose two different bank accounts.');
        }
        if ($from->purpose === 'safeguarded' || $to->purpose === 'safeguarded') {
            throw new DomainException('Safeguarded customer funds cannot be moved by internal fund transfer.');
        }
        $t = new FundTransfer(['reference' => Numbering::next('fund_transfer'), 'from_bank_account_id' => $from->id, 'to_bank_account_id' => $to->id,
            'amount' => Money::of($amount), 'purpose' => $purpose, 'requested_by' => $maker->id]);
        $t->forceFill(['status' => 'pending_approval'])->save();
        $this->approvals->submit($t, ApprovalType::FundTransfer, $t->amount, $maker);

        return $t;
    }

    public function completeFundTransfer(FundTransfer $t): void
    {
        $jv = $this->gl->createManual([
            'voucher_date' => today()->toDateString(), 'narration' => "Fund transfer {$t->reference}: {$t->purpose}",
        ], [
            ['account_id' => $t->toAccount->account_id, 'debit' => $t->amount, 'credit' => 0],
            ['account_id' => $t->fromAccount->account_id, 'debit' => 0, 'credit' => $t->amount],
        ], User::find($t->requested_by));
        $jv->forceFill(['source_type' => $t->getMorphClass(), 'source_id' => $t->id, 'event_type' => 'fund_transfer', 'origin' => 'system'])->save();
        $this->gl->post($jv);
        $t->forceFill(['status' => 'completed', 'journal_voucher_id' => $jv->id])->save();
    }

    public function recordFundReceipt(Fund $fund, string $amount, User $actor, ?Carbon $date = null): void
    {
        $role = in_array($fund->type, ['own_equity'], true) ? 'equity_capital' : ($fund->type === 'bank_line' ? 'bank_lines' : 'investor_funds');
        $this->gl->postEvent('fund_receipt', $fund, ['amount' => $amount], "Funding received – {$fund->name}", $date, ['fund_id' => $fund->id],
            ['line:2' => Account::where('system_role', $role)->value('id')], 'fund_receipt:'.$fund->id.':'.($date ?? now())->format('YmdHis'), 'treasury');
        Auditor::record('treasury.fund_receipt', $fund, [], ['amount' => $amount]);
    }

    /** Liquidity snapshot per bank account from posted GL balances (FMS §5.5). */
    public function bankBalances(): array
    {
        $reports = app(FinancialReports::class);

        return BankAccount::with('account')->where('is_active', true)->get()->map(fn ($b) => [
            'bank' => $b, 'balance' => $reports->accountBalance($b->account), 'below_minimum' => Money::lt($reports->accountBalance($b->account), $b->minimum_balance),
        ])->all();
    }

    // -------------------------------------------------------------- provisioning & write-off (FMS §4.4)

    public function runProvisioning(?Carbon $asOf = null): array
    {
        $asOf ??= today();
        $rates = TenantSettings::get('lending', 'provisioning_rates');
        $total = '0.00';
        foreach (LoanAccount::whereIn('status', ['active', 'overdue'])->get() as $loan) {
            $rate = (string) ($rates[$loan->classification] ?? 0);
            $required = Money::pct($loan->principal_outstanding, $rate);
            $prev = Money::of(LoanProvision::where('loan_account_id', $loan->id)->where('as_of', '<', $asOf->toDateString())->latest('as_of')->value('required_provision') ?? 0);
            $movement = Money::sub($required, $prev);
            if (LoanProvision::where('loan_account_id', $loan->id)->whereDate('as_of', $asOf)->exists()) {
                continue;
            }
            $prov = LoanProvision::create(['loan_account_id' => $loan->id, 'as_of' => $asOf, 'dpd' => $loan->dpd, 'classification' => $loan->classification,
                'rate' => $rate, 'outstanding' => $loan->principal_outstanding, 'required_provision' => $required, 'movement' => $movement]);
            if (! Money::isZero($movement)) {
                $event = Money::isPositive($movement) ? 'loan_provision' : 'loan_provision_release';
                $jv = $this->gl->postEvent($event, $prov, ['amount' => ltrim($movement, '-')], "Provision {$loan->account_no} ({$loan->classification})", $asOf,
                    ['branch_id' => $loan->branch_id, 'customer_id' => $loan->customer_id], module: 'fms');
                $prov->update(['journal_voucher_id' => $jv->id]);
                $total = Money::add($total, $movement);
            }
        }

        return ['net_movement' => $total];
    }

    public function requestWriteOff(LoanAccount $loan, string $reason, User $maker): WriteOff
    {
        if (! $loan->isOpen() || $loan->classification !== 'loss') {
            throw new DomainException('Only open financings classified as Loss can be proposed for write-off.');
        }
        $w = new WriteOff(['reference' => Numbering::next('write_off'), 'loan_account_id' => $loan->id, 'amount' => $loan->principal_outstanding,
            'reason' => $reason, 'requested_by' => $maker->id]);
        $w->forceFill(['status' => 'pending_approval'])->save();
        $this->approvals->submit($w, ApprovalType::WriteOff, (string) $w->amount, $maker);

        return $w;
    }

    public function completeWriteOff(WriteOff $w): void
    {
        $loan = LoanAccount::whereKey($w->loan_account_id)->lockForUpdate()->first();
        $provisioned = Money::of(LoanProvision::where('loan_account_id', $loan->id)->latest('as_of')->value('required_provision') ?? 0);
        $principal = Money::of($loan->principal_outstanding);
        $covered = Money::min($provisioned, $principal);
        // Unearned profit and financed charges still in the receivable are released against their deferred accounts.
        $jv = $this->gl->postEvent('loan_write_off', $w, ['principal' => $principal, 'provisioned' => $covered, 'excess' => Money::sub($principal, $covered),
            'profit' => $loan->profit_outstanding, 'charges' => $loan->charges_outstanding, 'unearned' => Money::add($loan->profit_outstanding, $loan->charges_outstanding)],
            "Write-off {$w->reference} – {$loan->account_no}", dimensions: ['branch_id' => $loan->branch_id, 'customer_id' => $loan->customer_id], module: 'fms');
        $loan->forceFill(['status' => \App\Enums\LoanAccountStatus::WrittenOff, 'closed_at' => now()])->save();
        $w->forceFill(['status' => 'approved', 'journal_voucher_id' => $jv->id])->save();
        Auditor::record('loan.written_off', $loan, [], ['amount' => $principal]);
        \App\Services\Lending\RepaymentService::lifecycleEvent($loan, 'Financing '.$loan->account_no.' written off ('.$w->reference.').', false);
    }

    // -------------------------------------------------------------- Islamic finance & charity (FMS §11)

    public function charityBalance(): string
    {
        return app(FinancialReports::class)->roleBalance('charity_payable');
    }

    public function requestCharityDisbursement(string $beneficiary, string $amount, string $purpose, User $maker): CharityDisbursement
    {
        if (Money::gt($amount, $this->charityBalance())) {
            throw new DomainException('Amount exceeds the charity fund balance ('.Money::format($this->charityBalance()).').');
        }
        $c = new CharityDisbursement(['reference' => Numbering::next('charity'), 'beneficiary' => $beneficiary, 'amount' => Money::of($amount), 'purpose' => $purpose, 'requested_by' => $maker->id]);
        $c->forceFill(['status' => 'pending_approval'])->save();
        $this->approvals->submit($c, ApprovalType::CharityDisbursement, (string) $c->amount, $maker);

        return $c;
    }

    public function completeCharity(CharityDisbursement $c): void
    {
        $jv = $this->gl->postEvent('charity_disbursement', $c, ['amount' => $c->amount], "Charity disbursement {$c->reference} to {$c->beneficiary}", module: 'fms');
        $c->forceFill(['status' => 'disbursed', 'journal_voucher_id' => $jv->id])->save();
    }

    /** Mudarabah-style investor profit share on profit income attributable to a fund for a period. */
    public function calculateProfitDistribution(Fund $fund, AccountingPeriod $period): ProfitDistribution
    {
        if (! $fund->investor_profit_share_pct) {
            throw new DomainException('This fund has no investor profit-sharing ratio configured.');
        }
        $profitAccount = Account::where('system_role', 'profit_income')->value('id');
        $gross = Money::of(DB::table('journal_lines')->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_lines.journal_voucher_id')
            ->where('journal_lines.tenant_id', $fund->tenant_id)->where('journal_lines.fund_id', $fund->id)->where('journal_lines.account_id', $profitAccount)
            ->whereIn('journal_vouchers.status', ['posted', 'reversed'])
            ->whereBetween('journal_vouchers.voucher_date', [$period->start_date, $period->end_date])
            ->selectRaw('COALESCE(SUM(journal_lines.credit - journal_lines.debit),0) as v')->value('v'));
        $share = Money::pct($gross, (string) $fund->investor_profit_share_pct);

        return ProfitDistribution::updateOrCreate(['fund_id' => $fund->id, 'accounting_period_id' => $period->id, 'status' => 'calculated'],
            ['gross_profit' => $gross, 'investor_share_pct' => $fund->investor_profit_share_pct, 'investor_share' => $share, 'wht_amount' => Money::pct($share, '15')]);
    }

    public function postProfitDistribution(ProfitDistribution $d): void
    {
        if ($d->status !== 'calculated' || ! Money::isPositive($d->investor_share)) {
            throw new DomainException('Nothing to post for this distribution.');
        }
        $jv = $this->gl->postEvent('profit_distribution', $d, ['gross' => $d->investor_share, 'net' => Money::sub($d->investor_share, $d->wht_amount), 'wht' => $d->wht_amount],
            "Investor profit distribution – {$d->fund->name} {$d->period->name}", dimensions: ['fund_id' => $d->fund_id], module: 'fms');
        $d->update(['status' => 'posted', 'journal_voucher_id' => $jv->id]);
    }

    // -------------------------------------------------------------- fixed assets (FMS §7)

    public function acquireAsset(array $data, User $actor): FixedAsset
    {
        $asset = FixedAsset::create($data + ['asset_code' => Numbering::next('asset', null, false, 5)]);
        $this->gl->postEvent('asset_purchase', $asset, ['amount' => $asset->cost], "Asset acquired {$asset->asset_code} – {$asset->name}", $asset->acquired_on,
            ['branch_id' => $asset->branch_id], module: 'fms');
        Auditor::record('fms.asset_acquired', $asset, [], ['cost' => $asset->cost]);

        return $asset;
    }

    public function runDepreciation(AccountingPeriod $period): string
    {
        $total = '0.00';
        foreach (FixedAsset::where('status', 'active')->whereDate('acquired_on', '<=', $period->end_date)->get() as $asset) {
            if (AssetDepreciation::where('fixed_asset_id', $asset->id)->where('accounting_period_id', $period->id)->exists()) {
                continue;
            }
            $depreciable = Money::sub($asset->cost, $asset->salvage_value);
            $remaining = Money::sub($depreciable, $asset->accumulated_depreciation);
            if (! Money::isPositive($remaining)) {
                continue;
            }
            $amount = Money::min(Money::div($depreciable, $asset->useful_life_months), $remaining);
            $dep = AssetDepreciation::create(['fixed_asset_id' => $asset->id, 'accounting_period_id' => $period->id, 'amount' => $amount]);
            $jv = $this->gl->postEvent('depreciation', $dep, ['amount' => $amount], "Depreciation {$asset->asset_code} {$period->name}", $period->end_date,
                ['branch_id' => $asset->branch_id], module: 'fms');
            $dep->update(['journal_voucher_id' => $jv->id]);
            $asset->update(['accumulated_depreciation' => Money::add($asset->accumulated_depreciation, $amount)]);
            $total = Money::add($total, $amount);
        }

        return $total;
    }

    public function disposeAsset(FixedAsset $asset, string $proceeds, User $actor): void
    {
        if ($asset->status !== 'active') {
            throw new DomainException('Asset already disposed.');
        }
        $nbv = Money::sub($asset->cost, $asset->accumulated_depreciation);
        $diff = Money::sub($proceeds, $nbv);
        $jv = $this->gl->postEvent('asset_disposal', $asset, [
            'proceeds' => $proceeds, 'accumulated' => $asset->accumulated_depreciation, 'cost' => $asset->cost,
            'gain' => Money::isPositive($diff) ? $diff : '0', 'loss' => Money::lt($diff, 0) ? ltrim($diff, '-') : '0',
        ], "Disposal of {$asset->asset_code} – {$asset->name}", idempotencyKey: 'asset_disposal:'.$asset->id, module: 'fms');
        $asset->update(['status' => 'disposed', 'disposed_on' => today(), 'disposal_proceeds' => $proceeds, 'journal_voucher_id' => $jv->id]);
        Auditor::record('fms.asset_disposed', $asset, [], ['proceeds' => $proceeds, 'gain_loss' => $diff]);
    }

    // -------------------------------------------------------------- payroll (FMS §8, internal records only)

    public function preparePayroll(string $period, User $maker): PayrollRun
    {
        if (PayrollRun::where('period', $period)->exists()) {
            throw new DomainException("Payroll for {$period} already exists.");
        }

        return DB::transaction(function () use ($period, $maker) {
            $run = new PayrollRun(['period' => $period, 'created_by' => $maker->id]);
            $run->forceFill(['status' => 'draft'])->save();
            $totals = ['gross' => '0.00', 'tax' => '0.00', 'ded' => '0.00', 'net' => '0.00'];
            foreach (Employee::where('status', 'active')->get() as $e) {
                $gross = Money::add($e->basic_salary, $e->allowances);
                $tax = Money::isPositive(Money::sub($gross, '100000')) ? Money::pct(Money::sub($gross, '100000'), '15') : '0.00';
                $pf = Money::pct($e->basic_salary, '8.33');
                $eobi = '370.00';
                $net = Money::sub(Money::sub(Money::sub($gross, $tax), $pf), $eobi);
                $run->lines()->create(['employee_id' => $e->id, 'basic' => $e->basic_salary, 'allowances' => $e->allowances, 'gross' => $gross,
                    'income_tax' => $tax, 'provident_fund' => $pf, 'eobi' => $eobi, 'net' => $net]);
                $totals['gross'] = Money::add($totals['gross'], $gross);
                $totals['tax'] = Money::add($totals['tax'], $tax);
                $totals['ded'] = Money::add($totals['ded'], $pf, $eobi);
                $totals['net'] = Money::add($totals['net'], $net);
            }
            $run->update(['total_gross' => $totals['gross'], 'total_tax' => $totals['tax'], 'total_deductions' => $totals['ded'], 'total_net' => $totals['net']]);
            $run->forceFill(['status' => 'pending_approval'])->save();
            $this->approvals->submit($run, ApprovalType::Payroll, $totals['gross'], $maker);

            return $run;
        });
    }

    public function postPayroll(PayrollRun $run): void
    {
        $jv = $this->gl->postEvent('payroll', $run, ['gross' => $run->total_gross, 'net' => $run->total_net, 'tax' => $run->total_tax, 'pf' => $run->total_deductions],
            "Payroll {$run->period}", Carbon::parse($run->period.'-01')->endOfMonth()->min(today()), module: 'fms');
        $run->forceFill(['status' => 'posted', 'journal_voucher_id' => $jv->id])->save();
    }

    // -------------------------------------------------------------- suspense (FMS §2.5)

    public function reclassifySuspense(SuspenseItem $item, int $accountId, string $notes, User $actor): void
    {
        if ($item->status !== 'open') {
            throw new DomainException('This suspense item is already resolved.');
        }
        $target = Account::where('is_postable', true)->findOrFail($accountId);
        $suspense = Account::where('system_role', 'suspense')->value('id');
        $jv = $this->gl->createManual(['voucher_date' => today()->toDateString(), 'narration' => "Suspense reclass {$item->reference}: {$notes}"], [
            ['account_id' => $suspense, 'debit' => $item->amount, 'credit' => 0],
            ['account_id' => $target->id, 'debit' => 0, 'credit' => $item->amount],
        ], $actor);
        $jv->forceFill(['origin' => 'system', 'source_type' => $item->getMorphClass(), 'source_id' => $item->id, 'event_type' => 'suspense_reclass'])->save();
        $this->gl->post($jv);
        $item->update(['status' => 'reclassified', 'reclass_journal_id' => $jv->id, 'resolved_by' => $actor->id, 'resolution_notes' => $notes]);
        Auditor::record('fms.suspense_reclassified', $item, [], ['to_account' => $target->code], $notes);
    }
}
