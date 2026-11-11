<?php

namespace App\Livewire\Platform;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Tenant;
use App\Services\Platform\PlatformMetrics;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Integration health per tenant × provider. No request/response payloads, no credentials. */
#[Layout('layouts.platform')]
class Health extends Component
{
    use HandlesActions;

    public int $hours = 24;

    public function mount(): void
    {
        $this->need('platform.health.view');
    }

    public function render(PlatformMetrics $metrics)
    {
        $this->hours = in_array($this->hours, [1, 6, 24, 168], true) ? $this->hours : 24;
        $tenants = Tenant::orderBy('id')->get()->keyBy('id');

        return view('livewire.platform.health', [
            'rows' => $metrics->integrationHealth($this->hours, perTenant: true),
            'errors' => $metrics->recentErrors(),
            'matrix' => $metrics->configMatrix(),
            'tenants' => $tenants,
            'providers' => config('integrations.providers'),
        ]);
    }
}
