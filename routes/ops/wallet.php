<?php

use App\Livewire;
use Illuminate\Support\Facades\Route;

// Wallet & payments operations (F4)
Route::middleware('feature:wallet')->group(function () {
    Route::get('/wallets', Livewire\Ops\Wallets\Index::class)->name('wallets.index');
    Route::get('/wallets/{wallet}', Livewire\Ops\Wallets\Show::class)->name('wallets.show');
    Route::get('/wallet-transactions', Livewire\Ops\WalletTransactions\Index::class)->name('wallet-transactions.index');
});
Route::get('/payments', Livewire\Ops\Payments\Index::class)->name('payments.index');
Route::get('/payments/{order}', Livewire\Ops\Payments\Show::class)->name('payments.show');

// Complaints & disputes
Route::get('/complaints', Livewire\Ops\Complaints\Index::class)->name('complaints.index');
Route::get('/complaints/{complaint}', Livewire\Ops\Complaints\Show::class)->name('complaints.show');
