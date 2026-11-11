<?php

namespace App\Services\Payments;

use App\Enums\PaymentOrderStatus;
use App\Models\PaymentCallback;
use App\Models\PaymentOrder;
use App\Services\Audit\Auditor;
use App\Support\Money;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Processes provider callbacks (webhooks) safely:
 *  - every callback is stored in an inbox; the same provider event id is processed at most once,
 *  - the HMAC signature (tenant webhook secret) must be valid,
 *  - the reference must belong to this tenant, and the amount must match the order exactly,
 *  - the order transition + business side effects run in one locked DB transaction.
 *
 * Returns the inbox result: processed|duplicate|rejected|ignored|error.
 */
class CallbackProcessor
{
    public function __construct(private PaymentOrchestrator $orchestrator) {}

    public function process(string $provider, array $payload, bool $viaInquiry = false): string
    {
        $tenantId = TenantContext::id() ?? throw new \RuntimeException('Callbacks are processed in a tenant context (webhook URL is tenant-specific).');
        $eventId = (string) ($payload['event_id'] ?? '');
        $signatureValid = hash_equals($this->orchestrator->sign($payload, $provider), (string) ($payload['signature'] ?? ''));

        return DB::transaction(function () use ($provider, $payload, $eventId, $signatureValid, $tenantId) {
            $seen = PaymentCallback::where('tenant_id', $tenantId)->where('provider', $provider)->where('event_id', $eventId)->lockForUpdate()->first();
            if ($seen) {
                $this->store($provider, $eventId.'#dup-'.uniqid('', true), $payload, $signatureValid, 'duplicate', $seen->payment_order_id, 'Duplicate delivery of an already-received event.');
                if ($seen->payment_order_id) {
                    PaymentOrder::whereKey($seen->payment_order_id)->increment('callback_count');
                }

                return 'duplicate';
            }

            if (! $eventId || ! $signatureValid) {
                $this->store($provider, $eventId ?: 'missing-'.uniqid(), $payload, $signatureValid, 'rejected', null, 'Invalid or missing signature / event id.');

                return 'rejected';
            }

            $order = PaymentOrder::where('reference', $payload['reference'] ?? '')->where('provider', $provider)->lockForUpdate()->first();
            if (! $order) {
                $this->store($provider, $eventId, $payload, true, 'rejected', null, 'Unknown payment reference for this institution.');
                Auditor::record('payment.callback_rejected', null, [], ['provider' => $provider, 'reference' => $payload['reference'] ?? null, 'reason' => 'unknown_reference'], tenantId: $tenantId);

                return 'rejected';
            }
            $order->increment('callback_count');

            if (! Money::eq($payload['amount'] ?? 0, $order->amount)) {
                $order->forceFill(['failure_code' => 'AMOUNT_MISMATCH', 'failure_message' => 'Callback amount '.Money::format($payload['amount'] ?? 0).' does not match order amount.',
                    'reconciliation_status' => 'exception'])->save();
                $this->store($provider, $eventId, $payload, true, 'rejected', $order->id, 'Amount mismatch – held for investigation.');
                Auditor::record('payment.callback_amount_mismatch', $order, [], ['reported' => $payload['amount'] ?? null, 'expected' => $order->amount]);

                return 'rejected';
            }

            $status = (string) ($payload['status'] ?? '');
            $target = match ($status) {
                'succeeded' => PaymentOrderStatus::Succeeded, 'failed' => PaymentOrderStatus::Failed, 'reversed' => PaymentOrderStatus::Reversed, default => null,
            };
            if (! $target) {
                $this->store($provider, $eventId, $payload, true, 'rejected', $order->id, 'Unknown status.');

                return 'rejected';
            }
            if ($order->status === $target) {
                $this->store($provider, $eventId, $payload, true, 'duplicate', $order->id, 'Order already in this state.');

                return 'duplicate';
            }
            if (! $order->status->canTransitionTo($target)) {
                $this->store($provider, $eventId, $payload, true, 'ignored', $order->id, "Transition {$order->status->value} → {$target->value} not allowed.");

                return 'ignored';
            }

            $this->orchestrator->applyOutcome($order, $status);
            $this->store($provider, $eventId, $payload, true, 'processed', $order->id);
            DB::afterCommit(fn () => $this->orchestrator->broadcast($order->fresh()));

            return 'processed';
        });
    }

    private function store(string $provider, string $eventId, array $payload, bool $valid, string $result, ?int $orderId, ?string $error = null): void
    {
        PaymentCallback::create([
            'tenant_id' => TenantContext::id(),
            'payment_order_id' => $orderId,
            'provider' => $provider,
            'event_id' => $eventId,
            'provider_ref' => $payload['provider_ref'] ?? null,
            'reported_status' => $payload['status'] ?? null,
            'reported_amount' => is_numeric($payload['amount'] ?? null) ? $payload['amount'] : null,
            'payload' => collect($payload)->except('signature')->all(),
            'signature_valid' => $valid,
            'result' => $result,
            'error' => $error,
            'received_at' => now(),
        ]);
    }
}
