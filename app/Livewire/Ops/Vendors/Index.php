<?php

namespace App\Livewire\Ops\Vendors;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Vendor;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    #[Url(except: '')] public string $filterStatus = '';
    #[Url(except: '')] public string $filterKind = '';
    #[Url(except: '')] public string $filterCategory = '';

    public function mount(): void
    {
        $this->need('vendors.view');
    }

    public function render()
    {
        $q = Vendor::query()->withCount(['items', 'applications'])
            ->when($this->filterStatus, fn ($w) => $w->where('status', $this->filterStatus))
            ->when($this->filterKind, fn ($w) => $w->where('kind', $this->filterKind))
            ->when($this->filterCategory, fn ($w) => $w->where('category', $this->filterCategory));
        $this->applySearch($q, ['business_name', 'code', 'owner_name', 'merchant_code', 'city']);

        return view('livewire.ops.vendors.index', [
            'vendors' => $this->applySort($q, 'id', ['id', 'business_name', 'created_at'])->paginate($this->perPage),
            'counts' => Vendor::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }
}
