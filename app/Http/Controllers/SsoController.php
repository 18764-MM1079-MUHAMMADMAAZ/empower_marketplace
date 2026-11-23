<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\SsoLoginToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class SsoController extends Controller
{
    /**
     * Consumes a one-time SSO login token issued by Api\SsoController::issueToken(). The claim
     * (marking it consumed) is a single conditional UPDATE guarded by `consumed_at IS NULL` via
     * the valid() scope, so two simultaneous requests for the same token can never both succeed.
     */
    public function consume(string $token): RedirectResponse
    {
        $invalidRedirect = redirect()->route('login')
            ->with('status', 'This login link has expired or already been used. Please log in normally.');

        $tokenHash = hash('sha256', $token);

        $loginToken = SsoLoginToken::valid()->where('token_hash', $tokenHash)->first();

        if (! $loginToken) {
            return $invalidRedirect;
        }

        $claimed = SsoLoginToken::valid()->where('id', $loginToken->id)->update(['consumed_at' => now()]);

        if ($claimed === 0) {
            return $invalidRedirect;
        }

        Auth::login($loginToken->user);
        session(['sign_in_method' => $loginToken->ssoPartner->name]);

        ActivityLog::record(
            'sso.login_completed',
            "Logged in via SSO ({$loginToken->ssoPartner->name}).",
            user: $loginToken->user,
        );

        return redirect()->route('portal');
    }
}
