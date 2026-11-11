<?php

namespace App\Livewire\Public;

use App\Livewire\Customer\Auth\BindsTenant;
use App\Models\DocumentType;
use App\Models\Tenant;
use App\Models\Vendor;
use App\Services\Audit\Auditor;
use App\Services\Documents\DocumentStore;
use App\Services\Lending\VendorOriginationService;
use App\Services\Support\Numbering;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Public vendor onboarding request (LMS §4.1). Creates a pending vendor; the institution approves it. */
#[Layout('layouts.guest')]
class VendorRequest extends Component
{
    use BindsTenant, WithFileUploads;

    public array $form = [
        'business_name' => '', 'owner_name' => '', 'cnic' => '', 'ntn' => '', 'registration_no' => '', 'category' => 'motorbike',
        'city' => '', 'address' => '', 'phone' => '', 'email' => '', 'bank_name' => '', 'iban' => '', 'account_title' => '',
    ];

    public $registration_doc;

    public $bank_letter;

    public bool $accept = false;

    public ?string $reference = null;

    public function mount(Tenant $tenant): void
    {
        $this->bindTenant($tenant);
        abort_unless($tenant->hasFeature('vendor_portal') || $tenant->hasFeature('lms'), 404);
    }

    public function submit(DocumentStore $store): void
    {
        $key = 'vendor-request:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('form.business_name', __('Too many requests from this device. Please try again later.'));

            return;
        }
        $this->validate([
            'form.business_name' => 'required|string|min:3|max:150',
            'form.owner_name' => 'required|string|max:120',
            'form.cnic' => ['required', 'regex:/^\d{5}-\d{7}-\d$/'],
            'form.ntn' => 'nullable|string|max:20',
            'form.registration_no' => 'nullable|string|max:60',
            'form.category' => 'required|in:'.implode(',', array_keys(self::categories())),
            'form.city' => 'required|string|max:60',
            'form.address' => 'required|string|max:190',
            'form.phone' => ['required', 'regex:/^(\+92|92|0)\d{9,10}$/'],
            'form.email' => 'required|email|max:150',
            'form.bank_name' => 'required|string|max:100',
            'form.iban' => ['required', 'regex:/^PK\d{2}[A-Z]{4}\d{16}$/'],
            'form.account_title' => 'required|string|max:120',
            'registration_doc' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,webp,pdf',
            'bank_letter' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,webp,pdf',
            'accept' => 'accepted',
        ], ['form.cnic.regex' => __('Enter the CNIC as 12345-1234567-1.'), 'form.iban.regex' => __('Enter a valid Pakistani IBAN, e.g. PK36SCBL0000001123456702.')]);
        RateLimiter::hit($key, 3600);

        $vendor = DB::transaction(function () use ($store) {
            $v = new Vendor(array_merge($this->form, ['code' => Numbering::next('vendor', $this->tenant->id, false, 5), 'kind' => 'asset', 'source' => 'web_request',
                'preferred_payment_method' => 'raast']));
            $v->forceFill(['status' => 'pending'])->save();
            if ($this->registration_doc) {
                $store->store($this->registration_doc, $v, DocumentType::where('code', 'VENDOR_REG')->first(), null);
            }
            if ($this->bank_letter) {
                $store->store($this->bank_letter, $v, DocumentType::where('code', 'VENDOR_BANK')->first(), null);
            }
            Auditor::record('vendor.onboarding_requested', $v, [], ['business' => $v->business_name, 'source' => 'web_request'], tenantId: $this->tenant->id);

            return $v;
        });
        VendorOriginationService::notifyStaff('vendors.approve', 'New vendor onboarding request', $vendor->business_name.' ('.$vendor->city.') applied to become a vendor.', '/ops/vendors/'.$vendor->id);
        $this->reference = $vendor->code;
    }

    public static function categories(): array
    {
        return ['motorbike' => 'Motorbike dealer', 'rickshaw' => 'Rickshaw dealer', 'laptop' => 'Laptops & electronics', 'scooty' => 'Scooty / EV dealer',
            'solar' => 'Solar systems', 'home_appliances' => 'Home appliances', 'sewing_machine' => 'Sewing machines', 'livestock' => 'Livestock', 'other' => 'Other goods supplier'];
    }

    public function render()
    {
        return view('livewire.public.vendor-request', ['categories' => self::categories()]);
    }
}
