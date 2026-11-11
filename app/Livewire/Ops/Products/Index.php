<?php

namespace App\Livewire\Ops\Products;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    public string $filterCategory = '';

    public string $filterActive = '';

    public function mount(): void
    {
        $this->need('products.view');
    }

    public function render()
    {
        $q = Product::with(['currentVersion', 'parent', 'loanCategory'])->withCount(['versions', 'children'])
            ->when($this->filterCategory, fn ($w) => $w->where('category', $this->filterCategory))
            ->when($this->filterActive !== '', fn ($w) => $w->where('is_active', (bool) $this->filterActive));
        $this->applySearch($q, ['name', 'code', 'name_ur']);

        return view('livewire.ops.products.index', [
            'products' => $q->orderByRaw('COALESCE(parent_id, id), parent_id IS NOT NULL')->orderBy('sort')->paginate(25),
            'stats' => Product::selectRaw('COUNT(*) total, SUM(is_active) active, SUM(shariah_board_approved) approved')->first(),
        ]);
    }
}
