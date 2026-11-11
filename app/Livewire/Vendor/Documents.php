<?php

namespace App\Livewire\Vendor;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Vendor\Concerns\ResolvesVendor;
use App\Models\DocumentType;
use App\Services\Documents\DocumentStore;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.vendor')]
class Documents extends Component
{
    use HandlesActions, ResolvesVendor, WithFileUploads;

    public const TYPES = ['VENDOR_REG', 'VENDOR_BANK'];

    public string $type = 'VENDOR_REG';

    public $file;

    public string $expires_on = '';

    public function saveDocument(DocumentStore $store): void
    {
        $vendor = $this->vendor();
        $this->validate(['type' => 'required|in:'.implode(',', self::TYPES), 'file' => 'required|file|max:5120|mimes:jpg,jpeg,png,webp,pdf', 'expires_on' => 'nullable|date|after:today']);
        if ($this->attempt(fn () => $store->store($this->file, $vendor, DocumentType::where('code', $this->type)->first(), auth()->user(), null, $this->expires_on ?: null), __('Document uploaded for verification.'))) {
            $this->reset('file', 'expires_on');
        }
    }

    public function render()
    {
        $vendor = $this->vendor();

        return view('livewire.vendor.documents', [
            'documents' => $vendor->documents()->with('type')->latest('id')->get(),
            'types' => DocumentType::whereIn('code', self::TYPES)->pluck('name', 'code'),
        ]);
    }
}
