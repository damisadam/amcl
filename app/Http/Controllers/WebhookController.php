<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Payments\CallbackProcessor;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Provider webhook endpoint: POST /webhooks/{tenant}/{provider}. The URL fixes the tenant; the HMAC
 * signature (tenant webhook secret) authenticates the provider; the inbox de-duplicates events.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, string $provider)
    {
        abort_unless(array_key_exists($provider, config('integrations.providers')), 404);
        abort_unless($tenant->isActive(), 404);
        $payload = $request->json()->all();
        $result = TenantContext::run($tenant, fn () => app(CallbackProcessor::class)->process($provider, $payload));

        return response()->json(['result' => $result], $result === 'rejected' ? 422 : 200);
    }
}
