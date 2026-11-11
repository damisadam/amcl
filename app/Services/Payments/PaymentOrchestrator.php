<?php

namespace App\Services\Payments;

use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentPurpose;
use App\Events\PaymentStatusUpdated;
use App\Exceptions\DomainException;
use App\Jobs\DeliverSimulatedCallback;
use App\Models\BankAccount;
use App\Models\PaymentOrder;
use App\Models\SettlementRecord;
use App\Services\Audit\Auditor;
use App\Services\Integrations\Deterministic;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\ProviderResponse;
use App\Services\Support\Numbering;
use App\Support\Money;
use App\Support\Realtime;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates every external money movement:
 *   create order (idempotent) → initiate with provider → pending → callback/inquiry → final state
 *   → purpose handler (wallet credit, loan activation, vendor settlement…) in the same DB transaction.
 *
 * In demo mode the provider is simulated: the selected scenario decides which callbacks are delivered.
 * A success state is NEVER set directly by a user action — only through processCallback().
 */
class PaymentOrchestrator
{
    public const HANDLERS = [
        'wallet_topup' => Handlers\WalletTopupHandler::class,
        'wallet_withdrawal' => Handlers\WalletOutboundHandler::class,
        'bill_payment' => Handlers\WalletOutboundHandler::class,
        'merchant_payment' => Handlers\WalletOutboundHandler::class,
        'loan_disbursement' => Handlers\DisbursementHandler::class,
        'instalment_collection' => Handlers\InstalmentCollectionHandler::class,
        'vendor_payment' => Handlers\VendorPaymentHandler::class,
    ];

    /** Default settlement provider/bank account purpose per payment purpose. */
    private const BANK_PURPOSE = [
        'wallet_topup' => 'safeguarded', 'wallet_withdrawal' => 'safeguarded', 'bill_payment' => 'safeguarded', 'merchant_payment' => 'safeguarded',
        'loan_disbursement' => 'disbursement', 'instalment_collection' => 'collection', 'vendor_payment' => 'operating', 'fund_transfer' => 'operating',
    ];

    public function __construct(private IntegrationManager $integrations) {}

    /**
     * Create (or return the existing) payment order for an idempotency key and initiate it with the provider.
     */
    public function initiate(
        PaymentPurpose $purpose,
        string $provider,
        string $amount,
        Model $payable,
        string $idempotencyKey,
        array $counterparty = [],
        ?string $scenario = null,
        array $meta = [],
    ): PaymentOrder {
        if (! Money::isPositive($amount)) {
            throw new DomainException('Payment amount must be greater than zero.');
        }
        // Forced simulated outcomes are honoured only for demo tenants in demo mode, and audited (QA S5).
        $scenario = Simulation::scenario($scenario, 'payment:'.$purpose->value);

        $order = DB::transaction(function () use ($purpose, $provider, $amount, $payable, $idempotencyKey, $counterparty, $scenario, $meta) {
            $existing = PaymentOrder::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if (! Money::eq($existing->amount, $amount) || $existing->purpose !== $purpose) {
                    throw new DomainException('Idempotency key reuse with different payment details was rejected.');
                }

                return $existing;
            }

            $order = new PaymentOrder([
                'reference' => Numbering::next('payment_order'),
                'purpose' => $purpose,
                'direction' => in_array($purpose, [PaymentPurpose::WalletTopup, PaymentPurpose::InstalmentCollection], true) ? 'inbound' : 'outbound',
                'provider' => $provider,
                'mode' => $this->integrations->setting($provider)?->mode ?? 'demo',
                'amount' => Money::of($amount),
                'payable_type' => $payable->getMorphClass(),
                'payable_id' => $payable->getKey(),
                'counterparty_name' => $counterparty['name'] ?? null,
                'counterparty_account' => $counterparty['account'] ?? null,
                'counterparty_bank' => $counterparty['bank'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'scenario' => $scenario ?: $this->defaultScenario($provider),
                'bank_account_id' => $this->bankAccountFor($purpose)?->id,
                'meta' => $meta,
                'initiated_by' => auth()->id(),
                'expires_at' => now()->addMinutes(30),
            ]);
            $order->forceFill(['status' => PaymentOrderStatus::Created])->save();
            Auditor::record('payment.order_created', $order, [], ['purpose' => $purpose->value, 'provider' => $provider, 'amount' => $order->amount, 'scenario' => $order->scenario]);

            return $order;
        });

        if ($order->status === PaymentOrderStatus::Created) {
            $this->sendToProvider($order);
        }

        return $order->fresh();
    }

