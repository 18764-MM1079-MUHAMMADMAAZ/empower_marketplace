<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds baseline hardening headers to every response. The CSP keeps 'unsafe-inline'/'unsafe-eval'
 * on script-src because Alpine (bundled with Livewire) evaluates directive expressions via
 * `new Function(...)` and the app relies on inline event handlers — tightening that would require
 * adopting Alpine's dedicated CSP build, a separate change. Everything else here (frame-ancestors,
 * object-src, base-uri, form-action) is new restriction with no behavior change for this app.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com",
            "font-src 'self' https://fonts.bunny.net https://fonts.gstatic.com data:",
            "img-src 'self' data:",
            // Address autocomplete (resources/js/app.js) calls these two keyless public APIs
            // directly from the browser — see workflow-changes.md §5.
            "connect-src 'self' https://photon.komoot.io https://api.zippopotam.us",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
