<?php

namespace App\Livewire\Customer\Applications;

use App\Enums\VendorStatus;
use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\LoanApplication;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Services\Documents\DocumentStore;
use App\Services\Lending\ApplicationService;
use App\Services\Products\FinancingCalculator;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** DFS application wizard: terms → guarantors → documents → review & submit (drafts can be resumed). */
#[Layout('layouts.customer')]
class Apply extends Component
{
    use CustomerContext, WithFileUploads;

    public Product $product;

    #[Url]
    public ?int $application = null;

    public string $step = 'terms';

    public ?int $vendor_id = null;

    public ?int $vendor_item_id = null;

    public string $asset_price = '';

    public string $down_payment = '';

    public string $requested_amount = '';

    public int $tenure = 12;

    public string $purpose = '';

    public string $asset_description = '';

    public array $guarantor = ['name' => '', 'cnic' => '', 'mobile' => '', 'relation' => '', 'address' => '', 'occupation' => '', 'monthly_income' => '', 'consent' => false];

    public array $uploads = [];

    public function mount(Product $product)
    {
        abort_unless($product->is_active && $product->currentVersion, 404);
        $this->product = $product;
        $v = $product->currentVersion;
        if ($this->customer()->kyc_status->value !== 'passed') {
            session()->flash('info', __('Please complete identity verification first.'));

            return $this->redirect(route('app.onboarding'), navigate: true);
        }
        // QA H2: query parameters are untrusted – clamp them to the product's limits.
        $tenure = filter_var(request('tenure'), FILTER_VALIDATE_INT);
        $this->tenure = (int) max($v->min_tenure, min($v->max_tenure, $tenure === false ? 12 : $tenure));
        $amount = request('amount');
        $amount = is_numeric($amount) ? (float) $amount : (float) $v->min_amount;
        $this->requested_amount = (string) (int) round(max((float) $v->min_amount, min((float) $v->max_amount, $amount)));

        if ($this->application) {
            $app = $this->own(LoanApplication::find($this->application));
            abort_unless((int) $app->product_id === (int) $product->id, 404);
            if (! $app->isEditableByCustomer()) {
                return $this->redirect(route('app.applications.show', $app), navigate: true);
            }
            $this->fillFrom($app);
        }
    }

    private function fillFrom(LoanApplication $app): void
    {
        $this->vendor_id = $app->vendor_id;
        $this->vendor_item_id = $app->vendor_item_id;
        $this->asset_price = $app->asset_price ? (string) (float) $app->asset_price : '';
        $this->down_payment = $app->down_payment ? (string) (float) $app->down_payment : '';
        $this->requested_amount = (string) (float) $app->requested_amount;
        $this->tenure = (int) $app->tenure;
        $this->purpose = (string) $app->purpose;
        $this->asset_description = (string) $app->asset_description;
    }

    private function app(): ?LoanApplication
    {
        return $this->application ? $this->own(LoanApplication::find($this->application)) : null;
    }

    public function updatedVendorId(): void
    {
        $this->vendor_item_id = null;
    }

    public function updatedVendorItemId(): void
    {
        $item = $this->vendor_item_id ? VendorItem::forProduct($this->product)->where('vendor_id', $this->vendor_id)->find($this->vendor_item_id) : null;
        if ($item) {
            $this->asset_price = (string) (float) $item->price;
            $this->asset_description = $item->name;
            $this->down_payment = (string) (float) Money::pct($item->price, (string) $this->product->currentVersion->down_payment_pct);
        }
    }

    public function saveTerms(ApplicationService $svc): void
    {
        $v = $this->product->currentVersion;
        $rules = [
            'tenure' => "required|integer|min:{$v->min_tenure}|max:{$v->max_tenure}",
            'purpose' => 'required|string|min:3|max:500',
        ];
        if ($v->requires_vendor) {
            $rules += ['vendor_id' => 'required|integer', 'vendor_item_id' => 'nullable|integer', 'asset_price' => 'required|numeric|min:1', 'down_payment' => 'required|numeric|min:0', 'asset_description' => 'required|string|max:255'];
        } else {
            $rules += ['requested_amount' => "required|numeric|min:{$v->min_amount}|max:{$v->max_amount}"];
        }
        $this->validate($rules);
        if ($v->requires_vendor && ! Vendor::forProduct($this->product)->whereKey($this->vendor_id)->where('status', VendorStatus::Active->value)->exists()) {
            $this->addError('vendor_id', __('Select an active vendor.'));

            return;
        }

        $data = ['tenure' => $this->tenure, 'purpose' => $this->purpose];
        if ($v->requires_vendor) {
            $data += ['vendor_id' => $this->vendor_id, 'vendor_item_id' => $this->vendor_item_id ?: null, 'asset_price' => $this->asset_price,
                'down_payment' => $this->down_payment, 'asset_description' => $this->asset_description];
        } else {
            $data += ['requested_amount' => Money::of($this->requested_amount), 'asset_description' => $this->asset_description ?: null];
        }

        $app = $this->attempt(fn () => ($existing = $this->app())
            ? $svc->updateDraft($existing, $data, $this->user())
            : $svc->createDraft($this->customer(), $this->product, $data, $this->user(), 'web'), __('Draft saved.'));
        if ($app instanceof LoanApplication) {
            $this->application = $app->id;
            $this->step = $v->guarantors_required > 0 ? 'guarantors' : 'documents';
        }
    }