    /** Ask the provider (simulator) to execute the order and schedule the scenario's callbacks. */
    private function sendToProvider(PaymentOrder $order): void
    {
        $response = $this->integrations->call($order->provider, $order->direction === 'inbound' ? 'collect' : 'payout', [
            'reference' => $order->reference, 'amount' => $order->amount, 'purpose' => $order->purpose->value,
            'counterparty' => $order->counterparty_account, 'scenario' => $order->scenario,
        ], fn () => $this->simulateInitiation($order), $order, $order->idempotency_key);

        DB::transaction(function () use ($order, $response) {
            $order = PaymentOrder::whereKey($order->id)->lockForUpdate()->first();
            $order->attempts++;
            $order->provider_ref = $response->providerRef ?? $order->provider_ref;
            $to = match ($response->status) {
                'timeout' => PaymentOrderStatus::TimedOut,
                'failure', 'error' => PaymentOrderStatus::Failed,
                default => PaymentOrderStatus::Pending,
            };
            $order->status->assertCanTransitionTo($to, 'Payment order');
            $order->status = $to;
            if ($to === PaymentOrderStatus::Failed) {
                $order->failure_code = $response->errorCode;
                $order->failure_message = $response->message;
            }
            $order->save();
            if ($to === PaymentOrderStatus::Failed) {
                $this->handler($order)->failed($order);
            }
        });

        foreach ($this->plannedCallbacks($order->fresh()) as $i => $cb) {
            $delay = $cb['delay'] ?? 0;
            if ($delay > 0 && config('queue.default') !== 'sync') {
                DeliverSimulatedCallback::dispatch($order->tenant_id, $order->id, $cb, $i)->delay(now()->addSeconds($delay))->onQueue('callbacks');
            } elseif ($delay === 0) {
                $this->deliverSimulatedCallback($order->fresh(), $cb, $i);
            }
        }
        $this->broadcast($order->fresh());
    }

    private function simulateInitiation(PaymentOrder $order): ProviderResponse
    {
        $ref = Deterministic::ref(strtoupper(substr($order->provider, 0, 4)), $order->reference);

        return match ($order->scenario) {
            'failure' => new ProviderResponse('failure', ['reason' => 'Declined by customer bank (simulated)'], 'DECLINED', 'Payment declined by the paying bank (simulated).', $ref),
            'timeout' => new ProviderResponse('timeout', [], 'TIMEOUT', 'Provider did not respond in time (simulated).', $ref),
            default => new ProviderResponse('success', ['accepted' => true, 'note' => 'SIMULATED – no real money moved'], providerRef: $ref),
        };
    }

    /** Callbacks the simulator will deliver for the order's scenario. */
    private function plannedCallbacks(PaymentOrder $order): array
    {
        if ($order->status !== PaymentOrderStatus::Pending) {
            return [];
        }
        $delay = (int) ($this->integrations->setting($order->provider)?->option('callback_delay_seconds', 0) ?? 0);

        return match ($order->scenario) {
            'success' => [['status' => 'succeeded', 'delay' => $delay]],
            'delayed_success' => [['status' => 'succeeded', 'delay' => max(20, $delay)]],
            'duplicate_callback' => [['status' => 'succeeded', 'delay' => $delay], ['status' => 'succeeded', 'delay' => $delay, 'duplicate' => true]],
            'reversal' => [['status' => 'succeeded', 'delay' => $delay], ['status' => 'reversed', 'delay' => $delay]],
            'invalid_reference' => [['status' => 'succeeded', 'delay' => $delay, 'reference' => 'UNKNOWN-'.Str::upper(Str::random(6))]],
            'amount_mismatch' => [['status' => 'succeeded', 'delay' => $delay, 'amount' => Money::sub($order->amount, '100')]],
            default => [], // pending: waits for an explicit simulated callback or inquiry
        };
    }

