<?php

namespace App\Livewire\Ops\Finance\Journals;

use App\Enums\JournalStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Models\ApprovalRequest;
use App\Models\Disbursement;
use App\Models\JournalVoucher;
use App\Models\LoanPayment;
use App\Models\PaymentOrder;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use App\Models\WalletTransaction;
use App\Services\Accounting\GeneralLedger;
use App\Services\Accounting\JournalWorkflow;
use App\Services\Approvals\ApprovalEngine;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.ops')]
class Show extends Component
{
    use HandlesActions;

    public JournalVoucher $journal;

    public bool $showReverse = false;

    public string $reason = '';

    public string $comment = '';

    public function mount(JournalVoucher $journal): void
    {
        $this->need('fms.view');
        $this->journal = $journal;
    }

    public function submit(): void
    {
        $this->need('fms.journals.create');
        abort_unless((int) $this->journal->created_by === (int) auth()->id(), 403);
        $this->attempt(fn () => app(JournalWorkflow::class)->submit($this->journal->fresh('lines'), auth()->user()), 'Voucher submitted.');
        $this->journal->refresh();
    }

    public function postDirect(): void
    {
        $this->need('fms.journals.post');
        $this->attempt(fn () => app(JournalWorkflow::class)->postDirect($this->journal->fresh(), auth()->user()), 'Voucher posted.');
        $this->journal->refresh();
    }

    public function decide(string $action): void
    {
        $this->need('approvals.act');
        $req = $this->pendingRequest();
        abort_unless($req, 404);
        if ($action === 'approve' && $req->type === \App\Enums\ApprovalType::JournalReversal && ! $this->stepUp()) {
            return;
        }
        $engine = app(ApprovalEngine::class);
        $this->attempt(fn () => match ($action) {
            'approve' => $engine->approve($req, auth()->user(), $this->comment ?: null),
            'reject' => $engine->reject($req, auth()->user(), $this->comment),
            'return' => $engine->returnForCorrection($req, auth()->user(), $this->comment),
        }, 'Decision recorded.');
        $this->comment = '';
        $this->journal->refresh();
    }

    public function reverse(): void
    {
        $this->need('fms.journals.reverse');
        $this->validate(['reason' => 'required|string|min:5|max:500']);
        $rev = $this->attempt(fn () => app(JournalWorkflow::class)->requestReversal($this->journal, auth()->user(), $this->reason),
            'Reversal requested – a second authorised user must approve it before the reversal voucher is posted.');
        if ($rev) {
            $this->showReverse = false;
            $this->reason = '';
            $this->journal->refresh();
        }
    }

    public function discard()
    {
        $this->need('fms.journals.create');
        if ($this->attempt(fn () => app(JournalWorkflow::class)->discard($this->journal, auth()->user()), 'Draft discarded.')) {
            return $this->redirect(route('ops.finance.journals.index'), navigate: true);
        }
    }

    private function pendingRequest(): ?ApprovalRequest
    {
        return ApprovalRequest::where('approvable_type', $this->journal->getMorphClass())->where('approvable_id', $this->journal->id)
            ->where('status', 'pending')->with('currentStage.role')->latest('id')->first();
    }

    /** @return array{0:string,1:?string} label and optional URL for the originating transaction */
    private function sourceLink(): array
    {
        $j = $this->journal;
        if (! $j->source_type) {
            return ['Manual entry', null];
        }
        $src = $j->source;
        $label = class_basename($j->source_type).' #'.$j->source_id;
        $url = match (true) {
            $src instanceof LoanPayment && Route::has('ops.loans.show') => route('ops.loans.show', $src->loan_account_id),
            $src instanceof Disbursement && $src->loan_account_id && Route::has('ops.loans.show') => route('ops.loans.show', $src->loan_account_id),
            $src instanceof WalletTransaction && Route::has('ops.wallets.show') => route('ops.wallets.show', $src->wallet_id),
            $src instanceof PaymentOrder && Route::has('ops.payments.show') => route('ops.payments.show', $src),
            $src instanceof VendorPayment, $src instanceof VendorBill => route('ops.finance.payables'),
            default => null,
        };
        $ref = $src?->reference ?? $src?->payment_no ?? $src?->bill_no ?? $src?->account_no ?? null;

        return [$label.($ref ? " ({$ref})" : ''), $url];
    }

    public function render()
    {
        $this->journal->load(['lines.account', 'lines.costCenter', 'lines.branch', 'creator', 'poster', 'period', 'reversalOf', 'reversedBy', 'documents', 'reversalRequester']);
        $req = $this->pendingRequest();
        $user = auth()->user();
        $history = ApprovalRequest::where('approvable_type', $this->journal->getMorphClass())->where('approvable_id', $this->journal->id)
            ->with('actions.actor')->orderBy('id')->get();

        return view('livewire.ops.finance.journals.show', [
            'source' => $this->sourceLink(),
            'pending' => $req,
            'canDecide' => $req && $user->hasPermission('approvals.act') && app(ApprovalEngine::class)->canAct($req, $user),
            'history' => $history,
            'isMaker' => (int) $this->journal->created_by === (int) $user->id,
            'directPost' => ! app(JournalWorkflow::class)->requiresApproval(),
            'editable' => $this->journal->origin === 'manual' && in_array($this->journal->status, [JournalStatus::Draft, JournalStatus::Rejected], true),
        ]);
    }
}
