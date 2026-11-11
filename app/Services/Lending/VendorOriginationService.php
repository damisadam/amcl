<?php

namespace App\Services\Lending;

use App\Enums\AmlStatus;
use App\Enums\ConsentType;
use App\Enums\CustomerStatus;
use App\Enums\KycStatus;
use App\Exceptions\DomainException;
use App\Models\Consent;
use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\LoanApplication;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Services\Audit\Auditor;
use App\Services\Documents\DocumentStore;
use App\Services\Kyc\OnboardingService;
use App\Services\Support\Numbering;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Vendor-assisted point-of-sale origination (LMS §2.8 / §4.4). The vendor creates (or links) the
 * customer record, uploads documents and starts a draft application for one of its own items.
 * Identity verification and credit decisions remain with the institution.
 */
class VendorOriginationService
{
    public const KYC_PENDING_MESSAGE = 'Customer KYC must be completed by the institution before this request can be submitted. The draft has been saved and the institution notified.';

    public function __construct(private ApplicationService $applications, private DocumentStore $documents) {}

    /** @return array{application: LoanApplication, submitted: bool, message: string} */
    public function originate(Vendor $vendor, User $vendorUser, array $data, ?UploadedFile $cnicFront = null, ?UploadedFile $incomeProof = null): array
    {
        if (! $vendorUser->isVendor() || (int) $vendorUser->vendor_id !== (int) $vendor->id) {
            throw new DomainException('You are not authorized to originate for this vendor.');
        }
        if (! $vendor->isActive()) {
            throw new DomainException('Your vendor account must be active to submit financing requests.');
        }
        $item = VendorItem::where('vendor_id', $vendor->id)->where('is_active', true)->find($data['vendor_item_id'] ?? 0)
            ?? throw new DomainException('Select one of your active items.');
        $product = Product::with('currentVersion')->where('is_active', true)->find($data['product_id'] ?? 0)
            ?? throw new DomainException('Select a financing product.');
        if (! $product->currentVersion?->requires_vendor) {
            throw new DomainException('This product is not offered through vendors.');
        }
        if (! in_array($vendor->category, \App\Models\Vendor::categoriesForProduct($product), true)) {
            throw new DomainException('Your shop is not registered to supply '.$product->name.'.');
        }
        if (! VendorItem::whereKey($item->id)->forProduct($product)->exists()) {
            throw new DomainException($item->name.' cannot be financed under '.$product->name.'.');
        }
        $cnic = trim((string) ($data['cnic'] ?? ''));
        if (! preg_match('/^\d{5}-\d{7}-\d$/', $cnic)) {
            throw new DomainException('Enter the CNIC as 12345-1234567-1.');
        }
        $mobile = OnboardingService::normaliseMobile((string) ($data['mobile'] ?? ''));

        $app = DB::transaction(function () use ($vendor, $vendorUser, $data, $item, $product, $cnic, $mobile, $cnicFront, $incomeProof) {
            $customer = Customer::where('cnic', $cnic)->first();
            if ($customer) {
                if ($customer->mobile !== $mobile) {
                    throw new DomainException('A customer with this CNIC is registered with a different mobile number. Ask the customer to contact the institution.');
                }
            } else {
                if (Customer::where('mobile', $mobile)->exists()) {
                    throw new DomainException('This mobile number is already registered to another customer.');
                }
                $customer = new Customer([
                    'cif_no' => Numbering::next('customer', null, false, 7),
                    'client_type' => 'vendor_referred',
                    'full_name' => $data['full_name'], 'cnic' => $cnic, 'mobile' => $mobile, 'date_of_birth' => $data['date_of_birth'] ?? null,
                    'current_address' => $data['address'] ?? null, 'permanent_address' => $data['address'] ?? null, 'city' => $data['city'] ?? $vendor->city,
                    'employment_type' => $data['employment_type'] ?? null, 'monthly_income' => $data['monthly_income'] ?? 0,
                    'onboarding_channel' => 'vendor', 'onboarding_step' => 'personal', 'origin_vendor_id' => $vendor->id,
                    'branch_id' => \App\Models\Branch::where('is_head_office', true)->value('id'), 'created_by' => $vendorUser->id,
                ]);
                $customer->forceFill(['status' => CustomerStatus::Pending, 'kyc_status' => KycStatus::NotStarted, 'aml_status' => AmlStatus::NotScreened])->save();
                Auditor::record('customer.created_by_vendor', $customer, [], ['cif' => $customer->cif_no, 'vendor' => $vendor->code]);
            }

            // Itemised consents captured at the vendor's premises are flagged as such in the snapshot.
            foreach ([ConsentType::TermsOfUse, ConsentType::PrivacyPolicy, ConsentType::NadraVerification, ConsentType::CreditBureau, ConsentType::DataSharing] as $type) {
                if (! empty($data['consents'][$type->value])) {
                    Consent::create([
                        'customer_id' => $customer->id, 'consent_type' => $type, 'version' => '1.0', 'granted' => true, 'locale' => app()->getLocale(),
                        'text_snapshot' => '[Captured at vendor premises: '.$vendor->business_name.' by '.$vendorUser->name.'] '.__('consents.'.$type->value),
                        'ip' => request()->ip(), 'user_agent' => substr((string) request()->userAgent(), 0, 500), 'recorded_at' => now(),
                    ]);
                }
            }

            if ($cnicFront) {
                $this->documents->store($cnicFront, $customer, DocumentType::where('code', 'CNIC_FRONT')->first(), $vendorUser);
            }

            $app = $this->applications->createDraft($customer, $product, [
                'vendor_item_id' => $item->id, 'tenure' => (int) ($data['tenure'] ?? $product->currentVersion->min_tenure),
                'down_payment' => $data['down_payment'] ?? null, 'purpose' => $data['purpose'] ?? 'Purchase of '.$item->name,
            ], $vendorUser, 'vendor');
            if ($incomeProof) {
                $this->documents->store($incomeProof, $app, DocumentType::where('code', $data['income_doc_type'] ?? 'SALARY_SLIP')->first(), $vendorUser);
            }
            Auditor::record('application.vendor_originated', $app, [], ['vendor' => $vendor->code, 'item' => $item->name]);

            return $app;
        });

        $result = $this->trySubmit($app, $vendorUser);
        self::notifyStaff('applications.verify', 'Vendor financing request '.$app->application_no,
            $vendor->business_name.' started a request for '.$app->customer->full_name.($result['submitted'] ? ' (submitted).' : ' – customer KYC / documents pending.'),
            '/ops/applications/'.$app->id);

        return $result;
    }

