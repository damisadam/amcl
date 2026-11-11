<?php

// Guest-accessible module flows (web middleware).

use App\Livewire;
use Illuminate\Support\Facades\Route;

// Vendor onboarding request (LMS §4.1) – creates a PENDING vendor that staff approve.
Route::get('/t/{tenant:code}/vendor-request', Livewire\Public\VendorRequest::class)->name('vendor.request');

// Guarantor remote e-signature (H6) – only via a time-limited signed link sent to the guarantor's mobile.
Route::get('/t/{tenant:code}/guarantor-sign/{signatureRequest}', Livewire\Public\GuarantorSign::class)
    ->whereNumber('signatureRequest')->middleware(['signed', 'throttle:30,1'])->name('guarantor.sign');
