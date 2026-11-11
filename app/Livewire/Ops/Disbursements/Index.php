<?php

namespace App\Livewire\Ops\Disbursements;

use App\Enums\ApplicationStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Disbursement;
use App\Models\LoanApplication;
use App\Policies\RecordAccess;
use App\Services\Lending\DisbursementService;
use App\Services\Products\FinancingCalculator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    #[Url]
    public string $tab = 'ready';

    public string $filterStatus = '';

    public string $filterFrom = '';

    public string $filterTo = '';

    public ?int $confirmId = null;

    public bool $showConfirm = false;

    public string $scenario = 'success';

    public function mount(): void
    {
        $this->need('disbursements.view');
    }

    #[On('realtime')]
    public function refreshList(): void {}

    public function confirm(int $id): void
    {
        $this->need('disbursements.initiate');
        $this->confirmId = $id;
        $this->scenario = 'success';
        $this->showConfirm = true;
    }

    /** Maker: request the payout (nothing is sent until a different user releases it). */
    public function initiate(DisbursementService $svc): void
    {
        $this->need('disbursements.initiate');
        $app = LoanApplication::findOrFail($this->confirmId);
        abort_unless(RecordAccess::canViewApplication(auth()->user(), $app), 403);
        $scenario = \App\Services\Payments\Simulation::scenario($this->scenario, 'disbursement');
        $d = $this->attempt(fn () => $svc->initiate($app, auth()->user(), $scenario));
        if ($d) {
            $this->toast(__('Payout :r requested. A second authorised user must release it (In progress tab).', ['r' => $d->reference]));
            $this->showConfirm = false;
        }
    }

    public ?int $releaseId = null;

    public bool $showRelease = false;

    public string $declineReason = '';

    public function openRelease(int $id): void
    {
        $this->need('disbursements.initiate');
        $this->releaseId = $id;
        $this->declineReason = '';
        $this->showRelease = true;
    }

    /** Checker: release the requested payout to the provider. */
    public function release(DisbursementService $svc): void
    {
        $this->need('disbursements.initiate');
        if (! $this->stepUp()) {
            return;
        }
        $d = $this->releasable();
        $d = $this->attempt(fn () => $svc->release($d, auth()->user()));
        if ($d) {
            $this->toast($d->status->value === 'succeeded'
                ? __('Disbursement :r succeeded – loan account created.', ['r' => $d->reference])
                : __('Disbursement :r is :s.', ['r' => $d->reference, 's' => $d->status->label()]), $d->status->value === 'failed' ? 'red' : 'green');
            $this->showRelease = false;
        }
    }

    public function decline(DisbursementService $svc): void
    {
        $this->need('disbursements.initiate');
        $this->validate(['declineReason' => 'required|string|min:5|max:500'], [], ['declineReason' => __('reason')]);
        $d = $this->releasable();
        if ($this->attempt(fn () => $svc->declineRequest($d, auth()->user(), $this->declineReason), __('Payout request declined; the case is back in the queue.'))) {
            $this->showRelease = false;
        }
    }

    private function releasable(): Disbursement
    {
        $d = Disbursement::with('application')->findOrFail($this->releaseId);
        abort_unless(RecordAccess::canViewApplication(auth()->user(), $d->application), 403);

        return $d;
    }

    public function render(DisbursementService $svc, FinancingCalculator $calc)
    {
        $user = auth()->user();
        $ready = collect();
        $preview = null;
        if ($this->tab === 'ready') {
            $q = RecordAccess::applicationsQuery(LoanApplication::query(), $user)
                ->with(['customer', 'product', 'productVersion.charges', 'vendor', 'branch', 'disbursements'])
                ->whereIn('status', [ApplicationStatus::ReadyForDisbursement->value, ApplicationStatus::CoolingOff->value]);
            $this->applySearch($q, ['application_no']);
            $ready = $q->orderBy('cooling_off_ends_at')->paginate($this->perPage);
            $ready->getCollection()->each(fn ($a) => $a->setAttribute('blockers', $svc->blockers($a)));
        }
        if ($this->confirmId && ($app = LoanApplication::with('productVersion.charges', 'vendor', 'customer.linkedBankAccounts', 'customer.wallet')->find($this->confirmId))) {
            $preview = ['app' => $app, 'quote' => $calc->quote($app->productVersion, (string) $app->financedAmount(), (int) $app->tenure)];
        }

        $history = null;
        if ($this->tab !== 'ready') {
            $q = Disbursement::with(['application.customer', 'application.product', 'paymentOrder', 'loanAccount'])
                ->whereIn('loan_application_id', RecordAccess::applicationsQuery(LoanApplication::query(), $user)->select('id'));
            $this->tab === 'progress' ? $q->whereIn('status', ['initiated', 'processing']) : $q->when($this->filterStatus, fn ($w) => $w->where('status', $this->filterStatus));
            $q->when($this->filterFrom, fn ($w) => $w->whereDate('created_at', '>=', $this->filterFrom))
              ->when($this->filterTo, fn ($w) => $w->whereDate('created_at', '<=', $this->filterTo));
            $this->applySearch($q, ['reference', 'recipient_name']);
            $q->with(['initiator', 'releaser']);
            $history = $this->applySort($q, 'id', ['id', 'net_amount', 'created_at'])->paginate($this->perPage);
        }

        return view('livewire.ops.disbursements.index', [
            'ready' => $ready, 'history' => $history, 'preview' => $preview,
            'counts' => [
                'ready' => RecordAccess::applicationsQuery(LoanApplication::query(), $user)->whereIn('status', ['ready_for_disbursement', 'cooling_off'])->count(),
                'progress' => Disbursement::whereIn('status', ['initiated', 'processing'])->count(),
            ],
            'canInitiate' => $user->hasPermission('disbursements.initiate'),
            'releasing' => $this->releaseId ? Disbursement::with(['application.customer', 'initiator'])->find($this->releaseId) : null,
            'simulation' => \App\Services\Payments\Simulation::enabled(),
        ]);
    }
}