    /** Notify active staff of the current tenant who hold a permission. */
    public static function notifyStaff(string $permission, string $title, string $body, ?string $url = null): void
    {
        $notifier = app(\App\Services\Notifications\Notifier::class);
        User::where('type', 'staff')->where('status', 'active')->with('roles')->get()
            ->filter(fn (User $u) => $u->hasPermission($permission))
            ->each(fn (User $u) => $notifier->toUser($u, 'vendor_request', ['title' => $title, 'body' => $body, 'url' => $url]));
    }

    /** Submit if the checklist passes; otherwise keep as draft and explain why. */
    public function trySubmit(LoanApplication $app, User $vendorUser): array
    {
        if ((int) $app->vendor_id !== (int) $vendorUser->vendor_id) {
            throw new DomainException('Not authorized.');
        }
        $app->refresh();
        if ($app->customer->kyc_status !== KycStatus::Passed) {
            return ['application' => $app, 'submitted' => false, 'message' => self::KYC_PENDING_MESSAGE];
        }
        $missing = $this->applications->submissionChecklist($app);
        if ($missing) {
            return ['application' => $app, 'submitted' => false, 'message' => implode(' ', $missing)];
        }
        $this->applications->submit($app, $vendorUser);

        return ['application' => $app->fresh(), 'submitted' => true, 'message' => 'Financing request submitted to the institution.'];
    }
}
