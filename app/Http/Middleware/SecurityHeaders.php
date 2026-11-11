<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

/**
 * Security headers (S3): nonce-based Content-Security-Policy, framing/MIME/referrer/permissions
 * policies, HSTS on HTTPS/production, and no X-Powered-By fingerprint.
 *
 * CSP notes: scripts need the per-request nonce (Vite, Livewire and our inline scripts use
 * Vite::cspNonce()). 'unsafe-eval' is required because Livewire 3 bundles the standard Alpine build,
 * which evaluates x-data / x-on expressions with Function(); Alpine's CSP build is not available for
 * Livewire's bundle. Inline <script> blocks without the nonce, external scripts and plugins are refused.
 */
class SecurityHeaders
{
    /** sha256 of the only inline event handler we allow (print buttons on receipts/documents). */
    private const PRINT_HANDLER_HASH = "'sha256-MguIPR6qNR8D3B+eAlK+bIRTZe8t3wkOY4B/56Me9FU='";

    public function handle(Request $request, Closure $next)
    {
        if (config('security.csp.enabled', true)) {
            Vite::useCspNonce();
        }
        $response = $next($request);

        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // DFS §6.2: the web app never requests contacts/gallery; camera/geolocation are only used where explicitly needed.
        $response->headers->set('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=(), payment=(), usb=()');
        if ($request->isSecure() || app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        // Responses that set their own policy (e.g. private document downloads) keep it; Horizon's UI uses inline scripts.
        if (config('security.csp.enabled', true) && ! $response->headers->has('Content-Security-Policy') && ! $request->is('horizon', 'horizon/*')) {
            $response->headers->set('Content-Security-Policy', $this->policy());
        }

        return $response;
    }

    public function policy(): string
    {
        $nonce = Vite::cspNonce();
        $fonts = implode(' ', config('security.csp.font_hosts', []));
        $reverb = config('broadcasting.connections.reverb.options', []);
        $ws = '';
        if (! empty($reverb['host'])) {
            $port = $reverb['port'] ?? 443;
            $ws = 'ws://'.$reverb['host'].':'.$port.' wss://'.$reverb['host'].':'.$port;
        }

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval' 'unsafe-hashes' ".self::PRINT_HANDLER_HASH,
            "style-src 'self' 'unsafe-inline' {$fonts}",
            "font-src 'self' data: {$fonts}",
            "img-src 'self' data: blob:",
            "connect-src 'self' {$ws}",
            "frame-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}
