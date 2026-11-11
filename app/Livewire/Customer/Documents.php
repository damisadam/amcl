<?php

namespace App\Livewire\Customer;

use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\LoanAccount;
use App\Models\LoanApplication;
use App\Services\Documents\DocumentStore;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/** The customer's own documents across profile, applications and financing (with version history). */
#[Layout('layouts.customer')]
class Documents extends Component
{
    use CustomerContext, WithFileUploads;

    public $file;

    public ?int $typeId = null;

    public ?string $expiresOn = null;

    public function saveDocument(DocumentStore $store): void
    {
        $this->validate(['typeId' => 'required|integer', 'file' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120', 'expiresOn' => 'nullable|date|after:today']);
        $type = DocumentType::whereIn('category', ['identity', 'income', 'business'])->findOrFail($this->typeId);
        if ($this->attempt(fn () => $store->store($this->file, $this->customer(), $type, $this->user(), null, $this->expiresOn), __('Document uploaded.'))) {
            $this->reset('file', 'typeId', 'expiresOn');
        }
    }

    public function render()
    {
        $c = $this->customer();
        $appIds = LoanApplication::where('customer_id', $c->id)->pluck('id');
        $loanIds = LoanAccount::where('customer_id', $c->id)->pluck('id');
        $docs = Document::with('type')->where(function ($q) use ($c, $appIds, $loanIds) {
            $q->where(fn ($q) => $q->where('documentable_type', $c->getMorphClass())->where('documentable_id', $c->id))
                ->orWhere(fn ($q) => $q->where('documentable_type', (new LoanApplication)->getMorphClass())->whereIn('documentable_id', $appIds))
                ->orWhere(fn ($q) => $q->where('documentable_type', (new LoanAccount)->getMorphClass())->whereIn('documentable_id', $loanIds));
        })->latest('id')->get();

        return view('livewire.customer.documents', [
            'current' => $docs->where('status', '!=', 'superseded'),
            'previous' => $docs->where('status', 'superseded'),
            'types' => DocumentType::whereIn('category', ['identity', 'income', 'business'])->where('is_active', true)->orderBy('name')->get(),
        ])->title(__('Documents'));
    }
}
