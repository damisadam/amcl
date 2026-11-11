<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Services\Approvals\ApprovalHandlerRegistry::class);
    }

    public function boot(): void
    {
        // Permission keys ("module.resource.action") are explicit grants checked against the user's roles.
        // Abilities without a dot fall through to model policies (record-level checks).
        Gate::before(function (User $user, string $ability) {
            if (str_contains($ability, '.')) {
                return $user->hasPermission($ability);
            }

            return null;
        });

        RateLimiter::for('login', fn (Request $r) => [Limit::perMinute(10)->by($r->ip()), Limit::perMinute(5)->by(strtolower((string) $r->input('email', $r->input('login'))).'|'.$r->ip())]);
        RateLimiter::for('otp', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('money', fn (Request $r) => Limit::perMinute(20)->by(optional($r->user())->id ?: $r->ip()));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(300)->by($r->ip()));

        \Illuminate\Pagination\Paginator::defaultView('components.pagination');

        // Urdu/English JSON strings are split per module (lang/json/<module>/ur.json) to keep them maintainable.
        foreach (glob(lang_path('json/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $this->loadJsonTranslationsFrom($dir);
        }
    }
}
