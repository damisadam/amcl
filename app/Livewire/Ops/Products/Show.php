<?php

namespace App\Livewire\Ops\Products;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\LoanApplication;
use App\Models\LoanCategory;
use App\Models\Product;
use App\Services\Audit\Auditor;
use App\Services\Products\FinancingCalculator;
use App\Services\Products\ProductService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.ops')]
class Show extends Component
{
    use HandlesActions, ProductTermsForm;

    public Product $product;

    #[Url]
    public string $tab = 'terms';

    public array $info = [];

    public array $branchIds = [];

    public string $previewAmount = '150000';

    public string $previewTenure = '12';

    public function mount(Product $product): void
    {
        $this->need('products.view');
        $this->product = $product;
        $this->loadInfo();
    }

    private function loadInfo(): void
    {
        $p = $this->product;
        $this->info = $p->only(['name', 'name_ur', 'description', 'description_ur', 'shariah_approval_ref', 'sort']) + [
            'shariah_board_approved' => (bool) $p->shariah_board_approved, 'show_in_catalog' => (bool) $p->show_in_catalog, 'loan_category_id' => (string) $p->loan_category_id,
        ];
        $this->branchIds = $p->branches()->pluck('branches.id')->map(fn ($i) => (string) $i)->all();
        $this->defaultTerms($p->currentVersion?->load('charges', 'requiredDocuments.documentType'));
    }

    public function saveInfo(): void
    {
        $this->need('products.manage');
        $this->validate(['info.name' => 'required|string|max:120', 'info.name_ur' => 'nullable|string|max:120', 'info.sort' => 'nullable|integer',
            'info.shariah_approval_ref' => 'required_if:info.shariah_board_approved,true|nullable|string|max:100', 'info.loan_category_id' => 'nullable|exists:loan_categories,id']);
        $data = $this->info;
        $data['loan_category_id'] = $data['loan_category_id'] ?: null;
        $this->product->fill($data);
        Auditor::changes('product.updated', $this->product);
        $this->product->save();
        $this->toast(__('Product details saved.'));
    }

    public function saveBranches(): void
    {
        $this->need('products.manage');
        $ids = Branch::whereIn('id', $this->branchIds)->pluck('id')->all();
        $old = $this->product->branches()->pluck('branches.id')->all();
        $this->product->branches()->sync($ids);
        Auditor::record('product.branches_changed', $this->product, ['branches' => $old], ['branches' => $ids]);
        $this->toast(__('Branch availability updated.'));
    }

    public function toggleActive(): void
    {
        $this->need('products.manage');
        $this->product->is_active = ! $this->product->is_active;
        Auditor::changes('product.status_changed', $this->product);
        $this->product->save();
        $this->toast($this->product->is_active ? __('Product activated.') : __('Product deactivated – no new applications can be started.'));
    }

    public function createVersion(ProductService $svc): void
    {
        $this->need('products.manage');
        $this->validate($this->termsRules());
        if ($this->attempt(fn () => $svc->newVersion($this->product, $this->termsPayload(), $this->chargesPayload(), $this->docsPayload(), auth()->user(), exact: true),
            __('New version published. Existing applications and loans keep their original terms.'))) {
            $this->product->refresh();
            $this->loadInfo();
            $this->tab = 'versions';
        }
    }

    public function render(FinancingCalculator $calc)
    {
        $v = $this->product->currentVersion?->load('charges', 'requiredDocuments.documentType');
        $preview = null;
        if ($v && is_numeric($this->previewAmount) && is_numeric($this->previewTenure) && $this->previewTenure > 0 && $this->previewTenure <= 260) {
            $preview = $calc->quote($v, (string) $this->previewAmount, (int) $this->previewTenure);
        }

        return view('livewire.ops.products.show', [
            'version' => $v, 'preview' => $preview,
            'versions' => $this->product->versions()->with('charges')->withCount([])->get(),
            'usage' => LoanApplication::where('product_id', $this->product->id)->selectRaw('product_version_id, COUNT(*) n')->groupBy('product_version_id')->pluck('n', 'product_version_id'),
            'children' => $this->product->children()->get(),
            'documentTypes' => DocumentType::where('is_active', true)->orderBy('name')->pluck('name', 'code')->all(),
            'categories' => LoanCategory::orderBy('name')->pluck('name', 'id')->all(),
            'branches' => Branch::orderBy('name')->get(),
            'canManage' => auth()->user()->hasPermission('products.manage'),
        ]);
    }
}
