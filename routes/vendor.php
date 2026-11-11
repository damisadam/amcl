<?php

// Vendor portal (prefix /vendor, name vendor., middleware auth + type:vendor + feature:vendor_portal).
// Every screen restricts queries to the signed-in user's vendor record.

use App\Http\Controllers\Vendor\ReceiptController;
use App\Livewire;
use Illuminate\Support\Facades\Route;

Route::get('/', Livewire\Vendor\Dashboard::class)->name('dashboard');
Route::get('/orders', Livewire\Vendor\Orders\Index::class)->name('orders');
Route::get('/orders/{application}', Livewire\Vendor\Orders\Show::class)->name('orders.show');
Route::get('/originate', Livewire\Vendor\Originate::class)->name('originate');
Route::get('/payments', Livewire\Vendor\Payments::class)->name('payments');
Route::get('/payments/{payment}/receipt', ReceiptController::class)->name('payments.receipt');
Route::get('/items', Livewire\Vendor\Items::class)->name('items');
Route::get('/merchant', Livewire\Vendor\Merchant::class)->name('merchant');
Route::get('/documents', Livewire\Vendor\Documents::class)->name('documents');
Route::get('/profile', Livewire\Vendor\Profile::class)->name('profile');
