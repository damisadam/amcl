<?php

namespace App\Livewire\Ops\Customers;

use App\Enums\KycStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\Document;
use App\Models\DocumentType;
use App\Policies\RecordAccess;
use App\Services\Audit\Auditor;
use App\Services\Documents\DocumentStore;
use App\Services\Kyc\CustomerAdminService;
use App\Services\Kyc\OnboardingService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** 360° customer view (LMS §2.4). */
#[Layout('layouts.ops')]
class Show extends Component
{
    use HandlesActions, WithFileUploads;

    public Customer $customer;

    #[Url] public string $tab = 'profile';

    public string $note = '';
    public string $noteCategory = 'general';
    public $docFile; // not "upload": that name shadows Livewire's $wire.upload() in the browser
    public string $uploadType = '';
    public string $uploadExpiry = '';
    public bool $showReject = false;
    public ?int $rejectDocId = null;
    public string $rejectReason = '';
    public array $edit = [];
    public bool $showEdit = false;
    public string $changeField = 'cnic';
    public string $changeValue = '';
    public string $changeReason = '';
    public string $decisionComment = '';
    public string $reviewNotes = '';
    public string $kycScenario = 'auto';
    public bool $showArchive = false;
    public string $archiveReason = '';
    public string $blacklistReason = '';

    /** M2: human field names in validation messages. */
    protected function validationAttributes(): array
    {
        return ['edit.email' => 'email', 'edit.city' => 'city', 'edit.current_address' => 'current address', 'reviewNotes' => 'review notes', 'docFile' => 'file', 'uploadType' => 'document type', 'uploadExpiry' => 'expiry date', 'rejectReason' => 'rejection reason', 'note' => 'note', 'noteCategory' => 'note category', 'changeField' => 'field', 'changeValue' => 'new value', 'changeReason' => 'reason', 'blacklistReason' => 'blacklist reason', 'archiveReason' => 'archive reason'];
    }

    /** M3: an error disappears as soon as the user edits that field. */
    public function updated(string $property): void
    {
        $this->resetErrorBag($property);
    }

    public function mount(Customer $customer): void
    {
        $this->need('customers.view');
        abort_unless(RecordAccess::canViewCustomer(auth()->user(), $customer), 403);
        // M13: branch roles can only open customers of their own branch (or assigned to them).
        abort_unless(Index::scopeForUser(Customer::query(), auth()->user())->whereKey($customer->id)->exists(), 403);
        $this->customer = $customer;
        if (auth()->user()->hasPermission('customers.view_sensitive')) {
            Auditor::record('customer.profile_viewed', $customer);
        }
    }

    #[On('realtime')]
    public function refreshCustomer(): void
    {
        $this->customer->refresh();
    }

    public function openEdit(): void
    {
        $this->need('customers.update');
        $this->edit = $this->customer->only(['email', 'current_address', 'permanent_address', 'city', 'occupation', 'employment_type', 'employer_name',
            'business_name', 'business_type', 'income_source', 'monthly_income', 'other_income', 'existing_obligations', 'marital_status']);
        $this->showEdit = true;
    }

