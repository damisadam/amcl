<?php

use App\Livewire;
use Illuminate\Support\Facades\Route;

// Servicing, products, vendors & staff lending (F3)
Route::middleware('feature:lms')->group(function () {
    Route::get('/disbursements', Livewire\Ops\Disbursements\Index::class)->name('disbursements.index');
    Route::get('/loans', Livewire\Ops\Loans\Index::class)->name('loans.index');
    Route::get('/loans/transfer', Livewire\Ops\Loans\Transfer::class)->name('loans.transfer');
    Route::get('/loans/{loan}', Livewire\Ops\Loans\Show::class)->name('loans.show');
    Route::get('/collections', Livewire\Ops\Collections\Index::class)->name('collections.index');
    Route::get('/recovery', Livewire\Ops\Recovery\Index::class)->name('recovery.index');
    Route::get('/recovery/{loan}', Livewire\Ops\Recovery\Show::class)->name('recovery.show');
    Route::get('/products', Livewire\Ops\Products\Index::class)->name('products.index');
    Route::get('/products/create', Livewire\Ops\Products\Create::class)->name('products.create');
    Route::get('/products/{product}', Livewire\Ops\Products\Show::class)->name('products.show');
});

Route::get('/vendors', Livewire\Ops\Vendors\Index::class)->name('vendors.index');
Route::get('/vendors/create', Livewire\Ops\Vendors\Create::class)->name('vendors.create');
Route::get('/vendors/{vendor}', Livewire\Ops\Vendors\Show::class)->name('vendors.show');

Route::middleware('feature:staff_lending')->group(function () {
    Route::get('/staff-lending', Livewire\Ops\StaffLending\Index::class)->name('staff-lending.index');
    Route::get('/staff-lending/{corporate}', Livewire\Ops\StaffLending\Show::class)->name('staff-lending.show');
});
