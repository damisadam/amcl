<?php

// Platform super-admin portal (prefix /platform, name platform., middleware auth + type:platform).
// Platform users see counts / health only; tenant customer and financial data is never listed here.

use App\Livewire;
use Illuminate\Support\Facades\Route;

Route::get('/', Livewire\Platform\Dashboard::class)->name('dashboard');
Route::get('/tenants', Livewire\Platform\Tenants\Index::class)->name('tenants.index');
Route::get('/tenants/create', Livewire\Platform\Tenants\Create::class)->name('tenants.create');
Route::get('/tenants/{tenant:code}', Livewire\Platform\Tenants\Show::class)->name('tenants.show');
Route::get('/health', Livewire\Platform\Health::class)->name('health');
Route::get('/queues', Livewire\Platform\Queues::class)->name('queues');
Route::get('/audit', Livewire\Platform\Audit::class)->name('audit');
Route::get('/support-access', Livewire\Platform\SupportAccess::class)->name('support-access');
Route::get('/settings', Livewire\Platform\Settings::class)->name('settings');
Route::get('/demo', Livewire\Platform\Demo::class)->name('demo');
