<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wraps MTBC's EmpowerSSOAPI — a single-endpoint credential-verification API backing the "Sign in
 * with CareCloud"/"Sign in with talkEHR" login buttons (see sso.md §1a). Confirmed live against
 * the UAT sandbox on 2026-10-02: its only route is POST /api/Auth/Login, taking a plain username
 * and a password hashed client-side as an unsalted, unkeyed SHA-512 hex digest — no HMAC, no key
 * material involved despite the similarity to the AES-keyed scheme EmpowerPaymentApiClient uses
 * for card data. We don't yet know whether CareCloud and talkEHR share this exact same endpoint
 * or have separate ones — this is used for both buttons until MTBC says otherwise.
 */
class EmpowerSsoApiClient
{
    public function login(string $username, string $password): EmpowerSsoLoginResult
    {
        $baseUrl = config('services.empower_sso_api.base_url');

        if (! $baseUrl) {
            return new EmpowerSsoLoginResult(success: false, declineMessage: 'This sign-in option is not configured.');
        }

        try {
            $response = Http::asJson()->timeout(30)->post("{$baseUrl}/api/Auth/Login", [
                'UserName' => $username,
                'Password' => hash('sha512', $password),
            ]);
        } catch (\Throwable $e) {
            Log::error('EmpowerSSOAPI login request failed to send', ['error' => $e->getMessage()]);

            return new EmpowerSsoLoginResult(success: false, declineMessage: 'Could not reach the sign-in service. Please try again.');
        }

        $json = $response->json();

        if (! $response->successful() || ! ($json['success'] ?? false)) {
            if ($response->status() !== 401) {
                Log::warning('EmpowerSSOAPI login declined or failed', [
                    'http_status' => $response->status(),
                    'body' => $json ?? $response->body(),
                ]);
            }

            return new EmpowerSsoLoginResult(success: false, declineMessage: $json['message'] ?? 'Invalid username or password.');
        }

        $data = $json['data'][0] ?? null;

        if (! is_array($data)) {
            Log::warning('EmpowerSSOAPI login succeeded but returned no data', ['body' => $json]);

            return new EmpowerSsoLoginResult(success: false, declineMessage: 'Invalid username or password.');
        }

        return new EmpowerSsoLoginResult(
            success: true,
            externalUserId: $data['userId'] ?? null,
            email: $data['email'] ?: null,
            firstName: $data['firstName'] ?? null,
            lastName: $data['lastName'] ?? null,
            practiceName: $data['practiceName'] ?? null,
            practiceAddress: $data['prac_Address'] ?? null,
            practiceCity: $data['prac_city'] ?? null,
            practiceState: $data['prac_State'] ?? null,
            practiceZip: $data['zip'] ?? null,
        );
    }
}