    /** Build a signed callback payload exactly as the provider would POST it to our webhook. */
    public function buildCallbackPayload(PaymentOrder $order, string $status, array $overrides = [], int $sequence = 0): array
    {
        $payload = [
            'event_id' => $overrides['event_id'] ?? $this->eventId($order, $status, $sequence),
            'reference' => $overrides['reference'] ?? $order->reference,
            'provider_ref' => $order->provider_ref,
            'status' => $status,
            'amount' => $overrides['amount'] ?? $order->amount,
            'currency' => 'PKR',
            'timestamp' => now()->toIso8601String(),
            'simulated' => true,
        ];
        $payload['signature'] = $this->sign($payload, $order->provider);

        return $payload;
    }

    /** Provider-side event id: unique per provider transaction (provider_ref) and outcome. */
    private function eventId(PaymentOrder $order, string $status, int $sequence): string
    {
        return 'EVT-'.($order->provider_ref ?? $order->reference).'-'.$status.($sequence ? '-'.$sequence : '');
    }

    public function deliverSimulatedCallback(PaymentOrder $order, array $cb, int $sequence = 0): string
    {
        $payload = $this->buildCallbackPayload($order, $cb['status'], array_filter([
            'reference' => $cb['reference'] ?? null,
            'amount' => $cb['amount'] ?? null,
            // A duplicate re-sends the SAME event id the provider already delivered.
            'event_id' => ! empty($cb['duplicate']) ? $this->eventId($order, $cb['status'], 0) : null,
        ]), ! empty($cb['duplicate']) ? 0 : $sequence);

        return app(CallbackProcessor::class)->process($order->provider, $payload);
    }

    /** Demo tool: simulate a provider callback for a pending order (permission + demo mode enforced by caller). */
    public function simulate(PaymentOrder $order, string $status): string
    {
        if (! Simulation::enabled()) {
            throw new DomainException('Callback simulation is only available for demo institutions in demo mode.');
        }
        if (! in_array($status, ['succeeded', 'failed', 'reversed'], true)) {
            throw new DomainException('Invalid simulated status.');
        }
        Auditor::record('payment.callback_simulated', $order, [], ['status' => $status]);
        Simulation::audit('payment_callback', $status, $order);

        return $this->deliverSimulatedCallback($order, ['status' => $status], (int) $order->callback_count + 1);
    }

    /** Status inquiry for pending / timed-out orders. The simulator resolves timeouts as successful. */
    public function inquire(PaymentOrder $order): PaymentOrder
    {
        if (! in_array($order->status, [PaymentOrderStatus::Pending, PaymentOrderStatus::TimedOut], true)) {
            return $order;
        }
        $response = $this->integrations->call($order->provider, 'inquiry', ['reference' => $order->reference, 'scenario' => $order->scenario], function () use ($order) {
            $final = match ($order->scenario) {
                'timeout', 'delayed_success', 'success' => 'succeeded',
                'failure' => 'failed',
                default => null, // still pending
            };

            return new ProviderResponse($final ? 'success' : 'pending', ['transaction_status' => $final ?? 'pending'], providerRef: $order->provider_ref);
        }, $order);

        $final = $response->get('transaction_status');
        if (in_array($final, ['succeeded', 'failed'], true)) {
            $payload = $this->buildCallbackPayload($order, $final, ['event_id' => 'INQ-'.$this->eventId($order, $final, 0)]);
            app(CallbackProcessor::class)->process($order->provider, $payload, viaInquiry: true);
        }

        return $order->fresh();
    }

