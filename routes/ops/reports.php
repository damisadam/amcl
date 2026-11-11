<?php

use App\Livewire;
use Illuminate\Support\Facades\Route;

// Reports — loaded inside the /ops group (auth + type:staff, name prefix "ops.")
Route::prefix('reports')->name('reports.')->group(function () {
    Route::get('/lending', Livewire\Ops\Reports\Lending::class)->name('lending');
    Route::get('/wallet', Livewire\Ops\Reports\Wallet::class)->middleware('feature:wallet')->name('wallet');
    Route::get('/financial', Livewire\Ops\Reports\Financial::class)->middleware('feature:fms')->name('financial');
    Route::get('/regulatory', Livewire\Ops\Reports\Regulatory::class)->name('regulatory');
});
