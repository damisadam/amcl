<?php

namespace App\Livewire\Vendor\Orders;

use App\Enums\ApplicationStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Vendor\Concerns\ResolvesVendor;
use App\Models\DocumentType;
use App\Models\LoanApplication;
use App\Services\Documents\DocumentStore;
use App\Services\Lending\VendorOriginationService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Vendor view of one order: status, item, amounts, payout. No credit, bureau or AML details. */
#[Layout('layouts.vendor')]
class Show extends Component
{
    use HandlesActions, ResolvesVendor, WithFileUploads;

    public LoanApplication $application;

    public $docFile;

    public string $uploadType = 'SALARY_SLIP';

    public function mount(LoanApplication $application): void
    {
        abort_unless((int) $application->vendor_id === (int) $this->vendor()->id, 403);
        // Rejected / withdrawn / cancelled outcomes are not shared with the vendor (M19).
        abort_unless(in_array($application->status->value, \App\Livewire\Vendor\Concerns\VendorOrderStatus::visible(), true), 404);
        $this->application = $application;
    }

    public function uploadDocument(DocumentStore $store): void
    {
        abort_unless((int) $this->application->vendor_id === (int) $this->vendor()->id, 403);
        abort_unless($this->application->status === ApplicationStatus::Draft, 403);
        $this->validate(['docFile' => 'required|file|max:5120|mimes:jpg,jpeg,png,webp,pdf', 'uploadType' => 'required|in:SALARY_SLIP,BANK_STATEMENT,BUSINESS_PROOF,UTILITY_BILL,QUOTATION']);
        if ($this->attempt(fn () => $store->store($this->docFile, $this->application, DocumentType::where('code', $this->uploadType)->first(), auth()->user()), 'Document uploaded.')) {
            $this->reset('docFile');
        }
    }

    public function submit(VendorOriginationService $svc): void
    {
        abort_unless((int) $this->application->vendor_id === (int) $this->vendor()->id, 403);
        $result = $this->attempt(fn () => $svc->trySubmit($this->application, auth()->user()));
        if (is_array($result)) {
            $this->toast(__($result['message']), $result['submitted'] ? 'green' : 'blue');
            $this->application->refresh();
        }
    }

    public function render()
    {
        $app = $this->application->load(['customer', 'product', 'vendorItem', 'disbursements', 'histories' => fn ($q) => $q->where('visible_to_customer', true)->whereIn('to_status', \App\Livewire\Vendor\Concerns\VendorOrderStatus::visible())]);

        return view('livewire.vendor.orders.show', [
            'app' => $app,
            'documents' => $app->documents()->with('type')->where('uploaded_by', auth()->id())->latest('id')->get(),
            'kycPending' => $app->customer->kyc_status->value !== 'passed',
        ]);
    }
}
