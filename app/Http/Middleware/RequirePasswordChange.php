<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After logging in with the temporary password from the welcome email, every page sends the user
 * to My Account › Sign-in & security until they choose their own password (spec §4).
 *
 * Only applies to a session that logged in with that password (the login form sets
 * "password_change_required"): the automatic sign-in right after payment is never interrupted.
 * Only full page loads (GET) are redirected, so Livewire requests and logging out keep working.
 */
class RequirePasswordChange
{
    public const SESSION_KEY = 'password_change_required';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get(self::SESSION_KEY)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            $request->session()->forget(self::SESSION_KEY);

            return $next($request);
        }

        if (! $request->isMethod('GET')
            || $request->routeIs('account', 'password.edit', 'oidc.logout')
            || str_starts_with($request->path(), 'livewire')) {
            return $next($request);
        }

        // Heading to the LMS: finish that handoff once the password is changed.
        if ($request->routeIs('oidc.authorize')) {
            $request->session()->put('oidc_authorize_url', $request->fullUrl());
        }

        return redirect()->to(route('account').'#security')
            ->with('toast', 'Please choose a new password to finish signing in.')
            ->with('toast_type', 'error');
    }
}
