<?php

namespace App\Livewire\Platform\Tenants;

use App\Enums\TenantStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.platform')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    public function mount(): void
    {
        $this->need('platform.tenants.view');
    }

    public function render()
    {
        $q = Tenant::query()->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus));
        $this->applySearch($q, ['name', 'code', 'legal_name', 'city']);
        $this->applySort($q, 'id', ['id', 'name', 'code', 'created_at', 'status']);

        return view('livewire.platform.tenants.index', ['tenants' => $q->paginate($this->perPage), 'statuses' => TenantStatus::options()]);
    }
}
