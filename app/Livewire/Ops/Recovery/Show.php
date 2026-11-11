<?php

namespace App\Livewire\Ops\Recovery;

use App\Livewire\Concerns\HandlesActions;
use App\Models\LoanAccount;
use App\Services\Lending\ServicingService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.ops')]
class Show extends Component
{
    use HandlesActions;

    public LoanAccount $loan;

    public string $contact_type = 'call';

    public string $contacted_party = 'borrower';

    public string $outcome = 'promise_to_pay';

    public string $ptp_date = '';

    public string $ptp_amount = '';

    public string $notes = '';

    public function mount(LoanAccount $loan): void
    {
        $this->need('recovery.view', 'recovery.work', 'recovery.assign');
        $this->authorizeCase($loan);
        $this->loan = $loan;
        $this->ptp_date = today()->addDays(3)->toDateString();
    }

    private function authorizeCase(LoanAccount $loan): void
    {
        $user = auth()->user();
        $manager = $user->hasAnyPermission(['recovery.view', 'recovery.assign']) && \App\Policies\RecordAccess::canViewLoan($user, $loan);
        abort_unless($manager || (int) $loan->recovery_agent_id === (int) $user->id, 403, __('This case is not assigned to you.'));
    }

    public function logContact(ServicingService $svc): void
    {
        $this->authorizeCase($this->loan);
        $this->validate([
            'contact_type' => 'required|in:call,sms,visit,whatsapp,app_notification',
            'contacted_party' => 'required|in:borrower,guarantor',
            'outcome' => 'required|in:promise_to_pay,dispute,unreachable,settled,escalate_legal,paid,other',
            'ptp_date' => 'required_if:outcome,promise_to_pay|nullable|date|after_or_equal:today',
            'ptp_amount' => 'required_if:outcome,promise_to_pay|nullable|numeric|min:1',
            'notes' => 'nullable|string|max:2000',
        ]);
        $data = ['contact_type' => $this->contact_type, 'contacted_party' => $this->contacted_party, 'outcome' => $this->outcome, 'notes' => $this->notes,
            'ptp_date' => $this->outcome === 'promise_to_pay' ? $this->ptp_date : null, 'ptp_amount' => $this->outcome === 'promise_to_pay' ? $this->ptp_amount : null];
        if ($this->attempt(fn () => $svc->logContact($this->loan, auth()->user(), $data), __('Contact logged.'))) {
            $this->reset('notes', 'ptp_amount');
        }
    }

    public function sendNotice(ServicingService $svc): void
    {
        $this->authorizeCase($this->loan);
        $this->attempt(fn () => $svc->sendDelayNotice($this->loan, auth()->user()), __('Payment delay notice sent.'));
    }

    public function evaluatePromises(ServicingService $svc): void
    {
        $this->need('recovery.assign', 'recovery.view');
        $this->attempt(fn () => $svc->evaluatePromises(), __('Promises to pay evaluated.'));
    }

    public function render()
    {
        $loan = $this->loan->load(['customer', 'product', 'branch', 'recoveryAgent', 'application.guarantors']);
        $sensitive = auth()->user()->hasPermission('customers.view_sensitive');
        $mask = fn ($m) => $m ? ($sensitive ? $m : substr($m, 0, 4).'*****'.substr($m, -2)) : null;

        return view('livewire.ops.recovery.show', [
            'loanAcc' => $loan, 'overdue' => $loan->overdueAmount(), 'mask' => $mask,
            'instalments' => $loan->instalments()->where('status', '!=', 'paid')->get(),
            'contacts' => $loan->recoveryContacts()->with('agent')->get(),
            'payments' => $loan->payments()->where('status', 'succeeded')->take(5)->get(),
            'window' => [\App\Services\Tenancy\TenantSettings::get('lending', 'collection_contact_start'), \App\Services\Tenancy\TenantSettings::get('lending', 'collection_contact_end')],
        ]);
    }
}
