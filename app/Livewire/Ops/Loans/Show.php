<?php

namespace App\Livewire\Ops\Loans;

use App\Livewire\Concerns\HandlesActions;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\JournalVoucher;
use App\Models\LoanAccount;
use App\Models\LoanPayment;
use App\Models\WriteOff;
use App\Policies\RecordAccess;
use App\Services\Lending\RepaymentService;
use App\Services\Lending\ServicingService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.ops')]
class Show extends Component
{
    use HandlesActions;

    public LoanAccount $loan;

    #[Url]
    public string $tab = 'summary';

    public bool $showPay = false;

    public string $payAmount = '';

    public string $payChannel = 'cash';

    public string $payRef = '';

    public string $payDate = '';

    public bool $showReverse = false;

    public ?int $reverseId = null;

    public string $reverseReason = '';

    public ?int $allocationId = null;

    public bool $showNoc = false;

    public function mount(LoanAccount $loan): void
    {
        $this->need('loans.view', 'recovery.view', 'recovery.work');
        abort_unless(RecordAccess::canViewLoan(auth()->user(), $loan), 403);
        $this->loan = $loan;
        $this->payDate = today()->toDateString();
    }

    #[On('realtime')]
    public function refreshLoan(): void
    {
        $this->loan->refresh();
    }

    public function openPay(): void
    {
        $this->need('repayments.record');
        $next = $this->loan->nextInstalment();
        $this->payAmount = $next ? \App\Support\Money::sub(\App\Support\Money::add($next->total_due, $next->late_charge_due), $next->total_paid) : '';
        $this->payRef = '';
        $this->showPay = true;
    }

    public function recordPayment(RepaymentService $svc): void
    {
        $this->need('repayments.record');
        $this->validate([
            'payAmount' => 'required|numeric|min:1',
            'payChannel' => 'required|in:'.implode(',', RepaymentService::MANUAL_CHANNELS),
            'payRef' => 'required|string|max:60',
            'payDate' => 'required|date|before_or_equal:today',
        ]);
        if ($this->attempt(fn () => $svc->recordManual($this->loan, (string) $this->payAmount, $this->payChannel, $this->payRef, auth()->user(), $this->payDate), __('Repayment recorded and allocated.'))) {
            $this->showPay = false;
            $this->loan->refresh();
        }
    }

    public function openReverse(int $id): void
    {
        $this->need('repayments.reverse');
        $this->reverseId = $id;
        $this->reverseReason = '';
        $this->showReverse = true;
    }

    public function reverse(RepaymentService $svc): void
    {
        $this->need('repayments.reverse');
        $this->validate(['reverseReason' => 'required|string|min:5|max:500']);
        $payment = LoanPayment::where('loan_account_id', $this->loan->id)->findOrFail($this->reverseId);
        if ($this->attempt(fn () => $svc->reverse($payment, $this->reverseReason, auth()->user()), __('Repayment reversed; balances restored and GL reversed.'))) {
            $this->showReverse = false;
            $this->loan->refresh();
        }
    }

    public function issueNoc(ServicingService $svc): void
    {
        $this->need('loans.issue_noc');
        if ($this->attempt(fn () => $svc->issueNoc($this->loan, auth()->user()), __('NOC issued.'))) {
            $this->showNoc = false;
            $this->loan->refresh();
        }
    }

    public function render()
    {
        $loan = $this->loan->load(['customer', 'product', 'productVersion', 'branch', 'fund', 'vendor', 'officer', 'recoveryAgent', 'application']);
        $user = auth()->user();
        $data = ['loan' => $loan, 'sensitive' => $user->hasPermission('customers.view_sensitive')];
        $data['next'] = $loan->nextInstalment();
        $data['overdue'] = $loan->overdueAmount();
        $data['writeOff'] = WriteOff::where('loan_account_id', $loan->id)->latest('id')->first();

        if ($this->tab === 'schedule') {
            $data['instalments'] = $loan->instalments()->get();
        }
        if ($this->tab === 'payments') {
            $data['payments'] = $loan->payments()->with('recorder', 'allocations.instalment')->get();
            $data['vouchers'] = JournalVoucher::where('source_type', (new LoanPayment)->getMorphClass())->whereIn('source_id', $data['payments']->pluck('id'))->get()->keyBy('source_id');
        }
        if ($this->tab === 'recovery') {
            $data['contacts'] = $loan->recoveryContacts()->with('agent')->get();
        }
        if ($this->tab === 'documents') {
            $data['documents'] = Document::with('type')->where(function ($q) use ($loan) {
                $q->where(fn ($w) => $w->where('documentable_type', $loan->getMorphClass())->where('documentable_id', $loan->id))
                  ->orWhere(fn ($w) => $w->where('documentable_type', $loan->application->getMorphClass())->where('documentable_id', $loan->loan_application_id));
            })->latest('id')->get()->filter(fn ($d) => RecordAccess::canDownload($user, $d));
        }
        if ($this->tab === 'audit') {
            $this->need('audit.view', 'loans.manage');
            $data['audits'] = AuditLog::with('actor')->where('tenant_id', $loan->tenant_id)->where(function ($q) use ($loan) {
                $q->where(fn ($w) => $w->where('auditable_type', $loan->getMorphClass())->where('auditable_id', $loan->id))
                  ->orWhere(fn ($w) => $w->where('auditable_type', (new LoanPayment)->getMorphClass())->whereIn('auditable_id', $loan->payments()->pluck('id')));
            })->latest('id')->limit(200)->get();
        }
        if ($this->allocationId) {
            $data['allocation'] = LoanPayment::with('allocations.instalment')->where('loan_account_id', $loan->id)->find($this->allocationId);
        }

        return view('livewire.ops.loans.show', $data);
    }
}
