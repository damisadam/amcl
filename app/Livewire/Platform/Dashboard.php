<?php

namespace App\Livewire\Platform;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Tenant;
use App\Services\Platform\PlatformMetrics;
use App\Services\Platform\QueueHealth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/** Platform overview: counts and health only – no tenant customer or financial data. */
#[Layout('layouts.platform')]
class Dashboard extends Component
{
    use HandlesActions;

    public function mount(): void
    {
        $this->need('platform.tenants.view');
    }

    #[On('realtime')]
    public function refreshDashboard(): void {}

    public function render(PlatformMetrics $metrics, QueueHealth $queues)
    {
        $tenants = Tenant::orderBy('id')->get();

        return view('livewire.platform.dashboard', [
            'byStatus' => $tenants->groupBy(fn ($t) => $t->status->value)->map->count(),
            'tenants' => $tenants,
            'health' => $metrics->tenantHealth(),
            'integrations' => $metrics->integrationHealth(24),
            'modeCounts' => $metrics->modeCounts(),
            'failedJobs' => $metrics->failedJobsCount(),
            'platformUsers' => $metrics->platformUsers(),
            'redis' => $queues->redisStatus(),
            'audit' => PlatformMetrics::platformAuditQuery()->with('actor')->latest('id')->take(8)->get(),
        ]);
    }
}
