<?php

namespace App\Livewire\Ops;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\FieldVisit;
use App\Models\Instalment;
use App\Models\LoanAccount;
use App\Models\LoanApplication;
use App\Models\LoanPayment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Policies\RecordAccess;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/** Role-aware operational dashboard. Every figure is a live database query (no stored totals). */
#[Layout('layouts.ops')]
class Dashboard extends Component
{
    public function mount(): void
    {
        $u = auth()->user();
        abort_unless($u->hasAnyPermission(['dashboard.view', 'field_visits.perform', 'recovery.work']), 403);
    }

    #[On('realtime')]
    public function refreshDashboard(): void {}

    public function render()
    {
        $u = auth()->user();
        $data = ['user' => $u, 'cards' => [], 'charts' => [], 'performance' => [], 'tasks' => [], 'greetName' => $this->greetName($u)];

        if (! $u->hasPermission('dashboard.view')) {
            return view('livewire.ops.dashboard.index', $data + ['taskView' => true] + $this->taskData($u));
        }

        if ($u->hasPermission('applications.view')) {
            $apps = RecordAccess::applicationsQuery(LoanApplication::query(), $u);
            $byStatus = (clone $apps)->select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status');
            $data['charts']['applications'] = $byStatus->mapWithKeys(fn ($c, $s) => [\App\Enums\ApplicationStatus::from($s)->label() => $c])->sortDesc()->all();
            $month = now()->startOfMonth();
            $data['cards'][] = ['In review / approval', (string) (($byStatus['submitted'] ?? 0) + ($byStatus['under_review'] ?? 0) + ($byStatus['pending_approval'] ?? 0)), 'Submitted, under review & pending approval', 'amber', route('ops.applications.index')];
            $data['cards'][] = ['Approved this month', (string) (clone $apps)->where('approved_at', '>=', $month)->count(), 'Rejected: '.(clone $apps)->where('rejected_at', '>=', $month)->count(), 'green', null];
            $tat = ApprovalRequest::where('type', 'loan')->where('status', 'approved')->whereNotNull('completed_at')
                ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at)) h')->value('h');
            $data['cards'][] = ['Approval turnaround', $tat !== null ? number_format((float) $tat, 1).' h' : '—', 'Average submission → final approval', 'purple', null];
        }

        if ($u->hasPermission('approvals.act')) {
            $roleIds = $u->roles->pluck('id');
            $mine = ApprovalRequest::where('status', ApprovalStatus::Pending->value)
                ->where(fn ($q) => $q->where('assigned_to', $u->id)->orWhere(fn ($w) => $w->whereNull('assigned_to')->whereHas('currentStage', fn ($s) => $s->whereIn('role_id', $roleIds))))
                ->with('currentStage')->get();
            $data['cards'][] = ['My approval tasks', (string) $mine->count(), $mine->filter->isOverdue()->count().' overdue SLA', $mine->filter->isOverdue()->count() ? 'red' : 'amber', route('ops.approvals.index')];
        }

        if ($u->hasPermission('loans.view')) {
            $loans = RecordAccess::loansQuery(LoanAccount::query(), $u);
            $open = (clone $loans)->whereIn('status', ['active', 'overdue']);
            $outstanding = Money::of((clone $open)->sum('principal_outstanding'));
            $par30 = Money::of((clone $open)->where('dpd', '>', 30)->sum('principal_outstanding'));
            $parPct = Money::isPositive($outstanding) ? number_format((float) bcdiv(bcmul($par30, '100', 4), $outstanding, 4), 2) : '0.00';
            $data['cards'][] = ['Active financings', (string) (clone $open)->count(), 'Closed: '.(clone $loans)->whereIn('status', ['closed', 'noc_issued'])->count(), 'green', \Illuminate\Support\Facades\Route::has('ops.loans.index') ? route('ops.loans.index') : null];
            $data['cards'][] = ['Outstanding principal', Money::format($outstanding), 'Portfolio at risk >30d: '.$parPct.'%', 'black', null];
            $data['cards'][] = ['Overdue accounts', (string) (clone $loans)->where('status', 'overdue')->count(), 'PAR30 '.Money::format($par30), 'red', null];

            $loanIds = (clone $loans)->select('id');
            $dueToday = Instalment::whereIn('loan_account_id', $loanIds)->whereDate('due_date', today())->where('status', '!=', 'paid')->count();
            $monthDue = Money::of(Instalment::whereIn('loan_account_id', $loanIds)->whereBetween('due_date', [now()->startOfMonth(), now()->endOfMonth()])->sum('total_due'));
            // M12: cash received this month (any instalment) and how much of THIS month's dues is already settled.
            $monthCollected = Money::of(LoanPayment::whereIn('loan_account_id', $loanIds)->where('status', 'succeeded')
                ->whereBetween('received_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'));
            $monthDuePaid = Money::of(Instalment::whereIn('loan_account_id', $loanIds)->whereBetween('due_date', [now()->startOfMonth(), now()->endOfMonth()])->sum('total_paid'));
            $pct = Money::isPositive($monthDue) ? number_format(min(100, (float) bcdiv(bcmul($monthDuePaid, '100', 4), $monthDue, 4)), 0) : '0';
            $data['cards'][] = ['Collections this month', Money::format($monthCollected), Money::format($monthDuePaid).' of '.Money::format($monthDue).' due this month paid ('.$pct.'%) · due today: '.$dueToday, 'green', null];

            $data['performance']['product'] = (clone $loans)->join('products', 'products.id', '=', 'loan_accounts.product_id')
                ->groupBy('products.name')->selectRaw('products.name label, count(*) loans, SUM(loan_accounts.principal) disbursed, SUM(CASE WHEN loan_accounts.status IN ("active","overdue") THEN loan_accounts.principal_outstanding ELSE 0 END) outstanding, SUM(CASE WHEN loan_accounts.dpd > 30 THEN 1 ELSE 0 END) par')
                ->orderByDesc('disbursed')->get();
            $data['performance']['branch'] = (clone $loans)->join('branches', 'branches.id', '=', 'loan_accounts.branch_id')
                ->groupBy('branches.name')->selectRaw('branches.name label, count(*) loans, SUM(loan_accounts.principal) disbursed, SUM(CASE WHEN loan_accounts.status IN ("active","overdue") THEN loan_accounts.principal_outstanding ELSE 0 END) outstanding, SUM(CASE WHEN loan_accounts.dpd > 30 THEN 1 ELSE 0 END) par')
                ->orderByDesc('disbursed')->get();
            // M12: always six months ending with the current one (zero-filled), so the current month is never missing.
            $byMonth = (clone $loans)->where('disbursed_at', '>=', now()->subMonths(5)->startOfMonth())
                ->selectRaw("DATE_FORMAT(disbursed_at, '%Y-%m') m, SUM(principal) v")->groupBy('m')->pluck('v', 'm');
            $data['charts']['disbursements'] = collect(range(5, 0))->mapWithKeys(fn ($i) => [$k = now()->subMonths($i)->format('Y-m') => (float) ($byMonth[$k] ?? 0)])->all();
        }

        if ($u->hasPermission('wallet.view') && $u->tenant?->hasFeature('wallet')) {
            $data['cards'][] = ['Wallet balances (liability)', Money::format(Wallet::sum('balance')), Wallet::where('status', 'active')->count().' active wallets', 'blue', null];
            $today = WalletTransaction::where('created_at', '>=', today())->where('status', 'succeeded');
            $data['cards'][] = ['Wallet volume today', Money::format(Money::of((clone $today)->sum('amount'))), (clone $today)->count().' successful · '.WalletTransaction::where('status', 'pending')->count().' pending', 'blue', null];
        }

        // M12: branch roles (no view-all) see only their own branch's customer-facing counts.
        $branchScoped = ! $u->hasPermission('applications.view_all') && $u->branch_id && ! $u->branch?->is_head_office;
        $customerScope = fn ($q) => $branchScoped ? $q->where('branch_id', $u->branch_id) : $q;
        if ($u->hasPermission('complaints.view')) {
            $open = Complaint::whereNotIn('status', ['resolved', 'closed'])->when($branchScoped, fn ($q) => $q->whereHas('customer', $customerScope));
            $data['cards'][] = ['Open complaints', (string) (clone $open)->count(), (clone $open)->where('sla_due_at', '<', now())->count().' past SLA', 'amber', null];
        }
        if ($u->hasAnyPermission(['kyc.review', 'kyc.view'])) {
            $pending = $customerScope(Customer::where('kyc_status', 'pending_review'))->count();
            $hint = $u->hasPermission('aml.view') ? $customerScope(Customer::where('aml_status', 'potential_match'))->count().' AML potential matches' : 'Identity manual reviews';
            $data['cards'][] = ['KYC pending review', (string) $pending, $hint, 'amber', route('ops.kyc.index')];
        }

        return view('livewire.ops.dashboard.index', $data + ['taskView' => false] + $this->taskData($u));
    }

    /** M12: greet the person (employee record first), never an organisation-style account name such as "AMCL Administrator". */
    private function greetName($u): string
    {
        $name = trim($u->employee?->name ?: $u->name);
        $first = \Illuminate\Support\Str::before($name, ' ');
        $orgLike = strcasecmp($first, (string) $u->tenant?->name) === 0 || strcasecmp($first, (string) $u->tenant?->code) === 0
            || ($first === mb_strtoupper($first) && mb_strlen($first) > 1);

        return $orgLike ? $name : $first;
    }

    private function taskData($u): array
    {
        $out = ['myVisits' => collect(), 'myCases' => collect()];
        if ($u->hasPermission('field_visits.perform')) {
            $out['myVisits'] = FieldVisit::with('customer')->where('assigned_to', $u->id)->whereIn('status', ['scheduled', 'in_progress'])
                ->orderBy('scheduled_for')->take(10)->get();
        }
        if ($u->hasPermission('recovery.work')) {
            $out['myCases'] = LoanAccount::with('customer')->where('recovery_agent_id', $u->id)->whereIn('status', ['active', 'overdue'])->orderByDesc('dpd')->take(10)->get();
        }

        return $out;
    }
}
