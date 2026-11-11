<?php

namespace App\Livewire\Customer\Products;

use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.customer')]
class Index extends Component
{
    use CustomerContext;

    public string $category = '';

    public function render()
    {
        $c = $this->customer();
        $products = Product::with('currentVersion')->where('is_active', true)->where('show_in_catalog', true)->whereNull('parent_id')
            ->where('is_staff_product', false)
            ->when($this->category, fn ($q) => $q->where('category', $this->category))->orderBy('sort')->orderBy('name')->get();
        $categories = Product::where('is_active', true)->where('show_in_catalog', true)->distinct()->pluck('category')
            ->mapWithKeys(fn ($cat) => [$cat->value => __($cat->label())])->all();

        return view('livewire.customer.products.index', ['products' => $products, 'categories' => $categories, 'customer' => $c])->title(__('Apply for Financing'));
    }
}
