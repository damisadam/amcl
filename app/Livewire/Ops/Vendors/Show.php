<?php

namespace App\Livewire\Ops\Vendors;

use App\Enums\VendorStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Models\Disbursement;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\LoanApplication;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Models\VendorPayment;
use App\Services\Audit\Auditor;
use App\Services\Documents\DocumentStore;
use App\Services\Lending\VendorAdminService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.ops')]
class Show extends Component
{
    use HandlesActions, VendorForm, WithFileUploads;

    public Vendor $vendor;

    #[Url]
    public string $tab = 'overview';

    public bool $editing = false;

    public bool $showStatus = false;

    public string $statusTo = '';

    public string $statusReason = '';

    public array $item = ['id' => null, 'name' => '', 'sku' => '', 'product_category' => '', 'price' => '', 'is_active' => true];

    public $docFile; // not "upload": that name shadows Livewire's $wire.upload() in the browser

    public string $uploadType = 'VENDOR_REG';

    public string $uploadExpiry = '';

    public string $rejectDocId = '';

    public string $rejectReason = '';

    public string $userName = '';

    public string $userEmail = '';

    public ?string $tempPassword = null;

    public function mount(Vendor $vendor): void
    {
        $this->need('vendors.view');
        $this->vendor = $vendor;
        $this->form = array_merge($this->blankForm(), array_map(fn ($v) => $v ?? '', $vendor->only(array_keys($this->blankForm()))));
        $this->form['is_merchant'] = (bool) $vendor->is_merchant;
    }

    public function save(VendorAdminService $svc): void
    {
        $this->need('vendors.manage');
        $this->validate($this->formRules(), $this->formMessages());
        if ($this->attempt(fn () => $svc->update($this->vendor, $this->formPayload(), auth()->user()), __('Vendor updated.'))) {
            $this->editing = false;
        }
    }

    public function approve(VendorAdminService $svc): void
    {
        $this->need('vendors.approve');
        $this->attempt(fn () => $svc->approve($this->vendor, auth()->user()), __('Vendor approved and activated.'));
        $this->vendor->refresh();
    }

    public function openStatus(string $to): void
    {
        $this->need($to === 'rejected' ? 'vendors.approve' : 'vendors.manage');
        $this->statusTo = $to;
        $this->statusReason = '';
        $this->showStatus = true;
    }

    public function changeStatus(VendorAdminService $svc): void
    {
        $this->need($this->statusTo === 'rejected' ? 'vendors.approve' : 'vendors.manage');
        $this->validate(['statusReason' => 'required|string|min:3|max:500']);
        $ok = $this->statusTo === 'rejected'
            ? $this->attempt(fn () => $svc->reject($this->vendor, $this->statusReason, auth()->user()), __('Vendor request rejected.'))
            : $this->attempt(fn () => $svc->setStatus($this->vendor, VendorStatus::from($this->statusTo), $this->statusReason, auth()->user()), __('Vendor status updated.'));
        if ($ok) {
            $this->showStatus = false;
            $this->vendor->refresh();
        }
    }

    public function enableMerchant(VendorAdminService $svc): void
    {
        $this->need('vendors.manage');
        $this->attempt(fn () => $svc->enableMerchantWallet($this->vendor, auth()->user()), __('Merchant wallet active.'));
    }

    public function editItem(int $id): void
    {
        $i = $this->vendor->items()->findOrFail($id);
        $this->item = ['id' => $i->id, 'name' => $i->name, 'sku' => (string) $i->sku, 'product_category' => (string) $i->product_category, 'price' => (string) $i->price, 'is_active' => (bool) $i->is_active];
    }

