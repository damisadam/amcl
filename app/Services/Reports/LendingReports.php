<?php

namespace App\Services\Reports;

use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\Disbursement;
use App\Models\FieldVisit;
use App\Models\Instalment;
use App\Models\LoanAccount;
use App\Models\LoanApplication;
use App\Models\LoanPayment;
use App\Models\RecoveryContact;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lending MIS (LMS dashboards / reports). All queries use tenant-scoped Eloquent models.
 * Filters: from, to (dates), branch_id, product_id.
 */
class LendingReports
{
    public function __construct(private array $f = []) {}

    private function from(): string { return $this->f['from'] ?? now()->subMonths(6)->startOfMonth()->toDateString(); }
    private function to(): string { return $this->f['to'] ?? today()->toDateString(); }

    private function scopeApps(Builder $q): Builder
    {
        return $q->when($this->f['branch_id'] ?? null, fn ($w, $b) => $w->where('loan_applications.branch_id', $b))
            ->when($this->f['product_id'] ?? null, fn ($w, $p) => $w->where('loan_applications.product_id', $p));
    }

    public function funnel(): array
    {
        $base = fn () => $this->scopeApps(LoanApplication::query())->whereBetween('loan_applications.created_at', [$this->from(), $this->to().' 23:59:59']);

        return [
            'by_status' => $base()->selectRaw('status, COUNT(*) n, SUM(requested_amount) amount')->groupBy('status')->orderByDesc('n')->get()
                ->map(fn ($r) => ['status' => self::v($r->status), 'count' => (int) $r->n, 'amount' => Money::of($r->amount)])->all(),
            'by_channel' => $base()->selectRaw('channel, COUNT(*) n')->groupBy('channel')->pluck('n', 'channel')->all(),
            'by_product' => $base()->join('products', 'products.id', '=', 'loan_applications.product_id')
                ->selectRaw('products.name, COUNT(*) n, SUM(CASE WHEN loan_applications.status = "disbursed" THEN 1 ELSE 0 END) disbursed')
                ->groupBy('products.name')->get()->map(fn ($r) => ['product' => $r->name, 'count' => (int) $r->n, 'disbursed' => (int) $r->disbursed])->all(),
        ];
    }

    public function turnaround(): array
    {
        $apps = $this->scopeApps(LoanApplication::query())->whereNotNull('approved_at')->whereNotNull('submitted_at')
            ->whereBetween('approved_at', [$this->from(), $this->to().' 23:59:59'])->get(['submitted_at', 'approved_at']);
        $avg = $apps->isEmpty() ? null : round($apps->avg(fn ($a) => $a->submitted_at->diffInMinutes($a->approved_at) / 60), 1);

        $stages = [];
        $requests = ApprovalRequest::where('type', 'loan')->whereBetween('created_at', [$this->from(), $this->to().' 23:59:59'])->pluck('id');
        foreach ($requests->chunk(500) as $chunk) {
            $actions = ApprovalAction::whereIn('approval_request_id', $chunk)->orderBy('approval_request_id')->orderBy('id')->get();
            foreach ($actions->groupBy('approval_request_id') as $list) {
                $prev = null;
                foreach ($list as $a) {
                    if ($prev && in_array($a->action, ['approve', 'reject', 'return'], true)) {
                        $s = $a->stage_name ?? 'Stage';
                        $stages[$s]['hours'][] = $prev->created_at->diffInMinutes($a->created_at) / 60;
                        $stages[$s][$a->action] = ($stages[$s][$a->action] ?? 0) + 1;
                    }
                    $prev = $a;
                }
            }
        }
        $perStage = collect($stages)->map(fn ($s, $name) => ['stage' => $name, 'avg_hours' => round(array_sum($s['hours']) / max(1, count($s['hours'])), 1),
            'approved' => $s['approve'] ?? 0, 'rejected' => $s['reject'] ?? 0, 'returned' => $s['return'] ?? 0])->values()->all();

        return ['avg_hours' => $avg, 'count' => $apps->count(), 'stages' => $perStage];
    }

    public function decisions(): array
    {
        $q = fn () => $this->scopeApps(LoanApplication::query());
        $approved = $q()->whereBetween('approved_at', [$this->from(), $this->to().' 23:59:59'])->count();
        $rejected = $q()->whereBetween('rejected_at', [$this->from(), $this->to().' 23:59:59'])->count();
        $reasons = $q()->whereBetween('rejected_at', [$this->from(), $this->to().' 23:59:59'])->selectRaw('COALESCE(rejection_code, "other") code, COUNT(*) n')
            ->groupBy('code')->pluck('n', 'code')->all();

        return ['approved' => $approved, 'rejected' => $rejected, 'approval_rate' => ($approved + $rejected) ? round($approved * 100 / ($approved + $rejected), 1) : null, 'reasons' => $reasons];
    }