    /** Called by CallbackProcessor inside a locked transaction. */
    public function applyOutcome(PaymentOrder $order, string $status): void
    {
        $to = match ($status) {
            'succeeded' => PaymentOrderStatus::Succeeded,
            'failed' => PaymentOrderStatus::Failed,
            'reversed' => PaymentOrderStatus::Reversed,
        };
        $order->status->assertCanTransitionTo($to, 'Payment order');
        $order->status = $to;
        if ($to === PaymentOrderStatus::Succeeded) {
            $order->settled_at = now();
        }
        if ($to === PaymentOrderStatus::Failed) {
            $order->failure_code ??= 'PROVIDER_FAILED';
            $order->failure_message ??= 'The provider reported the payment as failed (simulated).';
        }
        $order->save();

        $handler = $this->handler($order);
        match ($to) {
            PaymentOrderStatus::Succeeded => $handler->succeeded($order),
            PaymentOrderStatus::Failed => $handler->failed($order),
            PaymentOrderStatus::Reversed => $handler->reversed($order),
        };

        $this->recordSettlement($order, $to);
        Auditor::record('payment.'.$status, $order, [], ['reference' => $order->reference, 'amount' => $order->amount, 'provider_ref' => $order->provider_ref]);
    }

    /** Simulated bank statement line, used by reconciliation. Clearly flagged as simulated. */
    private function recordSettlement(PaymentOrder $order, PaymentOrderStatus $status): void
    {
        if (! $order->bank_account_id || ! in_array($status, [PaymentOrderStatus::Succeeded, PaymentOrderStatus::Reversed], true)) {
            return;
        }
        if (($order->meta['skip_settlement'] ?? false) === true) {
            return; // scenario used to demonstrate a "missing settlement" reconciliation exception
        }
        $inbound = $order->direction === 'inbound';
        $credit = $status === PaymentOrderStatus::Succeeded ? $inbound : ! $inbound;
        SettlementRecord::create([
            'bank_account_id' => $order->bank_account_id,
            'provider' => $order->provider,
            'value_date' => now()->toDateString(),
            'provider_ref' => $order->provider_ref,
            'narration' => strtoupper($order->provider).' '.$order->reference.($status === PaymentOrderStatus::Reversed ? ' REVERSAL' : '').' (SIMULATED)',
            'direction' => $credit ? 'credit' : 'debit',
            'amount' => $order->meta['settled_amount'] ?? $order->amount,
            'is_simulated' => true,
        ]);
    }

    public function handler(PaymentOrder $order): PaymentPurposeHandler
    {
        return app(self::HANDLERS[$order->purpose->value] ?? throw new \LogicException('No handler for '.$order->purpose->value));
    }

    public function bankAccountFor(PaymentPurpose $purpose): ?BankAccount
    {
        $want = self::BANK_PURPOSE[$purpose->value] ?? 'operating';

        return BankAccount::where('purpose', $want)->where('is_active', true)->first()
            ?? BankAccount::where('purpose', 'operating')->where('is_active', true)->first();
    }

    public function sign(array $payload, string $provider): string
    {
        unset($payload['signature']);
        ksort($payload);

        return hash_hmac('sha256', json_encode($payload), $this->webhookSecret($provider));
    }

    public function webhookSecret(string $provider): string
    {
        $secret = $this->integrations->setting($provider)?->credentials['webhook_secret'] ?? null;

        return $secret ?: hash('sha256', config('app.key').'|'.TenantContext::id().'|'.$provider);
    }

    private function defaultScenario(string $provider): string
    {
        return $this->integrations->setting($provider)?->option('default_scenario', 'success') ?? 'success';
    }

    public function broadcast(PaymentOrder $order): void
    {
        Realtime::emit(new PaymentStatusUpdated($order->tenant_id, [
            'reference' => $order->reference, 'purpose' => $order->purpose->value, 'status' => $order->status->value,
        ]));
    }
}
