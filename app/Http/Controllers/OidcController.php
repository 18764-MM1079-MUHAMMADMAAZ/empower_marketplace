<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\OidcTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Marketplace as the OpenID Connect provider for the Empower LMS (Moodle's IOMAD "OpenID Connect"
 * plugin). Authorization code flow only, with a single confidential client configured in
 * config/oidc.php. See changes/Empower Marketplace to LMS SSO & Account Provisioning 1.pdf §5.
 */
class OidcController extends Controller
{
    public function __construct(private OidcTokenIssuer $issuer) {}

    /** The "Access LMS" button. Paid clients go to Moodle, which bounces them through authorize()
     *  and back signed in; everyone else is sent to the packages to buy one. */
    public function launch(Request $request): RedirectResponse
    {
        if (! $request->user()->hasLmsAccess()) {
            return redirect()->to(route('home').'#pricing')
                ->with('toast', 'Buy a package to unlock training.')
                ->with('toast_type', 'error');
        }

        return redirect()->away(config('oidc.moodle.launch_url'));
    }

    public function discovery(): JsonResponse
    {
        $issuer = config('oidc.issuer');

        return response()->json([
            'issuer' => $issuer,
            'authorization_endpoint' => route('oidc.authorize'),
            'token_endpoint' => route('oidc.token'),
            'userinfo_endpoint' => route('oidc.userinfo'),
            'jwks_uri' => route('oidc.jwks'),
            'end_session_endpoint' => route('oidc.logout'),
            'frontchannel_logout_supported' => true,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'profile', 'email'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'name', 'given_name', 'family_name', 'preferred_username', 'email', 'email_verified'],
        ]);
    }

    public function jwks(): JsonResponse
    {
        return response()->json($this->issuer->jwks());
    }

    public function authorize(Request $request): RedirectResponse
    {
        $client = config('oidc.moodle');

        // An unknown client or redirect URI is never redirected to — that would be an open redirect.
        abort_if(
            blank($client['client_id'])
                || $request->query('client_id') !== $client['client_id']
                || $request->query('redirect_uri') !== $client['redirect_uri'],
            400,
            'Unknown OpenID Connect client or redirect URI.',
        );

        $state = $request->query('state');

        if ($request->query('response_type') !== 'code') {
            return $this->errorRedirect('unsupported_response_type', $state);
        }

        if (! in_array('openid', explode(' ', (string) $request->query('scope')), true)) {
            return $this->errorRedirect('invalid_scope', $state);
        }

        $user = $request->user();

        if (! $user) {
            if ($request->query('prompt') === 'none') {
                return $this->errorRedirect('login_required', $state);
            }

            // The login form sends the client back here once they've signed in.
            session(['oidc_authorize_url' => $request->fullUrl()]);

            return redirect()->route('login');
        }

        if (! $user->hasLmsAccess()) {
            return redirect()->to(route('home').'#pricing')
                ->with('toast', 'Buy a package to unlock training.')
                ->with('toast_type', 'error');
        }

        $code = Str::random(64);

        Cache::put($this->codeKey($code), [
            'user_id' => $user->id,
            'client_id' => $client['client_id'],
            'redirect_uri' => $client['redirect_uri'],
            'nonce' => $request->query('nonce'),
            'auth_time' => time(),
        ], (int) config('oidc.code_ttl'));

        ActivityLog::record('lms.sso_login', 'Signed in to the Empower LMS via Marketplace single sign-on.', user: $user);

        return redirect()->away($this->withQuery($client['redirect_uri'], array_filter(['code' => $code, 'state' => $state])));
    }

    public function token(Request $request): JsonResponse
    {
        $client = config('oidc.moodle');

        // client_secret_basic (Authorization header) or client_secret_post (form fields).
        $clientId = $request->getUser() !== null ? urldecode($request->getUser()) : (string) $request->input('client_id');
        $clientSecret = $request->getUser() !== null ? urldecode((string) $request->getPassword()) : (string) $request->input('client_secret');

        if (blank($client['client_id']) || blank($client['client_secret'])
            || ! hash_equals((string) $client['client_id'], $clientId)
            || ! hash_equals((string) $client['client_secret'], $clientSecret)) {
            return $this->tokenError('invalid_client', 401);
        }

        if ($request->input('grant_type') !== 'authorization_code') {
            return $this->tokenError('unsupported_grant_type');
        }

        // pull() makes the code single use: a replayed code finds nothing.
        $grant = Cache::pull($this->codeKey((string) $request->input('code')));

        if (! $grant || $grant['client_id'] !== $clientId || $grant['redirect_uri'] !== $request->input('redirect_uri')) {
            return $this->tokenError('invalid_grant');
        }

        $user = User::find($grant['user_id']);

        if (! $user?->hasLmsAccess()) {
            return $this->tokenError('invalid_grant');
        }

        $accessToken = Str::random(64);
        Cache::put($this->accessTokenKey($accessToken), $user->id, (int) config('oidc.access_token_ttl'));

        return response()->json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('oidc.access_token_ttl'),
            'scope' => 'openid profile email',
            'id_token' => $this->issuer->idToken($user, $clientId, $grant['nonce'], $grant['auth_time']),
        ])->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    public function userinfo(Request $request): JsonResponse
    {
        $userId = $request->bearerToken() ? Cache::get($this->accessTokenKey($request->bearerToken())) : null;
        $user = $userId ? User::find($userId) : null;

        if (! $user) {
            return response()->json(['error' => 'invalid_token'], 401)
                ->header('WWW-Authenticate', 'Bearer error="invalid_token"');
        }

        return response()->json($this->issuer->claimsFor($user));
    }

    /** End-session endpoint: Moodle's "Single Sign Out" sends the browser here when the user logs
     *  out of the LMS, so they're signed out of Marketplace too. */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $target = (string) $request->query('post_logout_redirect_uri');

        // Only ever return to the LMS itself (trailing slash stops lookalike hosts).
        if ($target !== '' && str_starts_with($target, config('oidc.moodle.base_url').'/')) {
            return redirect()->away($target);
        }

        return redirect()->route('home');
    }

    private function errorRedirect(string $error, ?string $state): RedirectResponse
    {
        return redirect()->away($this->withQuery(config('oidc.moodle.redirect_uri'), array_filter(['error' => $error, 'state' => $state])));
    }

    private function tokenError(string $error, int $status = 400): JsonResponse
    {
        return response()->json(['error' => $error], $status)->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /** @param  array<string, string>  $params */
    private function withQuery(string $url, array $params): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params);
    }

    private function codeKey(string $code): string
    {
        return 'oidc:code:'.hash('sha256', $code);
    }

    private function accessTokenKey(string $token): string
    {
        return 'oidc:access_token:'.hash('sha256', $token);
    }
}
