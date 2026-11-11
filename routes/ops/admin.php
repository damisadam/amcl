<?php

use App\Livewire;
use Illuminate\Support\Facades\Route;

// Tenant administration (F4)
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', Livewire\Ops\Admin\Users::class)->name('users');
    Route::get('/roles', Livewire\Ops\Admin\Roles::class)->name('roles');
    Route::get('/organization', Livewire\Ops\Admin\Organization::class)->name('org');
    Route::get('/configuration', Livewire\Ops\Admin\Configuration::class)->name('config');
    Route::get('/workflows', Livewire\Ops\Admin\Workflows::class)->name('workflows');
    Route::get('/integrations', Livewire\Ops\Admin\Integrations::class)->name('integrations');
    Route::get('/content', Livewire\Ops\Admin\Content::class)->name('content');
    Route::get('/audit', Livewire\Ops\Admin\Audit::class)->name('audit');
    Route::get('/audit/export', [\App\Http\Controllers\Ops\AuditExportController::class, '__invoke'])->name('audit.export');
});
