<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\SetTenantContext::class,
            \App\Http\Middleware\TrackDevice::class,
            \App\Http\Middleware\RequireMfa::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
        // Tenant context must exist before route-model binding so bound records are tenant-scoped.
        $middleware->prependToPriorityList(before: \Illuminate\Routing\Middleware\SubstituteBindings::class, prepend: \App\Http\Middleware\SetTenantContext::class);
        $middleware->alias([
            'type' => \App\Http\Middleware\EnsureUserType::class,
            'feature' => \App\Http\Middleware\RequireFeature::class,
        ]);
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        $middleware->redirectGuestsTo(fn ($request) => $request->is('platform*') ? route('platform.login') : ($request->is('ops*', 'vendor*') ? route('staff.login') : route('home')));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([\App\Exceptions\DomainException::class]);
        $exceptions->render(function (\App\Exceptions\TenantIsolationViolation $e) {
            report($e);
            abort(404);
        });
    })->create();
