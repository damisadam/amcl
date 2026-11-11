<?php

namespace App\Livewire\Ops\Payments;

use App\Enums\PaymentOrderStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Models\IntegrationLog;
use App\Models\PaymentOrder;
use App\Services\Payments\PaymentOrchestrator;
use App\Support\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.ops')]
class Show extends Component
{
    use HandlesActions;

    public PaymentOrder $order;

    public function mount(PaymentOrder $order): void
    {
        $this->need('payments.view');
        $this->order = $order;
    }

    #[On('realtime')]
    public function refreshOrder(): void
    {
        $this->order->refresh();
    }

    private function guardDemo(): void
    {
        $this->need('payments.simulate');
        abort_unless(\App\Services\Payments\Simulation::enabled(), 403, __('Simulation tools are only available for demo institutions in demo mode.'));
    }

    public function simulate(string $status, PaymentOrchestrator $payments): void
    {
        $this->guardDemo();
        abort_unless(in_array($status, ['succeeded', 'failed', 'reversed'], true), 422);
        $result = $this->attempt(fn () => $payments->simulate($this->order, $status));
        if ($result) {
            $this->toast(__('Simulated :s callback → :r', ['s' => $status, 'r' => $result]), $result === 'processed' ? 'green' : 'blue');
        }
        $this->order->refresh();
    }

    public function duplicate(PaymentOrchestrator $payments): void
    {
        $this->guardDemo();
        $last = $this->order->callbacks()->where('result', 'processed')->latest('id')->first();
        if (! $last) {
            $this->toast(__('No processed callback to duplicate yet.'), 'red');

            return;
        }
        // Re-deliver the exact same provider event: the inbox must reject it as a duplicate.
        $payload = $payments->buildCallbackPayload($this->order, $last->reported_status, ['event_id' => $last->event_id]);
        $result = app(\App\Services\Payments\CallbackProcessor::class)->process($this->order->provider, $payload);
        \App\Services\Audit\Auditor::record('payment.duplicate_callback_simulated', $this->order, [], ['result' => $result]);
        $this->toast(__('Duplicate callback delivered → :r (no double posting).', ['r' => $result]), 'blue');
        $this->order->refresh();
    }

    public function inquire(PaymentOrchestrator $payments): void
    {
        $this->need('payments.view');
        $this->attempt(fn () => $payments->inquire($this->order), __('Status inquiry completed.'));
        $this->order->refresh();
    }

    public function render()
    {
        $this->need('payments.view');
        $logs = IntegrationLog::where('tenant_id', TenantContext::id())->where('subject_type', $this->order->getMorphClass())->where('subject_id', $this->order->id)->latest('id')->get();
        $open = in_array($this->order->status, [PaymentOrderStatus::Created, PaymentOrderStatus::Pending, PaymentOrderStatus::Processing, PaymentOrderStatus::TimedOut], true);

        return view('livewire.ops.payments.show', [
            'callbacks' => $this->order->callbacks()->orderBy('id')->get(),
            'logs' => $logs,
            'payable' => $this->order->payable,
            'open' => $open,
            'demo' => \App\Services\Payments\Simulation::enabled() && auth()->user()->hasPermission('payments.simulate'),
            'audit' => \App\Models\AuditLog::where('auditable_type', $this->order->getMorphClass())->where('auditable_id', $this->order->id)->orderBy('id')->get(),
        ])->title($this->order->reference);
    }
}
