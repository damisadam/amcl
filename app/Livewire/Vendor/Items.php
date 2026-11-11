<?php

namespace App\Livewire\Vendor;

use App\Enums\ProductCategory;
use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Vendor\Concerns\ResolvesVendor;
use App\Models\VendorItem;
use App\Services\Audit\Auditor;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.vendor')]
class Items extends Component
{
    use HandlesActions, ResolvesVendor, WithDataTable;

    public ?int $editingId = null;

    public bool $showForm = false;

    public array $form = ['name' => '', 'sku' => '', 'product_category' => '', 'price' => '', 'is_active' => true];

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $item = $this->item($id);
        $this->editingId = $item->id;
        $this->form = ['name' => $item->name, 'sku' => (string) $item->sku, 'product_category' => (string) $item->product_category, 'price' => (string) $item->price, 'is_active' => $item->is_active];
        $this->showForm = true;
    }

    public function save(\App\Services\Lending\VendorPriceChangeService $prices): void
    {
        $vendor = $this->vendor();
        $this->validate([
            'form.name' => 'required|string|max:150', 'form.sku' => 'nullable|string|max:40',
            'form.product_category' => 'nullable|in:'.implode(',', ProductCategory::values()),
            'form.price' => 'required|numeric|min:1|max:100000000', 'form.is_active' => 'boolean',
        ]);
        $price = \App\Support\Money::of($this->form['price']);
        $this->attempt(function () use ($vendor, $prices, $price) {
            $data = ['name' => $this->form['name'], 'sku' => $this->form['sku'] ?: null, 'product_category' => $this->form['product_category'] ?: null];
            if ($this->editingId) {
                $item = $this->item($this->editingId);
                $item->fill($data + ['is_active' => (bool) $this->form['is_active'] && $item->pending_price === null ? (bool) $this->form['is_active'] : $item->is_active]);
                Auditor::changes('vendor.item_updated', $item);
                $item->save();
                // Price is never changed directly: it goes to the institution for approval (M20).
                $prices->proposePrice($item, $price, auth()->user());
            } else {
                $prices->proposeNewItem($vendor, $data + ['price' => $price], auth()->user());
            }
            $this->showForm = false;
            $this->resetForm();
        }, __('Item saved. Price changes take effect after the institution approves them.'));
    }

    public function toggle(int $id): void
    {
        $item = $this->item($id);
        $this->attempt(function () use ($item) {
            if (! $item->is_active && $item->pending_price !== null && \App\Support\Money::eq($item->pending_price, $item->price)) {
                throw new \App\Exceptions\DomainException(__('This item can be activated after the institution approves its price.'));
            }
            $item->is_active = ! $item->is_active;
            Auditor::changes('vendor.item_updated', $item);
            $item->save();
        }, __('Item updated.'));
    }

    private function item(int $id): VendorItem
    {
        return VendorItem::where('vendor_id', $this->vendor()->id)->findOrFail($id);
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = ['name' => '', 'sku' => '', 'product_category' => '', 'price' => '', 'is_active' => true];
        $this->resetErrorBag();
    }

    public function render()
    {
        $q = VendorItem::where('vendor_id', $this->vendor()->id);
        $this->applySearch($q, ['name', 'sku']);
        $this->applySort($q, 'name', ['name', 'price']);

        return view('livewire.vendor.items', ['items' => $q->paginate($this->perPage), 'categories' => ProductCategory::options()]);
    }
}
