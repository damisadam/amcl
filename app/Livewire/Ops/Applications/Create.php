<?php

namespace App\Livewire\Ops\Applications;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Customer;
use App\Models\Fund;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Services\Lending\ApplicationService;
use App\Services\Products\FinancingCalculator;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Branch / vendor-assisted application capture for an existing (KYC-passed) customer. */
#[Layout('layouts.ops')]
class Create extends Component
{
    use HandlesActions;

    #[Url] public ?int $customer = null;

    public string $customerSearch = '';
    public string $product_id = '';
    public string $vendor_id = '';
    public string $vendor_item_id = '';
    public string $asset_price = '';
    public string $down_payment = '';
    public string $requested_amount = '';
    public string $tenure = '';
    public string $purpose = '';
    public string $fund_id = '';
    public string $channel = 'branch';

    public function mount(): void
    {
        $this->need('applications.create');
    }

    public function pickCustomer(int $id): void
    {
        $this->customer = Customer::findOrFail($id)->id;
        $this->customerSearch = '';
    }

    /** M3: clear a field's error as soon as it is edited. */
    public function updated(string $property): void
    {
        $this->resetErrorBag($property);
    }

    public function updatedProductId(): void
    {
        $v = Product::find($this->product_id)?->currentVersion;
        $this->tenure = $v ? (string) $v->min_tenure : '';
        $this->reset('vendor_id', 'vendor_item_id', 'asset_price', 'down_payment', 'requested_amount');
    }

    public function updatedVendorItemId(): void
    {
        $item = VendorItem::find($this->vendor_item_id);
        $v = Product::find($this->product_id)?->currentVersion;
        if ($item && $v) {
            $this->asset_price = (string) $item->price;
            $this->down_payment = Money::pct($item->price, (string) $v->down_payment_pct);
        }
    }

    public function save(ApplicationService $apps)
    {
        $this->need('applications.create');
        $product = Product::with('currentVersion')->find($this->product_id);
        $v = $product?->currentVersion;
        $this->validate([
            'customer' => 'required|integer',
            'product_id' => 'required|integer',
            'tenure' => 'required|integer|min:'.($v?->min_tenure ?? 1).'|max:'.($v?->max_tenure ?? 600),
            'purpose' => 'required|string|max:500',
            'channel' => 'required|in:branch,vendor,call_center',
        ] + ($v?->requires_vendor ? ['vendor_item_id' => 'required|integer', 'down_payment' => 'required|numeric|min:0'] : ['requested_amount' => 'required|numeric|min:'.$v?->min_amount.'|max:'.$v?->max_amount]), [
            // M2: units and formatted amounts instead of raw limits
            'tenure.min' => 'Tenure must be between '.$v?->min_tenure.' and '.$v?->max_tenure.' '.($v?->isWeekly() ? 'weeks' : 'months').'.',
            'tenure.max' => 'Tenure must be between '.$v?->min_tenure.' and '.$v?->max_tenure.' '.($v?->isWeekly() ? 'weeks' : 'months').'.',
            'requested_amount.min' => 'Amount must be between '.Money::format($v?->min_amount ?? 0).' and '.Money::format($v?->max_amount ?? 0).'.',
            'requested_amount.max' => 'Amount must be between '.Money::format($v?->min_amount ?? 0).' and '.Money::format($v?->max_amount ?? 0).'.',
        ], [
            'customer' => 'customer', 'product_id' => 'product', 'vendor_item_id' => 'vendor item', 'down_payment' => 'down payment',
            'requested_amount' => 'amount to finance', 'tenure' => 'tenure', 'purpose' => 'purpose', 'channel' => 'channel',
        ]);
        $c = Customer::findOrFail($this->customer);

        $data = ['tenure' => (int) $this->tenure, 'purpose' => $this->purpose, 'fund_id' => $this->fund_id ?: null, 'branch_id' => $c->branch_id ?? auth()->user()->branch_id];
        if ($v->requires_vendor) {
            $item = VendorItem::findOrFail($this->vendor_item_id);
            $data += ['vendor_item_id' => $item->id, 'down_payment' => $this->down_payment];
        } else {
            $data += ['requested_amount' => $this->requested_amount];
        }
        $app = $this->attempt(fn () => $apps->createDraft($c, $product, $data, auth()->user(), $this->channel), __('Draft application created.'));
        if ($app) {
            return $this->redirect(route('ops.applications.show', $app), navigate: true);
        }
    }

    public function render()
    {
        $calc = app(FinancingCalculator::class);
        $product = $this->product_id ? Product::with('currentVersion.charges')->find($this->product_id) : null;
        $v = $product?->currentVersion;
        $amount = $v?->requires_vendor && $this->asset_price !== '' ? Money::sub($this->asset_price, $this->down_payment ?: '0') : ($this->requested_amount ?: null);
        $quote = null;
        if ($v && $amount && is_numeric($amount) && Money::isPositive($amount) && (int) $this->tenure >= 1) {
            try {
                $quote = $calc->quote($v, $amount, (int) $this->tenure);
            } catch (\Throwable) {
                $quote = null;
            }
        }
        $term = '%'.trim($this->customerSearch).'%';

        return view('livewire.ops.applications.create', [
            'selected' => $this->customer ? Customer::find($this->customer) : null,
            'matches' => strlen(trim($this->customerSearch)) >= 2 ? Customer::where(fn ($w) => $w->where('full_name', 'like', $term)->orWhere('cif_no', 'like', $term)->orWhere('mobile', 'like', $term)->orWhere('cnic', 'like', $term))->take(8)->get() : collect(),
            'products' => Product::where('is_active', true)->whereNotNull('current_version_id')->orderBy('name')->pluck('name', 'id')->all(),
            'v' => $v,
            'vendors' => $v?->requires_vendor ? Vendor::where('status', 'active')->orderBy('business_name')->pluck('business_name', 'id')->all() : [],
            'items' => $this->vendor_id ? VendorItem::where('vendor_id', $this->vendor_id)->where('is_active', true)->get()->mapWithKeys(fn ($i) => [$i->id => $i->name.' – '.Money::format($i->price)])->all() : [],
            'funds' => Fund::where('status', 'active')->pluck('name', 'id')->all(),
            'amount' => $amount,
            'quote' => $quote,
        ]);
    }
}
