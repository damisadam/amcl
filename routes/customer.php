<?php

use App\Livewire;
use Illuminate\Support\Facades\Route;

// Customer web portal (/app). All screens re-check ownership through the logged-in customer.
Route::get('/', Livewire\Customer\Home::class)->name('home');
Route::get('/onboarding', Livewire\Customer\Onboarding::class)->name('onboarding');

Route::get('/products', Livewire\Customer\Products\Index::class)->name('products');
Route::get('/products/{product}', Livewire\Customer\Products\Show::class)->name('products.show');
Route::get('/apply/{product}', Livewire\Customer\Applications\Apply::class)->name('apply');
Route::get('/applications', Livewire\Customer\Applications\Index::class)->name('applications');
Route::get('/applications/{application}', Livewire\Customer\Applications\Show::class)->name('applications.show');
Route::get('/applications/{application}/documents/{signatureRequest}', App\Http\Controllers\Customer\AgreementPreviewController::class)->name('applications.document');

Route::get('/financing', Livewire\Customer\Financing\Index::class)->name('financing');
Route::get('/financing/{loan}', Livewire\Customer\Financing\Show::class)->name('financing.show');
Route::get('/receipts/{payment}', [App\Http\Controllers\Customer\ReceiptController::class, 'loan'])->name('receipt');
Route::get('/pay', Livewire\Customer\Payments\Pay::class)->middleware('throttle:money')->name('pay');

Route::middleware('feature:wallet')->group(function () {
    Route::get('/wallet', Livewire\Customer\Wallet\Overview::class)->name('wallet');
    Route::get('/wallet/top-up', Livewire\Customer\Wallet\TopUp::class)->name('wallet.topup');
    Route::get('/wallet/transfer', Livewire\Customer\Wallet\Transfer::class)->name('wallet.transfer');
    Route::get('/wallet/withdraw', Livewire\Customer\Wallet\Withdraw::class)->name('wallet.withdraw');
    Route::get('/wallet/bills', Livewire\Customer\Wallet\Bills::class)->name('wallet.bills');
    Route::get('/wallet/merchant', Livewire\Customer\Wallet\Merchant::class)->name('wallet.merchant');
    Route::get('/wallet/settings', Livewire\Customer\Wallet\Settings::class)->name('wallet.settings');
});

Route::get('/transactions', Livewire\Customer\Transactions::class)->name('transactions');
Route::get('/transactions/wallet/{transaction}/receipt', [App\Http\Controllers\Customer\ReceiptController::class, 'wallet'])->name('wallet.receipt');
Route::get('/documents', Livewire\Customer\Documents::class)->name('documents');
Route::get('/notifications', Livewire\Customer\Notifications::class)->name('notifications');
Route::get('/support', Livewire\Customer\Support\Index::class)->name('support');
Route::get('/support/{complaint}', Livewire\Customer\Support\Show::class)->name('support.show');
Route::get('/profile', Livewire\Customer\Profile::class)->name('profile');
