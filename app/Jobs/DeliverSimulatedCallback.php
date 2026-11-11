<?php

namespace App\Jobs;

use App\Models\PaymentOrder;
use App\Models\Tenant;
use App\Services\Payments\PaymentOrchestrator;
use App\Support\Correlation;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a delayed simulated provider callback. Safe to retry: the callback inbox de-duplicates
 * by provider event id, so a retried job can never credit or post twice.
 */
class DeliverSimulatedCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [5, 15, 60, 300];

    public function __construct(public int $tenantId, public int $orderId, public array $callback, public int $sequence) {}

    public function handle(PaymentOrchestrator $orchestrator): void
    {
        $tenant = Tenant::findOrFail($this->tenantId);
        Correlation::fresh();
        TenantContext::run($tenant, function () use ($orchestrator) {
            $order = PaymentOrder::findOrFail($this->orderId);
            $orchestrator->deliverSimulatedCallback($order, $this->callback, $this->sequence);
        });
    }
}
