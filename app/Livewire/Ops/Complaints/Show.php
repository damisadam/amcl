<?php

namespace App\Livewire\Ops\Complaints;

use App\Enums\ComplaintStatus;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionType;
use App\Livewire\Concerns\HandlesActions;
use App\Models\Complaint;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Support\ComplaintService;
use App\Services\Wallet\WalletService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.ops')]
class Show extends Component
{
    use HandlesActions;

    public Complaint $complaint;

    public string $reply = '';

    public bool $internal = false;

    public ?int $assignee = null;

    public string $targetStatus = '';

    public string $note = '';

    public string $resolution = '';

    public bool $showTransition = false;

    public bool $showRefund = false;

    public string $refundReason = '';

    public function mount(Complaint $complaint): void
    {
        $this->need('complaints.view');
        $this->complaint = $complaint;
        $this->assignee = $complaint->assigned_to;
    }

    public function sendReply(ComplaintService $svc): void
    {
        $this->need('complaints.manage');
        $this->validate(['reply' => 'required|string|min:2|max:3000']);
        $internal = $this->internal;
        if ($this->attempt(fn () => $svc->reply($this->complaint, auth()->user(), $this->reply, $internal), $internal ? __('Internal note added.') : __('Reply sent to customer.'))) {
            $this->reset('reply', 'internal');
            if (! $internal && $this->complaint->status === ComplaintStatus::Open) {
                $svc->transition($this->complaint, ComplaintStatus::Acknowledged, auth()->user(), 'First response sent');
            }
            $this->complaint->refresh();
        }
    }

    public function assign(ComplaintService $svc): void
    {
        $this->need('complaints.manage');
        $agent = User::where('type', 'staff')->where('status', 'active')->findOrFail($this->assignee);
        abort_unless($agent->hasAnyPermission(['complaints.manage', 'complaints.view']), 422);
        $this->attempt(fn () => $svc->assign($this->complaint, $agent, auth()->user()), __('Assigned to :n.', ['n' => $agent->name]));
        $this->complaint->refresh();
    }

    public function openTransition(string $to): void
    {
        $this->need('complaints.manage');
        $this->targetStatus = $to;
        $this->note = $this->resolution = '';
        $this->showTransition = true;
    }

    public function transition(ComplaintService $svc): void
    {
        $this->need('complaints.manage');
        $to = ComplaintStatus::from($this->targetStatus);
        $this->validate(['resolution' => $to === ComplaintStatus::Resolved ? 'required|string|min:5|max:3000' : 'nullable', 'note' => 'nullable|string|max:1000']);
        if ($this->attempt(fn () => $svc->transition($this->complaint, $to, auth()->user(), $this->note ?: null, $to === ComplaintStatus::Resolved ? $this->resolution : null), __('Status updated to :s.', ['s' => $to->label()]))) {
            $this->showTransition = false;
            $this->complaint->refresh();
        }
    }

    public function refund(WalletService $wallets, ComplaintService $svc): void
    {
        $this->need('complaints.manage');
        $this->need('wallet.reverse');
        $this->validate(['refundReason' => 'required|string|min:5|max:500']);
        $txn = $this->refundableTransaction();
        abort_unless($txn, 422);
        $ok = $this->attempt(fn () => $wallets->reverseInternal($txn, 'Complaint '.$this->complaint->ticket_no.': '.$this->refundReason, auth()->user()),
            __('Refund posted to the customer wallet.'));
        if ($ok) {
            $svc->reply($this->complaint, auth()->user(), 'Refund of '.\App\Support\Money::format($txn->amount).' processed for '.$txn->reference.'.', true);
            $this->showRefund = false;
            $this->complaint->refresh();
        }
    }

    private function refundableTransaction(): ?WalletTransaction
    {
        $t = $this->complaint->related;
        if (! $t instanceof WalletTransaction) {
            return null;
        }

        return ($t->status === WalletTransactionStatus::Succeeded && ! $t->is_external && in_array($t->type, [WalletTransactionType::TransferOut, WalletTransactionType::MerchantPayment], true)) ? $t : null;
    }

    public function render()
    {
        $this->need('complaints.view');
        $user = auth()->user();

        return view('livewire.ops.complaints.show', [
            'messages' => $this->complaint->messages()->with('user')->get(),
            'agents' => User::where('type', 'staff')->where('status', 'active')->orderBy('name')->get()->filter(fn ($u) => $u->hasPermission('complaints.manage'))->pluck('name', 'id'),
            'transitions' => ComplaintStatus::transitions()[$this->complaint->status->value] ?? [],
            'related' => $this->complaint->related,
            'refundable' => $user->hasPermission('wallet.reverse') ? $this->refundableTransaction() : null,
            'canManage' => $user->hasPermission('complaints.manage'),
        ])->title($this->complaint->ticket_no);
    }
}