    public function saveEdit(OnboardingService $onb): void
    {
        $this->need('customers.update');
        $this->validate(['edit.email' => 'nullable|email', 'edit.city' => 'required|string|max:60', 'edit.current_address' => 'required|string|max:250',
            'edit.monthly_income' => 'required|numeric|min:0', 'edit.other_income' => 'nullable|numeric|min:0', 'edit.existing_obligations' => 'nullable|numeric|min:0']);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $this->edit);
        if ($this->attempt(fn () => $onb->updateProfile($this->customer, $data, auth()->user()), __('Profile updated.'))) {
            $this->showEdit = false;
        }
    }

    public function runKyc(OnboardingService $onb): void
    {
        $this->need('customers.update');
        $this->attempt(function () use ($onb) {
            $s = $onb->runEkyc($this->customer->fresh(), \App\Services\Payments\Simulation::enabled() ? $this->kycScenario : 'auto', auth()->user());
            $this->customer->refresh();
            $this->toast(__('e-KYC completed: :s', ['s' => $s->label()]), $s === KycStatus::Passed ? 'green' : 'blue');
        });
        $this->tab = 'kyc';
    }

    public function reviewKyc(bool $approve): void
    {
        $onb = app(OnboardingService::class);
        $this->need('kyc.review');
        $this->validate(['reviewNotes' => 'required|string|min:5']);
        if ($this->attempt(fn () => $onb->reviewKyc($this->customer, auth()->user(), $approve, $this->reviewNotes), __('KYC decision recorded.'))) {
            $this->reviewNotes = '';
            $this->customer->refresh();
        }
    }

    public function reviewAml(bool $clear): void
    {
        $onb = app(OnboardingService::class);
        $this->need('aml.review');
        $this->validate(['reviewNotes' => 'required|string|min:5']);
        if ($this->attempt(fn () => $onb->reviewAml($this->customer, auth()->user(), $clear, $this->reviewNotes), __('AML/CFT decision recorded.'))) {
            $this->reviewNotes = '';
            $this->customer->refresh();
        }
    }

    public function uploadDocument(DocumentStore $store): void
    {
        $this->need('documents.upload');
        $this->validate(['docFile' => 'required|file|max:5120|mimes:jpg,jpeg,png,webp,pdf', 'uploadType' => 'required|exists:document_types,id', 'uploadExpiry' => 'nullable|date']);
        $type = DocumentType::findOrFail($this->uploadType);
        if ($this->attempt(fn () => $store->store($this->docFile, $this->customer, $type, auth()->user(), null, $this->uploadExpiry ?: null), __('Document uploaded.'))) {
            $this->reset('docFile', 'uploadType', 'uploadExpiry');
        }
    }

    public function verifyDocument(int $id): void
    {
        $store = app(DocumentStore::class);
        $this->need('documents.verify');
        $doc = $this->ownDocument($id);
        $this->attempt(fn () => $store->verify($doc, auth()->user(), true), __('Document verified.'));
    }

    public function askReject(int $id): void
    {
        $this->need('documents.verify');
        $this->rejectDocId = $id;
        $this->rejectReason = '';
        $this->showReject = true;
    }

    public function rejectDocument(DocumentStore $store): void
    {
        $this->need('documents.verify');
        $this->validate(['rejectReason' => 'required|string|min:5']);
        $doc = $this->ownDocument($this->rejectDocId);
        if ($this->attempt(fn () => $store->verify($doc, auth()->user(), false, $this->rejectReason), __('Document rejected.'))) {
            $this->showReject = false;
        }
    }

    private function ownDocument(int $id): Document
    {
        return Document::where('documentable_type', $this->customer->getMorphClass())->where('documentable_id', $this->customer->id)->findOrFail($id);
    }

    public function addNote(CustomerAdminService $svc): void
    {
        $this->need('customers.view');
        $this->validate(['note' => 'required|string|min:2|max:2000', 'noteCategory' => 'required|in:general,kyc,collection,complaint,risk']);
        if ($this->attempt(fn () => $svc->addNote($this->customer, auth()->user(), $this->note, $this->noteCategory), __('Note added.'))) {
            $this->note = '';
        }
    }

    public function requestChange(OnboardingService $onb): void
    {
        $this->need('customers.update');
        $rules = ['changeField' => 'required|in:cnic,full_name,date_of_birth,father_husband_name', 'changeValue' => 'required|string|max:120', 'changeReason' => 'required|string|min:5'];
        if ($this->changeField === 'cnic') {
            $rules['changeValue'] .= '|regex:/^\d{5}-\d{7}-\d$/';
        }
        $this->validate($rules);
        if ($this->attempt(fn () => $onb->requestChange($this->customer, $this->changeField, $this->changeValue, $this->changeReason, auth()->user()), __('Change request submitted for approval.'))) {
            $this->reset('changeValue', 'changeReason');
        }
    }

    public function decideChange(int $id, bool $approve): void
    {
        $onb = app(OnboardingService::class);
        $this->need('customers.approve_changes');
        $req = CustomerChangeRequest::where('customer_id', $this->customer->id)->findOrFail($id);
        $this->attempt(fn () => $onb->decideChange($req, auth()->user(), $approve, $this->decisionComment ?: ($approve ? 'Verified against original CNIC' : 'Rejected')),
            $approve ? __('Change approved and applied.') : __('Change rejected.'));
        $this->customer->refresh();
    }

    public function toggleBlacklist(CustomerAdminService $svc): void
    {
        $this->need('config.blacklist');
        $this->validate(['blacklistReason' => 'required|string|min:5']);
        if ($this->attempt(fn () => $svc->setBlacklist($this->customer, auth()->user(), ! $this->customer->is_blacklisted, $this->blacklistReason), __('Blacklist updated.'))) {
            $this->blacklistReason = '';
            $this->customer->refresh();
        }
    }

    public function archive(CustomerAdminService $svc)
    {
        $this->need('customers.archive');
        $this->validate(['archiveReason' => 'required|string|min:5']);
        if ($this->attempt(fn () => $svc->archive($this->customer, auth()->user(), $this->archiveReason), __('Customer archived.'))) {
            return $this->redirect(route('ops.customers.index'), navigate: true);
        }
    }

    public function render()
    {
        $u = auth()->user();
        $c = $this->customer->load(['branch', 'officer', 'nextOfKins', 'wallet', 'originVendor', 'user']);
        $kyc = $c->kycVerifications()->with('reviewer')->latest('id')->get();
        if (! $u->hasPermission('aml.view')) {
            $kyc = $kyc->reject(fn ($k) => $k->step->value === 'aml_screening');
        }
        $docs = Document::with(['type', 'uploader', 'verifier'])->where('documentable_type', $c->getMorphClass())->where('documentable_id', $c->id)->latest('id')->get();
        if (! $u->hasPermission('customers.view_sensitive')) {
            $docs = $docs->map(function ($d) {
                $d->restricted = in_array($d->type?->code, ['CNIC_FRONT', 'CNIC_BACK', 'SELFIE', 'GUARANTOR_CNIC'], true);

                return $d;
            });
        }

        return view('livewire.ops.customers.show', [
            'c' => $c,
            'u' => $u,
            'sensitive' => $u->hasPermission('customers.view_sensitive'),
            'canAml' => $u->hasPermission('aml.view'),
            'kyc' => $kyc,
            'docs' => $docs,
            'docTypes' => DocumentType::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'applications' => RecordAccess::applicationsQuery($c->applications()->getQuery(), $u)->with('product')->latest('id')->get(),
            'loans' => $u->hasPermission('loans.view') ? $c->loanAccounts()->with('product')->latest('id')->get() : collect(),
            'walletTxns' => $u->hasPermission('wallet.view') && $c->wallet ? $c->wallet->transactions()->take(10)->get() : collect(),
            'notes' => $c->notes()->with('user')->get(),
            'consents' => $c->consents()->latest('recorded_at')->get(),
            'changes' => $c->changeRequests()->with(['requester', 'decider'])->latest('id')->get(),
        ]);
    }
}
