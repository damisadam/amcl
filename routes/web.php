<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\WebhookController;
use App\Livewire;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- public & auth
Route::get('/', Livewire\Public\Landing::class)->name('home');
Route::post('/locale/{locale}', [SessionController::class, 'locale'])->name('locale'); // POST + CSRF (QA M1)

Route::get('/t/{tenant:code}', Livewire\Public\TenantLanding::class)->name('tenant.entry');
Route::middleware('guest')->group(function () {
    Route::get('/t/{tenant:code}/login', Livewire\Customer\Auth\Login::class)->name('customer.login');
    Route::get('/t/{tenant:code}/register', Livewire\Customer\Auth\Register::class)->name('customer.register');
    Route::get('/t/{tenant:code}/forgot-password', Livewire\Customer\Auth\ForgotPassword::class)->name('customer.password');
    Route::get('/login', Livewire\Auth\StaffLogin::class)->name('staff.login');
    Route::get('/platform/login', Livewire\Auth\PlatformLogin::class)->name('platform.login');
});
Route::get('/t/{tenant:code}/staff', fn (\App\Models\Tenant $tenant) => redirect()->route('staff.login', ['org' => $tenant->code]));

Route::middleware('auth')->group(function () {
    Route::post('/logout', [SessionController::class, 'logout'])->name('logout');
    Route::get('/account/password', Livewire\Auth\ChangePassword::class)->name('password.change');
    // Two-step verification (TOTP) for platform users and privileged staff
    Route::get('/account/mfa/setup', [\App\Http\Controllers\MfaController::class, 'setup'])->name('mfa.setup');
    Route::post('/account/mfa/setup', [\App\Http\Controllers\MfaController::class, 'confirmSetup'])->middleware('throttle:10,1')->name('mfa.setup.confirm');
    Route::get('/account/mfa', [\App\Http\Controllers\MfaController::class, 'challenge'])->name('mfa.challenge');
    Route::post('/account/mfa', [\App\Http\Controllers\MfaController::class, 'verify'])->middleware('throttle:10,1')->name('mfa.verify');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
});

// Guest-accessible public flows owned by modules (e.g. vendor onboarding request)
Route::middleware('web')->group(base_path('routes/public.php'));

// Provider callbacks (CSRF-exempt, HMAC-signed, tenant fixed by URL)
Route::post('/webhooks/{tenant:code}/{provider}', WebhookController::class)->middleware('throttle:webhooks')->name('webhooks');

// ---------------------------------------------------------------- portals
Route::middleware(['auth', 'type:customer'])->prefix('app')->name('app.')->group(base_path('routes/customer.php'));
Route::middleware(['auth', 'type:staff'])->prefix('ops')->name('ops.')->group(function () {
    foreach (['ops', 'lms', 'servicing', 'wallet', 'admin', 'finance', 'reports'] as $file) {
        require base_path("routes/ops/{$file}.php");
    }
});
Route::middleware(['auth', 'type:vendor', 'feature:vendor_portal'])->prefix('vendor')->name('vendor.')->group(base_path('routes/vendor.php'));
Route::middleware(['auth', 'type:platform'])->prefix('platform')->name('platform.')->group(base_path('routes/platform.php'));
