<?php

use App\Livewire;
use Illuminate\Support\Facades\Route;

// Finance Management System (FMS) — loaded inside the /ops group (auth + type:staff, name prefix "ops.")
Route::middleware('feature:fms')->prefix('finance')->name('finance.')->group(function () {
    Route::get('/', Livewire\Ops\Finance\Dashboard::class)->name('dashboard');
    Route::get('/journals', Livewire\Ops\Finance\Journals\Index::class)->name('journals.index');
    Route::get('/journals/create', Livewire\Ops\Finance\Journals\Editor::class)->name('journals.create');
    Route::get('/journals/{journal}/edit', Livewire\Ops\Finance\Journals\Editor::class)->name('journals.edit');
    Route::get('/journals/{journal}', Livewire\Ops\Finance\Journals\Show::class)->name('journals.show');
    Route::get('/accounts', Livewire\Ops\Finance\Accounts::class)->name('accounts');
    Route::get('/accounts/{account}/ledger', Livewire\Ops\Finance\AccountLedger::class)->name('accounts.ledger');
    Route::get('/posting-rules', Livewire\Ops\Finance\PostingRules::class)->name('posting-rules');
    Route::get('/periods', Livewire\Ops\Finance\Periods::class)->name('periods');
    Route::get('/treasury', Livewire\Ops\Finance\Treasury::class)->name('treasury');
    Route::get('/reconciliation', Livewire\Ops\Finance\Reconciliation\Index::class)->name('reconciliation.index');
    Route::get('/reconciliation/{batch}', Livewire\Ops\Finance\Reconciliation\Show::class)->name('reconciliation.show');
    Route::get('/payables', Livewire\Ops\Finance\Payables::class)->name('payables');
    Route::get('/receivables', Livewire\Ops\Finance\Receivables::class)->name('receivables');
    Route::get('/funds', Livewire\Ops\Finance\Funds::class)->name('funds');
    Route::get('/provisioning', Livewire\Ops\Finance\Provisioning::class)->name('provisioning');
    Route::get('/assets', Livewire\Ops\Finance\Assets::class)->name('assets');
    Route::get('/payroll', Livewire\Ops\Finance\Payroll::class)->name('payroll');
    Route::get('/expenses', Livewire\Ops\Finance\Expenses::class)->name('expenses');
    Route::get('/budgets', Livewire\Ops\Finance\Budgets::class)->name('budgets');
    Route::get('/islamic', Livewire\Ops\Finance\Islamic::class)->name('islamic');
    Route::get('/tax', Livewire\Ops\Finance\Tax::class)->name('tax');
});
