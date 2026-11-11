<?php

namespace App\Livewire\Platform;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Tenant;
use App\Services\Platform\PlatformMetrics;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Platform-level audit events only (tenant business audit stays inside each tenant). */
#[Layout('layouts.platform')]
class Audit extends Component
{
    use HandlesActions, WithDataTable;

    #[Url(except: '')]
    public string $filterTenant = '';

    #[Url(except: '')]
    public string $filterFrom = '';

    #[Url(except: '')]
    public string $filterTo = '';

    public ?int $viewing = null;

    public function mount(): void
    {
        $this->need('platform.audit.view');
    }

    public function render()
    {
        $q = PlatformMetrics::platformAuditQuery()->with(['actor', 'tenant'])
            ->when($this->filterTenant !== '', fn ($q) => $q->where('tenant_id', (int) $this->filterTenant))
            ->when($this->filterFrom !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->filterFrom))
            ->when($this->filterTo !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->filterTo));
        $this->applySearch($q, ['action', 'correlation_id', 'reason']);

        return view('livewire.platform.audit', [
            'logs' => $q->latest('id')->paginate(25),
            'tenants' => Tenant::orderBy('name')->pluck('name', 'id'),
            'detail' => $this->viewing ? PlatformMetrics::platformAuditQuery()->find($this->viewing) : null,
        ]);
    }
}
