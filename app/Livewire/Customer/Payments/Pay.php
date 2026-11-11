<?php

namespace App\Livewire\Customer\Payments;

use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\LoanAccount;
use App\Models\LoanPayment;
use App\Services\Lending\RepaymentService;
use App\Services\Payments\PaymentOrchestrator;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Instalment payment (DFS §4.2–4.6): from wallet (internal ledger) or through simulated external
 * rails (Raast request-to-pay, merchant QR / IBAN alias, 1Bill biller, Easypaisa, JazzCash).
 */
#[Layout('layouts.customer')]
class Pay extends Component
{
    use CustomerContext;

    #[Url]
    public ?int $loan = null;

    #[Url]
    public ?int $payment = null;

    public string $amount = '';

    public string $channel = 'wallet';

    public string $pin = '';

    public string $scenario = 'success';

    public const CHANNELS = ['wallet' => 'My wallet', 'raast' => 'Raast (any bank app)', 'qr_iban' => 'Merchant QR / IBAN alias', 'one_bill' => '1Bill / 1LINK (bank, ATM, app)',
        'easypaisa' => 'Easypaisa', 'jazzcash' => 'JazzCash'];

    public function mount(): void
    {
        $loans = $this->loans();
        if ($this->loan) {
            $this->own(LoanAccount::find($this->loan));
        } else {
            $this->loan = $loans->first()?->id;
        }
        if ($this->payment) {
            $this->own(LoanPayment::find($this->payment));
        }
        $this->defaultAmount();
        if (! $this->wallet()?->isActive()) {
            $this->channel = 'raast';
        }
    }

    private function loans()
    {
        return LoanAccount::with('product')->where('customer_id', $this->customer()->id)->whereIn('status', ['active', 'overdue'])->get();
    }

    public function updatedLoan(): void
    {
        $this->own(LoanAccount::find($this->loan));
        $this->defaultAmount();
    }

    private function defaultAmount(): void
    {
        $l = $this->loan ? LoanAccount::find($this->loan) : null;
        $next = $l?->nextInstalment();
        if ($next) {
            $due = Money::sub(Money::add($next->total_due, $next->late_charge_due), $next->total_paid);
            $overdue = $l->overdueAmount();
            $this->amount = (string) (float) Money::max($due, $overdue);
        }
    }

    #[On('realtime')]
    public function refresh(): void {}

    public function pay(RepaymentService $repayments, WalletService $wallets): void
    {
        $this->validate([
            'loan' => 'required|integer',
            'amount' => 'required|numeric|min:1',
            'channel' => 'required|in:'.implode(',', array_keys(self::CHANNELS)),
            'pin' => $this->channel === 'wallet' ? 'required|digits_between:4,6' : 'nullable',
        ]);
        $loan = $this->own(LoanAccount::findOrFail($this->loan));
        $amount = Money::of($this->amount);
        if (Money::gt($amount, $loan->totalOutstanding())) {
            $this->addError('amount', __('Amount exceeds your total outstanding of :a.', ['a' => Money::format($loan->totalOutstanding())]));

            return;
        }

        if ($this->channel === 'wallet') {
            $wallet = $this->wallet() ?? abort(404);
            $this->attempt(fn () => $wallets->payInstalment($wallet, $loan, $amount, 'pay:'.$this->idem, $this->pin, $this->user()),
                __('Payment of :a received. Thank you!', ['a' => Money::format($amount)]));
            $this->pin = '';
            $this->defaultAmount();

            return;
        }

        $scenario = $this->demo() ? $this->scenario : null;
        $p = $this->attempt(fn () => $repayments->initiateCollection($loan, $amount, $this->channel, 'pay:'.$this->idem, $this->user(), $scenario));
        if ($p instanceof LoanPayment) {
            $this->payment = $p->id;
            $this->toast($p->status->value === 'succeeded' ? __('Payment confirmed by the provider.') : __('Payment request created. Complete it in your banking app.'), $p->status->value === 'failed' ? 'red' : 'green');
        }
    }

    public function simulate(string $status, PaymentOrchestrator $orchestrator): void
    {
        abort_unless($this->demo(), 403);
        $p = $this->own(LoanPayment::findOrFail($this->payment));
        $this->attempt(function () use ($p, $status, $orchestrator) {
            $r = $orchestrator->simulate($p->paymentOrder ?? abort(404), $status);
            $this->toast(__('Simulated callback: :r', ['r' => $r]), 'blue');
        });
    }

    public function inquire(PaymentOrchestrator $orchestrator): void
    {
        $p = $this->own(LoanPayment::findOrFail($this->payment));
        $this->attempt(fn () => $orchestrator->inquire($p->paymentOrder ?? abort(404)), __('Status refreshed.'));
    }

    public function newPayment(): void
    {
        $this->payment = null;
        $this->defaultAmount();
    }

    public function render()
    {
        $loan = $this->loan ? LoanAccount::find($this->loan) : null;
        $p = $this->payment ? $this->own(LoanPayment::with('paymentOrder')->find($this->payment)) : null;

        return view('livewire.customer.payments.pay', [
            'loans' => $this->loans(), 'selected' => $loan, 'next' => $loan?->nextInstalment(), 'wallet' => $this->wallet(), 'pending' => $p,
            'scenarios' => config('integrations.payment_scenarios'), 'collectionIban' => \App\Models\BankAccount::where('purpose', 'collection')->value('iban'),
        ])->title(__('Pay Instalment'));
    }
}
