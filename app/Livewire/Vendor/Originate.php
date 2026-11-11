<?php

namespace App\Livewire\Vendor;

use App\Enums\ConsentType;
use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Vendor\Concerns\ResolvesVendor;
use App\Models\Product;
use App\Services\Lending\VendorOriginationService;
use App\Services\Products\FinancingCalculator;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Point-of-sale origination by the vendor on the customer's behalf (LMS §2.8 / §4.4). */
#[Layout('layouts.vendor')]
class Originate extends Component
{
    use HandlesActions, ResolvesVendor, WithFileUploads;

    public string $full_name = '';

    public string $cnic = '';

    public string $mobile = '';

    public string $date_of_birth = '';

    public string $address = '';

    public string $city = '';

    public string $employment_type = 'salaried';

    public string $monthly_income = '';

    public string $product_id = '';

    public string $vendor_item_id = '';

    public string $tenure = '12';

    public string $down_payment = '';

    public string $income_doc_type = 'SALARY_SLIP';

    public $cnic_front;

    public $income_proof;

    public array $consents = [];

    public ?array $result = null;

    public function mount(): void
    {
        $vendor = $this->vendor();
        $this->city = (string) $vendor->city;
        $this->consents = array_fill_keys([ConsentType::TermsOfUse->value, ConsentType::PrivacyPolicy->value, ConsentType::NadraVerification->value,
            ConsentType::CreditBureau->value, ConsentType::DataSharing->value], false);
    }

    public function submit(VendorOriginationService $svc)
    {
        $vendor = $this->vendor();
        $this->validate([
            'full_name' => 'required|string|min:3|max:100',
            'cnic' => ['required', 'regex:/^\d{5}-\d{7}-\d$/'],
            'mobile' => ['required', 'regex:/^(\+92|92|0)3\d{9}$/'],
            'date_of_birth' => 'required|date|before:-18 years',
            'address' => 'required|string|max:190',
            'city' => 'required|string|max:60',
            'employment_type' => 'required|in:salaried,self_employed,business',
            'monthly_income' => 'required|numeric|min:1000|max:100000000',
            'product_id' => 'required|integer',
            'vendor_item_id' => 'required|integer',
            'tenure' => 'required|integer|min:1|max:120',
            'down_payment' => 'nullable|numeric|min:0',
            'income_doc_type' => 'required|in:SALARY_SLIP,BANK_STATEMENT,BUSINESS_PROOF',
            'cnic_front' => 'required|file|max:5120|mimes:jpg,jpeg,png,webp,pdf',
            'income_proof' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,webp,pdf',
            'consents.'.ConsentType::NadraVerification->value => 'accepted',
            'consents.'.ConsentType::CreditBureau->value => 'accepted',
            'consents.'.ConsentType::TermsOfUse->value => 'accepted',
        ], ['cnic.regex' => __('Enter the CNIC as 12345-1234567-1.'), 'mobile.regex' => __('Enter a valid Pakistani mobile number, e.g. 03001234567.'),
            'consents.*.accepted' => __('The customer must give this consent.')]);

        $result = $this->attempt(fn () => $svc->originate($vendor, auth()->user(), [
            'full_name' => $this->full_name, 'cnic' => $this->cnic, 'mobile' => $this->mobile, 'date_of_birth' => $this->date_of_birth,
            'address' => $this->address, 'city' => $this->city, 'employment_type' => $this->employment_type, 'monthly_income' => $this->monthly_income,
            'product_id' => $this->product_id, 'vendor_item_id' => $this->vendor_item_id, 'tenure' => $this->tenure,
            'down_payment' => $this->down_payment !== '' ? $this->down_payment : null, 'income_doc_type' => $this->income_doc_type,
            'consents' => array_filter($this->consents),
        ], $this->cnic_front, $this->income_proof));

        if (is_array($result)) {
            $this->result = ['id' => $result['application']->id, 'no' => $result['application']->application_no, 'submitted' => $result['submitted'], 'message' => $result['message']];
            $this->toast(__($result['message']), $result['submitted'] ? 'green' : 'blue');
        }
    }

    public function startAnother(): void
    {
        $this->reset(['full_name', 'cnic', 'mobile', 'date_of_birth', 'address', 'monthly_income', 'vendor_item_id', 'down_payment', 'cnic_front', 'income_proof', 'result']);
        $this->mount();
    }

    public function render(FinancingCalculator $calc)
    {
        $vendor = $this->vendor();
        // Only products this dealer can actually supply (M4), and only matching items for the chosen product.
        $products = Product::with('currentVersion')->where('is_active', true)->whereIn('category', $vendor->productCategories() ?: ['__none__'])->get()
            ->filter(fn ($p) => $p->currentVersion?->requires_vendor)->values();
        $product = $products->firstWhere('id', (int) $this->product_id);
        $items = $product ? $vendor->items()->forProduct($product)->orderBy('name')->get() : $vendor->items()->where('is_active', true)->orderBy('name')->get();
        $quote = null;
        $item = $items->firstWhere('id', (int) $this->vendor_item_id);
        if ($item && $product && (int) $this->tenure > 0) {
            $v = $product->currentVersion;
            $down = $this->down_payment !== '' && is_numeric($this->down_payment) ? Money::of($this->down_payment) : Money::pct($item->price, (string) $v->down_payment_pct);
            $financed = Money::sub($item->price, $down);
            if (Money::isPositive($financed) && (int) $this->tenure >= $v->min_tenure && (int) $this->tenure <= $v->max_tenure) {
                $quote = $calc->quote($v, $financed, (int) $this->tenure) + ['down' => $down];
            }
        }

        return view('livewire.vendor.originate', ['vendor' => $vendor, 'items' => $items, 'products' => $products, 'quote' => $quote, 'product' => $product]);
    }
}