    public function addGuarantor(ApplicationService $svc): void
    {
        $this->validate([
            'guarantor.name' => 'required|string|max:100',
            'guarantor.cnic' => ['required', 'regex:/^\d{5}-\d{7}-\d$/'],
            'guarantor.mobile' => ['required', 'regex:/^(\+92|92|0)3\d{9}$/'],
            'guarantor.relation' => 'required|string|max:30',
            'guarantor.address' => 'nullable|string|max:255',
            'guarantor.occupation' => 'nullable|string|max:100',
            'guarantor.monthly_income' => 'nullable|numeric|min:0',
            'guarantor.consent' => 'accepted',
        ], ['guarantor.cnic.regex' => __('CNIC must be in the format 12345-1234567-1.'), 'guarantor.consent.accepted' => __('The guarantor must consent to verification and credit checks.')]);
        $app = $this->app() ?? abort(404);
        $data = $this->guarantor;
        $data['monthly_income'] = $data['monthly_income'] === '' ? 0 : $data['monthly_income'];
        $data['mobile'] = \App\Services\Kyc\OnboardingService::normaliseMobile($data['mobile']);
        if ($this->attempt(fn () => $svc->addGuarantor($app, $data, $this->user()), __('Guarantor added.'))) {
            $this->reset('guarantor');
        }
    }

    public function removeGuarantor(int $id): void
    {
        $app = $this->app() ?? abort(404);
        abort_unless($app->isEditableByCustomer(), 403);
        $app->guarantors()->whereKey($id)->delete();
        \App\Services\Audit\Auditor::record('application.guarantor_removed', $app, [], ['guarantor_id' => $id]);
    }

    public function saveDocument(int $typeId, DocumentStore $store): void
    {
        $this->validate(["uploads.$typeId" => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120'], ["uploads.$typeId.required" => __('Choose a file first.')]);
        $app = $this->app() ?? abort(404);
        $type = DocumentType::findOrFail($typeId);
        if ($this->attempt(fn () => $store->store($this->uploads[$typeId], $app, $type, $this->user()), __(':d uploaded.', ['d' => $type->name]))) {
            unset($this->uploads[$typeId]);
        }
    }

    public function goTo(string $step): void
    {
        if ($step !== 'terms' && ! $this->application) {
            return;
        }
        $this->step = in_array($step, ['terms', 'guarantors', 'documents', 'review'], true) ? $step : 'terms';
    }

    public function submit(ApplicationService $svc)
    {
        $app = $this->app() ?? abort(404);
        if ($this->attempt(fn () => $svc->submit($app, $this->user()), __('Application submitted. We will keep you updated.'))) {
            return $this->redirect(route('app.applications.show', $app), navigate: true);
        }
    }

    public function render(FinancingCalculator $calc, ApplicationService $svc)
    {
        $v = $this->product->currentVersion;
        $app = $this->app();
        $amount = $v->requires_vendor && is_numeric($this->asset_price) && is_numeric($this->down_payment)
            ? Money::sub($this->asset_price, $this->down_payment) : (is_numeric($this->requested_amount) ? Money::of($this->requested_amount) : '0');
        $quote = Money::isPositive($amount) && $this->tenure >= $v->min_tenure && $this->tenure <= $v->max_tenure ? $calc->quote($v, $amount, $this->tenure) : null;

        $requiredDocs = $v->requiredDocuments()->with('documentType')->where('applies_to', 'applicant')
            ->where(fn ($q) => $q->whereNull('min_amount')->orWhere('min_amount', '<=', $amount))->get();
        $haveDocs = $app ? Document::where(function ($q) use ($app) {
            $q->where(fn ($q) => $q->where('documentable_type', $app->getMorphClass())->where('documentable_id', $app->id))
                ->orWhere(fn ($q) => $q->where('documentable_type', $app->customer->getMorphClass())->where('documentable_id', $app->customer_id));
        })->whereNotIn('status', ['rejected', 'superseded'])->get()->keyBy('document_type_id') : collect();

        return view('livewire.customer.applications.apply', [
            'v' => $v, 'app' => $app, 'quote' => $quote, 'financed' => $amount,
            // QA M4: only dealers and items that match this product's asset category.
            'vendors' => $v->requires_vendor ? Vendor::forProduct($this->product)->where('kind', 'asset')->orderBy('business_name')->get() : collect(),
            'items' => $this->vendor_id ? VendorItem::forProduct($this->product)->where('vendor_id', $this->vendor_id)->orderBy('name')->get() : collect(),
            'guarantors' => $app?->guarantors ?? collect(),
            'requiredDocs' => $requiredDocs, 'haveDocs' => $haveDocs,
            'checklist' => $app && $this->step === 'review' ? $svc->submissionChecklist($app) : [],
        ])->title(__('Apply'));
    }
}
