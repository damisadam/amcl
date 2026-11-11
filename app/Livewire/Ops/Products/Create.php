<?php

namespace App\Livewire\Ops\Products;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\LoanCategory;
use App\Models\Product;
use App\Services\Products\ProductService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.ops')]
class Create extends Component
{
    use HandlesActions, ProductTermsForm;

    public array $product = [
        'code' => '', 'name' => '', 'name_ur' => '', 'category' => 'motorbike', 'shariah_structure' => 'murabaha', 'description' => '', 'description_ur' => '',
        'parent_id' => '', 'loan_category_id' => '', 'shariah_board_approved' => false, 'shariah_approval_ref' => '', 'client_segment' => 'individual',
        'is_staff_product' => false, 'show_in_catalog' => true,
    ];

    public array $branchIds = [];

    public function mount(): void
    {
        $this->need('products.manage');
        $this->defaultTerms();
        $this->branchIds = Branch::pluck('id')->map(fn ($i) => (string) $i)->all();
    }

    public function save(ProductService $svc)
    {
        $this->need('products.manage');
        $this->validate($this->termsRules() + [
            'product.code' => 'required|alpha_dash|max:30|unique:products,code,NULL,id,tenant_id,'.auth()->user()->tenant_id,
            'product.name' => 'required|string|max:120', 'product.name_ur' => 'nullable|string|max:120',
            'product.category' => 'required|in:'.implode(',', \App\Enums\ProductCategory::values()),
            'product.shariah_structure' => 'required|in:'.implode(',', \App\Enums\ShariahStructure::values()),
            'product.parent_id' => 'nullable|exists:products,id', 'product.loan_category_id' => 'nullable|exists:loan_categories,id',
            'product.shariah_approval_ref' => 'required_if:product.shariah_board_approved,true|nullable|string|max:100',
        ]);
        $data = $this->product;
        $data['parent_id'] = $data['parent_id'] ?: null;
        $data['loan_category_id'] = $data['loan_category_id'] ?: null;
        $data['icon'] = $data['category'];
        $p = $this->attempt(function () use ($svc, $data) {
            $p = $svc->create($data, $this->termsPayload(), $this->chargesPayload(), $this->docsPayload(), auth()->user());
            $p->branches()->sync(Branch::whereIn('id', $this->branchIds)->pluck('id'));

            return $p;
        }, __('Product created.'));
        if ($p instanceof Product) {
            return $this->redirect(route('ops.products.show', $p), navigate: true);
        }
    }

    public function render()
    {
        return view('livewire.ops.products.create', [
            'documentTypes' => DocumentType::where('is_active', true)->orderBy('name')->pluck('name', 'code')->all(),
            'parents' => Product::whereNull('parent_id')->orderBy('name')->pluck('name', 'id')->all(),
            'categories' => LoanCategory::orderBy('name')->pluck('name', 'id')->all(),
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }
}
