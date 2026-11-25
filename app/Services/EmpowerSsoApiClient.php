<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Credential-verification clients for the "Sign in with talkEHR" and "Sign in with CareCloud" buttons
 * (sso.md §1a). The two are different platforms with different endpoints and password schemes, but
 * answer with the same JSON shape, which is normalised into one EmpowerSsoLoginResult:
 *
 * - talkEHR (MTBC's EmpowerSSOAPI): POST {base}/api/Auth/Login with a username and a password
 *   hashed as an unsalted SHA-512 hex digest.
 * - CareCloud (CCH): POST to the full login URL with an email and a password encrypted with
 *   AES-128-CBC (zero IV, base64) under CCH's shared key — the same scheme as MtbcCardCipher,
 *   confirmed live against CCH's staging service.
 */
class EmpowerSsoApiClient
{
    /** @return array<int, string> the providers whose endpoint is configured, e.g. ['talkehr'] */
    public function enabledProviders(): array
    {
        return array_keys(array_filter(config('services.empower_sso_api.providers', [])));
    }

    public function login(string $username, string $password, string $provider = 'talkehr'): EmpowerSsoLoginResult
    {
        $endpoint = config("services.empower_sso_api.providers.{$provider}");

        if (! $endpoint) {
            return new EmpowerSsoLoginResult(success: false, declineMessage: 'This sign-in option is not configured.');
        }

        // A missing or malformed key is a deployment mistake, not a network problem: say so in the log
        // and don't blame the sign-in service.
        if ($provider === 'carecloud' && strlen((string) config('services.empower_sso_api.cch_aes_key')) !== 16) {
            Log::error('CareCloud SSO is misconfigured: EMPOWER_SSO_CCH_AES_KEY must be set to the 16-character AES key (run php artisan config:clear if it was just added).');

            return new EmpowerSsoLoginResult(success: false, declineMessage: 'This sign-in option is not configured.');
        }

        try {
            $response = $provider === 'carecloud'
                ? $this->carecloudRequest($endpoint, $username, $password)
                : Http::asJson()->timeout(30)->post("{$endpoint}/api/Auth/Login", [
                    'UserName' => $username,
                    'Password' => hash('sha512', $password),
                ]);
        } catch (\Throwable $e) {
            Log::error('SSO login request failed to send', ['provider' => $provider, 'endpoint' => $endpoint, 'exception' => $e::class, 'error' => $e->getMessage()]);

            return new EmpowerSsoLoginResult(success: false, declineMessage: 'Could not reach the sign-in service. Please try again.');
        }

        $json = $response->json();

        if (! $response->successful() || ! ($json['success'] ?? false)) {
            if ($response->status() !== 401) {
                Log::warning('SSO login declined or failed', [
                    'provider' => $provider,
                    'http_status' => $response->status(),
                    'body' => $json ?? $response->body(),
                ]);
            }

            return new EmpowerSsoLoginResult(success: false, declineMessage: $response->serverError()
                ? 'The sign-in service is having trouble right now. Please try again shortly.'
                : ($json['message'] ?? 'Invalid username or password.'));
        }

        $data = $json['data'] ?? null;

        if (! is_array($data) || ! isset($data['email'])) {
            Log::warning('SSO login succeeded but returned no usable data', ['provider' => $provider, 'body' => $json]);

            return new EmpowerSsoLoginResult(success: false, declineMessage: 'Invalid username or password.');
        }

        $practice = $data['practice'] ?? [];
        $address = $practice['address'] ?? [];
        $specialty = trim((string) ($data['specialty'] ?? $data['specialization'] ?? ''));

        // TEMPORARY diagnostic (remove once confirmed): whether the partner sent the practice's phone
        // and address, and whether the practices list carries any details. Presence flags, counts
        // and IDs only, no personal data.
        Log::warning('SSO login practice details', [
            'provider' => $provider,
            'selected_practice_id' => $data['selected_practice_id'] ?? null,
            'practice_object_id' => $practice['id'] ?? null,
            'practice_keys' => array_keys($practice),
            'has_phone' => filled($practice['phone'] ?? null),
            'has_address' => filled($address['street'] ?? null),
            'practices_count' => count($data['practices'] ?? []),
            'practices_item_keys' => array_keys($data['practices'][0] ?? []),
        ]);

        return new EmpowerSsoLoginResult(
            success: true,
            externalUserId: isset($data['external_user_id']) ? (string) $data['external_user_id'] : null,
            email: $data['email'] ?: null,
            firstName: $data['first_name'] ?? null,
            lastName: $data['last_name'] ?? null,
            practiceName: $practice['name'] ?? null,
            practiceAddress: $address['street'] ?? null,
            practiceCity: $address['city'] ?? null,
            practiceState: $address['state'] ?? null,
            practiceZip: $address['zip'] ?? null,
            practicePhone: $practice['phone'] ?? null,
            isPracticeAdmin: (bool) ($data['is_practice_admin'] ?? false),
            practices: $data['practices'] ?? [],
            selectedPracticeId: isset($data['selected_practice_id']) ? (string) $data['selected_practice_id'] : null,
            practiceAdmins: $practice['admins'] ?? [],
            specialization: $specialty !== '' ? $specialty : null,
            accountStatus: isset($practice['account_status']) ? (bool) $practice['account_status'] : null,
            subscriptionAccountId: isset($practice['subscription_account_id']) ? (string) $practice['subscription_account_id'] : null,
            preferredBillingOption: isset($practice['preferred_billing_option']) ? (string) $practice['preferred_billing_option'] : null,
        );
    }

    private function carecloudRequest(string $loginUrl, string $email, string $password): Response
    {
        $cipher = new MtbcCardCipher((string) config('services.empower_sso_api.cch_aes_key'));

        return Http::asJson()->acceptJson()->timeout(30)->post($loginUrl, [
            'email' => $email,
            'password' => $cipher->encrypt($password),
        ]);
    }
}