    public function saveItem(): void
    {
        $this->need('vendors.manage');
        $this->validate(['item.name' => 'required|string|max:150', 'item.sku' => 'nullable|string|max:40', 'item.price' => 'required|numeric|min:1', 'item.product_category' => 'nullable|string|max:40']);
        $data = ['name' => $this->item['name'], 'sku' => $this->item['sku'] ?: null, 'price' => $this->item['price'], 'product_category' => $this->item['product_category'] ?: null, 'is_active' => (bool) $this->item['is_active']];
        if ($this->item['id']) {
            $i = $this->vendor->items()->findOrFail($this->item['id']);
            $i->fill($data);
            Auditor::changes('vendor.item_updated', $i);
            $i->save();
        } else {
            $i = $this->vendor->items()->create($data);
            Auditor::record('vendor.item_added', $this->vendor, [], ['item' => $i->name, 'price' => $i->price]);
        }
        $this->item = ['id' => null, 'name' => '', 'sku' => '', 'product_category' => '', 'price' => '', 'is_active' => true];
        $this->toast(__('Item saved.'));
    }

    public function uploadDoc(DocumentStore $store): void
    {
        $this->need('documents.upload', 'vendors.manage');
        $this->validate(['docFile' => 'required|file|max:5120|mimes:jpg,jpeg,png,webp,pdf', 'uploadType' => 'required|in:VENDOR_REG,VENDOR_BANK', 'uploadExpiry' => 'nullable|date|after:today']);
        $type = DocumentType::where('code', $this->uploadType)->firstOrFail();
        if ($this->attempt(fn () => $store->store($this->docFile, $this->vendor, $type, auth()->user(), null, $this->uploadExpiry ?: null), __('Document uploaded.'))) {
            $this->reset('docFile', 'uploadExpiry');
        }
    }

    public function verifyDoc(int $id, bool $approve, DocumentStore $store): void
    {
        $this->need('documents.verify');
        $doc = $this->vendor->documents()->findOrFail($id);
        if (! $approve && blank($this->rejectReason)) {
            $this->rejectDocId = (string) $id;
            $this->addError('rejectReason', __('Enter a rejection reason, then click Reject again.'));

            return;
        }
        $this->attempt(fn () => $store->verify($doc, auth()->user(), $approve, $approve ? null : $this->rejectReason), $approve ? __('Document verified.') : __('Document rejected.'));
        $this->reset('rejectDocId', 'rejectReason');
    }

    public function createUser(VendorAdminService $svc): void
    {
        $this->need('vendors.manage');
        $this->validate(['userName' => 'required|string|max:100', 'userEmail' => 'required|email|max:150']);
        $temp = $this->attempt(fn () => $svc->createPortalUser($this->vendor, $this->userName, $this->userEmail, auth()->user()), __('Vendor portal user created.'));
        if (is_string($temp)) {
            $this->tempPassword = $temp;
            $this->reset('userName', 'userEmail');
        }
    }

    public function render()
    {
        $v = $this->vendor;
        $data = ['v' => $v, 'canManage' => auth()->user()->hasPermission('vendors.manage'), 'canApprove' => auth()->user()->hasPermission('vendors.approve'),
            'wallet' => $v->wallet()->first(), 'transitions' => VendorAdminService::TRANSITIONS[$v->status->value] ?? []];
        if ($this->tab === 'items') {
            $data['items'] = $v->items()->orderBy('name')->get();
        }
        if ($this->tab === 'documents') {
            $data['documents'] = $v->documents()->with('type', 'verifier')->latest('id')->get();
        }
        if ($this->tab === 'users') {
            $data['users'] = User::where('vendor_id', $v->id)->where('type', 'vendor')->get();
        }
        if ($this->tab === 'cases') {
            $data['cases'] = LoanApplication::with('customer', 'product')->where('vendor_id', $v->id)->latest('id')->limit(100)->get();
        }
        if ($this->tab === 'payments') {
            $data['disbursements'] = Disbursement::with('application')->where('recipient_type', 'vendor')->where('recipient_id', $v->id)->latest('id')->limit(100)->get();
            $data['payments'] = VendorPayment::with('bill')->where('vendor_id', $v->id)->whereNull('disbursement_id')->latest('id')->limit(100)->get();
        }

        return view('livewire.ops.vendors.show', $data);
    }
}