    public function disbursements(): array
    {
        $q = Disbursement::query()->where('disbursements.status', 'succeeded')->whereBetween('disbursements.completed_at', [$this->from(), $this->to().' 23:59:59'])
            ->join('loan_applications', 'loan_applications.id', '=', 'disbursements.loan_application_id')
            ->when($this->f['branch_id'] ?? null, fn ($w, $b) => $w->where('loan_applications.branch_id', $b))
            ->when($this->f['product_id'] ?? null, fn ($w, $p) => $w->where('loan_applications.product_id', $p));

        return [
            'by_month' => (clone $q)->selectRaw("DATE_FORMAT(disbursements.completed_at, '%Y-%m') m, COUNT(*) n, SUM(disbursements.gross_amount) amount")->groupBy('m')->orderBy('m')->get()
                ->map(fn ($r) => ['month' => $r->m, 'count' => (int) $r->n, 'amount' => Money::of($r->amount)])->all(),
            'by_product' => (clone $q)->join('products', 'products.id', '=', 'loan_applications.product_id')->selectRaw('products.name p, COUNT(*) n, SUM(disbursements.gross_amount) amount')
                ->groupBy('p')->get()->map(fn ($r) => ['product' => $r->p, 'count' => (int) $r->n, 'amount' => Money::of($r->amount)])->all(),
            'by_branch' => (clone $q)->leftJoin('branches', 'branches.id', '=', 'loan_applications.branch_id')->selectRaw('COALESCE(branches.name, "—") b, COUNT(*) n, SUM(disbursements.gross_amount) amount')
                ->groupBy('b')->get()->map(fn ($r) => ['branch' => $r->b, 'count' => (int) $r->n, 'amount' => Money::of($r->amount)])->all(),
        ];
    }

    private function openLoans(): Builder
    {
        return LoanAccount::query()->whereIn('loan_accounts.status', ['active', 'overdue'])
            ->when($this->f['branch_id'] ?? null, fn ($w, $b) => $w->where('loan_accounts.branch_id', $b))
            ->when($this->f['product_id'] ?? null, fn ($w, $p) => $w->where('loan_accounts.product_id', $p));
    }

