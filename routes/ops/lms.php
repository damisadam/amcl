<?php

use App\Livewire;
use Illuminate\Support\Facades\Route;

// Customers & KYC
Route::get('/customers', Livewire\Ops\Customers\Index::class)->name('customers.index');
Route::get('/customers/create', Livewire\Ops\Customers\Create::class)->name('customers.create');
Route::get('/customers/{customer}', Livewire\Ops\Customers\Show::class)->name('customers.show');
Route::get('/kyc', Livewire\Ops\Kyc\Index::class)->name('kyc.index');

// Origination (LMS feature)
Route::middleware('feature:lms')->group(function () {
    Route::get('/leads', Livewire\Ops\Leads\Index::class)->name('leads.index');
    Route::get('/applications', Livewire\Ops\Applications\Index::class)->name('applications.index');
    Route::get('/applications/create', Livewire\Ops\Applications\Create::class)->name('applications.create');
    Route::get('/applications/{application}', Livewire\Ops\Applications\Show::class)->name('applications.show');
    Route::get('/field/visits', Livewire\Ops\FieldVisits\Index::class)->name('visits.index');
    Route::get('/field/visits/{visit}', Livewire\Ops\FieldVisits\Show::class)->name('visits.show');
});

Route::get('/approvals', Livewire\Ops\Approvals\Index::class)->name('approvals.index');