    public function portfolio(string $by = 'product'): array
    {
        [$join, $col] = match ($by) {
            'branch' => ['branches', 'branches.name'],
            'officer' => ['users', 'users.name'],
            default => ['products', 'products.name'],
        };
        $fk = ['branches' => 'branch_id', 'users' => 'loan_officer_id', 'products' => 'product_id'][$join];

        return $this->openLoans()->leftJoin($join, "$join.id", '=', "loan_accounts.$fk")
            ->selectRaw("COALESCE($col, '—') k, COUNT(*) n, SUM(loan_accounts.principal) disbursed, SUM(loan_accounts.principal_outstanding) principal,
                SUM(loan_accounts.principal_outstanding + loan_accounts.profit_outstanding + loan_accounts.charges_outstanding + loan_accounts.late_charges_outstanding) total,
                SUM(CASE WHEN loan_accounts.dpd > 30 THEN loan_accounts.principal_outstanding ELSE 0 END) par30")
            ->groupBy('k')->orderByDesc('principal')->get()
            ->map(fn ($r) => ['key' => $r->k, 'count' => (int) $r->n, 'disbursed' => Money::of($r->disbursed), 'principal' => Money::of($r->principal),
                'total' => Money::of($r->total), 'par30' => Money::of($r->par30)])->all();
    }

    public function par(): array
    {
        $rows = $this->openLoans()->get(['dpd', 'principal_outstanding']);
        $total = Money::add('0', ...$rows->pluck('principal_outstanding')->all());
        $bucket = fn ($min, $max = null) => $rows->filter(fn ($l) => $l->dpd >= $min && ($max === null || $l->dpd <= $max));
        $sum = fn ($c) => Money::add('0', ...$c->pluck('principal_outstanding')->all());
        $pct = fn ($v) => Money::isPositive($total) ? round((float) $v * 100 / (float) $total, 2) : 0.0;
        $buckets = [];
        foreach (['Current' => [0, 0], '1–30' => [1, 30], '31–60' => [31, 60], '61–90' => [61, 90], '90+' => [91, null]] as $label => [$a, $b]) {
            $c = $bucket($a, $b);
            $buckets[] = ['bucket' => $label, 'count' => $c->count(), 'outstanding' => $sum($c), 'pct' => $pct($sum($c))];
        }
        $parN = fn ($n) => ['amount' => $sum($bucket($n)), 'pct' => $pct($sum($bucket($n)))];

        return ['total' => $total, 'count' => $rows->count(), 'buckets' => $buckets, 'par1' => $parN(1), 'par30' => $parN(31), 'par60' => $parN(61), 'par90' => $parN(91)];
    }

    public function collections(): array
    {
        $loanIds = fn () => LoanAccount::query()->when($this->f['branch_id'] ?? null, fn ($w, $b) => $w->where('branch_id', $b))
            ->when($this->f['product_id'] ?? null, fn ($w, $p) => $w->where('product_id', $p))->select('id');
        $due = Instalment::whereIn('loan_account_id', $loanIds())->whereBetween('due_date', [$this->from(), $this->to()])
            ->selectRaw("DATE_FORMAT(due_date, '%Y-%m') m, SUM(total_due + late_charge_due) due, SUM(total_paid) paid")->groupBy('m')->get()->keyBy('m');
        $collected = LoanPayment::whereIn('loan_account_id', $loanIds())->where('status', 'succeeded')->whereBetween('received_at', [$this->from(), $this->to().' 23:59:59'])
            ->selectRaw("DATE_FORMAT(received_at, '%Y-%m') m, SUM(amount) amt, COUNT(*) n")->groupBy('m')->get()->keyBy('m');
        $months = $due->keys()->merge($collected->keys())->unique()->sort()->values();

        return $months->map(function ($m) use ($due, $collected) {
            $d = Money::of($due[$m]->due ?? 0);
            $paidOnDue = Money::of($due[$m]->paid ?? 0);

            return ['month' => $m, 'due' => $d, 'paid_against_due' => $paidOnDue, 'collected' => Money::of($collected[$m]->amt ?? 0),
                'payments' => (int) ($collected[$m]->n ?? 0), 'efficiency' => Money::isPositive($d) ? round((float) $paidOnDue * 100 / (float) $d, 1) : null];
        })->all();
    }

    public function timeliness(): array
    {
        $paid = Instalment::where('status', 'paid')->whereNotNull('paid_at')->whereBetween('due_date', [$this->from(), $this->to()])->get(['due_date', 'paid_at']);
        $early = $paid->filter(fn ($i) => $i->paid_at->lt($i->due_date))->count();
        $onTime = $paid->filter(fn ($i) => $i->paid_at->isSameDay($i->due_date))->count();
        $late = $paid->count() - $early - $onTime;
        $unpaidPastDue = Instalment::where('status', '!=', 'paid')->where('due_date', '<', today())->whereBetween('due_date', [$this->from(), $this->to()])->count();

        return ['early' => $early, 'on_time' => $onTime, 'late' => $late, 'unpaid_past_due' => $unpaidPastDue];
    }

    public function closures(): array
    {
        return [
            'closed' => LoanAccount::whereBetween('closed_at', [$this->from(), $this->to().' 23:59:59'])->whereIn('status', ['closed', 'noc_issued'])->count(),
            'noc_issued' => LoanAccount::whereBetween('noc_issued_at', [$this->from(), $this->to().' 23:59:59'])->count(),
            'awaiting_noc' => LoanAccount::where('status', 'closed')->count(),
            'written_off' => LoanAccount::where('status', 'written_off')->whereBetween('closed_at', [$this->from(), $this->to().' 23:59:59'])->count(),
        ];
    }

    public function visits(): array
    {
        return FieldVisit::whereBetween('scheduled_for', [$this->from(), $this->to()])->selectRaw('visit_type, status, COALESCE(recommendation, "—") rec, COUNT(*) n')
            ->groupBy('visit_type', 'status', 'rec')->get()->map(fn ($r) => ['type' => $r->visit_type, 'status' => self::v($r->status), 'recommendation' => $r->rec, 'count' => (int) $r->n])->all();
    }

    public function recovery(): array
    {
        $q = fn () => RecoveryContact::whereBetween('contacted_at', [$this->from(), $this->to().' 23:59:59']);

        return [
            'outcomes' => $q()->selectRaw('outcome, COUNT(*) n')->groupBy('outcome')->pluck('n', 'outcome')->all(),
            'ptp' => $q()->whereNotNull('ptp_status')->selectRaw('ptp_status, COUNT(*) n, SUM(ptp_amount) amt')->groupBy('ptp_status')->get()
                ->map(fn ($r) => ['status' => $r->ptp_status, 'count' => (int) $r->n, 'amount' => Money::of($r->amt)])->all(),
        ];
    }

    public static function periodLabel(?string $from, ?string $to): string
    {
        return Carbon::parse($from ?? now()->subMonths(6)->startOfMonth())->format('d M Y').' – '.Carbon::parse($to ?? today())->format('d M Y');
    }

    private static function v(mixed $x): mixed
    {
        return $x instanceof \BackedEnum ? $x->value : $x;
    }
}
