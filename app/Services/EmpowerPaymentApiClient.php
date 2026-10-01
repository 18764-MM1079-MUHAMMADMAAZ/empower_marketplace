<?php

namespace App\Services;

use App\Exceptions\EmpowerPaymentApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Wraps MTBC's Empower Payment API (a separate, newer API from the plain one-shot Clover_Api
 * gateway `CloverChargeService` uses — see plan.md's 2026-09-19 update). This is the only class
 * that talks to it directly.
 *
 * Tokenize/detokenize provide card vaulting for recurring billing: `tokenize()` stores an
 * AES-encrypted card (our own encryption, see MtbcCardCipher) and returns a masked reference to
 * keep; `detokenize()` returns the same encrypted blob back for us to decrypt when a stored card
 * needs to be charged again. `Create_Charge`'s request schema is confirmed (via this API's own
 * swagger.json) to be byte-identical to the old Clover_Api gateway's — raw cardNumber/expMonth/
 * expYear/cvv, no token field at all — so a charge always needs the raw card recovered via
 * detokenize() first.
 */
class EmpowerPaymentApiClient
{
    private const RATE_LIMIT_KEY = 'empower-payment-api';

    private const RATE_LIMIT_MAX_ATTEMPTS = 10;

    private const RATE_LIMIT_DECAY_SECONDS = 60;

    private const TOKEN_CACHE_KEY = 'empower_payment_api:access_token';

    /** MTBC's tokens expire in ~10 minutes; refreshed with a margin rather than cutting it close. */
    private const TOKEN_TTL_SECONDS = 480;

    public function __construct(private MtbcCardCipher $cipher) {}

    /**
     * @return array{token: string, firstSix: ?string, lastFour: ?string, referenceNumber: ?string}
     */
    public function tokenize(string $cardNumber, string $cvv): array
    {
        $response = $this->postAuthenticated('/api/payment/tokenize', [
            'cardNumber' => $this->cipher->encrypt($cardNumber),
            'cvv' => $this->cipher->encrypt($cvv),
        ]);

        $data = $response['data'] ?? null;

        if (! ($response['status'] ?? false) || ! is_array($data) || empty($data['token'])) {
            throw new EmpowerPaymentApiException('Tokenize failed: '.($response['message'] ?? 'unknown error'));
        }

        return [
            'token' => (string) $data['token'],
            'firstSix' => $data['firstSix'] ?? null,
            'lastFour' => $data['lastFour'] ?? null,
            'referenceNumber' => $data['referenceNumber'] ?? null,
        ];
    }

    /**
     * The recovered card number/cvv exist only in the array this returns — callers must let them
     * go out of scope immediately after building a charge request, never log or persist them.
     *
     * @return array{cardNumber: string, cvv: string, referenceNumber: ?string}
     */
    public function detokenize(string $token): array
    {
        $response = $this->postAuthenticated('/api/payment/detokenize', [
            'creditCardTokenNumber' => $token,
        ]);

        $data = $response['data'] ?? null;

        if (! ($response['status'] ?? false) || ! is_array($data) || empty($data['value']) || empty($data['cvv'])) {
            throw new EmpowerPaymentApiException('Detokenize failed: '.($response['message'] ?? 'unknown error'));
        }

        return [
            'cardNumber' => $this->cipher->decrypt($data['value']),
            'cvv' => $this->cipher->decrypt($data['cvv']),
            'referenceNumber' => $data['referenceNumber'] ?? null,
        ];
    }

    /**
     * Posts to Create_Charge with plaintext card fields and username/password in the body —
     * confirmed to be the same payment mechanism/credentials already used for the existing
     * single-payment flow, and the request schema is byte-identical to CloverChargeService's.
     * Unlike that older, separate gateway, this endpoint DOES require the same Bearer token as
     * tokenize/detokenize — confirmed live: without it, the request never reaches the app's own
     * logic and is rejected at the gateway with a bare 401 and empty body; with it, a credential
     * mistake instead comes back as a proper {"status":false,"message":"..."} JSON response.
     *
     * @param  array{name: string, address1: string, city: string, state: string, zip: string, product_Name: string, amount: float, cardNumber: string, expMonth: int, expYear: int, cvv: string}  $params
     */
    public function charge(array $params): ChargeResult
    {
        $baseUrl = config('services.empower_payment_api.base_url');

        if (! $baseUrl) {
            return new ChargeResult(success: false, declineMessage: 'Payment processing is not configured.');
        }

        $this->throttle();

        $body = [
            'username' => config('services.empower_payment_api.charge_username'),
            'password' => config('services.empower_payment_api.charge_password'),
            'business_Name' => config('services.empower_payment_api.business_name'),
            ...$params,
        ];

        try {
            $response = Http::asJson()->timeout(30)->withToken($this->accessToken())->post("{$baseUrl}/api/payment/Create_Charge", $body);

            if ($response->status() === 401) {
                // The cached token may have expired early or been rejected — clear and retry once,
                // same as postAuthenticated().
                Cache::forget(self::TOKEN_CACHE_KEY);
                $response = Http::asJson()->timeout(30)->withToken($this->accessToken())->post("{$baseUrl}/api/payment/Create_Charge", $body);
            }
        } catch (\Throwable $e) {
            Log::error('Empower Payment API charge request failed to send', ['error' => $e->getMessage()]);

            return new ChargeResult(success: false, declineMessage: 'Could not reach the payment processor. Please try again.');
        }

        $json = $response->json();

        if ($response->successful() && ($json['status'] ?? false) === true) {
            // Logged for testing visibility (e.g. the admin "End Trial" tool) — the response body
            // never contains card/cvv, only transaction metadata, so this is safe to log in full.
            Log::info('Empower Payment API charge succeeded', [
                'http_status' => $response->status(),
                'body' => $json,
            ]);

            return new ChargeResult(success: true, transactionId: $json['data']['id'] ?? null);
        }

        Log::warning('Empower Payment API charge declined or failed', [
            'http_status' => $response->status(),
            'body' => $json ?? $response->body(),
        ]);

        return new ChargeResult(success: false, declineMessage: $this->extractErrorMessage($json));
    }

    /** @param  array<string, mixed>  $body
     * @return array<string, mixed> */
    private function postAuthenticated(string $path, array $body): array
    {
        $baseUrl = config('services.empower_payment_api.base_url');

        if (! $baseUrl) {
            throw new EmpowerPaymentApiException('Empower Payment API is not configured.');
        }

        $this->throttle();

        try {
            $response = Http::asJson()->timeout(30)
                ->withToken($this->accessToken())
                ->post("{$baseUrl}{$path}", $body);
        } catch (\Throwable $e) {
            throw new EmpowerPaymentApiException("Request to {$path} failed to send: {$e->getMessage()}", previous: $e);
        }

        if ($response->status() === 401) {
            // The cached token may have expired early or been rejected — clear and retry once.
            Cache::forget(self::TOKEN_CACHE_KEY);

            $response = Http::asJson()->timeout(30)
                ->withToken($this->accessToken())
                ->post("{$baseUrl}{$path}", $body);
        }

        $json = $response->json();

        if (! $response->successful() || ! is_array($json)) {
            throw new EmpowerPaymentApiException("Request to {$path} failed with HTTP {$response->status()}: ".$response->body());
        }

        return $json;
    }

    /**
     * Public because CloverChargeService also needs a Bearer token for its own charge
     * endpoint — MTBC moved that gateway onto this same Empower_Payment_Api host and auth
     * server (confirmed live: the token this issues has `"iss":"Clover_Api"`), so both
     * services share one cached token rather than each fetching/caching their own.
     */
    public function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, self::TOKEN_TTL_SECONDS, function () {
            $baseUrl = config('services.empower_payment_api.base_url');

            $response = Http::asJson()->timeout(30)->post("{$baseUrl}/api/auth/token", [
                'username' => config('services.empower_payment_api.username'),
                'password' => config('services.empower_payment_api.password'),
            ]);

            $token = $response->json('data.accessToken');

            if (! $response->successful() || ! is_string($token) || $token === '') {
                throw new EmpowerPaymentApiException('Failed to obtain an Empower Payment API access token: '.$response->body());
            }

            return $token;
        });
    }

    /** Lets a caller holding a now-rejected cached token (a 401 from either gateway) force a
     *  fresh one on the next accessToken() call, without reaching into the private cache key. */
    public function invalidateAccessToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    /** Protects the shared 10 req/min/IP limit across every caller (tokenize, detokenize, charge,
     *  auth), whether called from a single checkout request or a batch of scheduled renewals. */
    private function throttle(): void
    {
        if (RateLimiter::tooManyAttempts(self::RATE_LIMIT_KEY, self::RATE_LIMIT_MAX_ATTEMPTS)) {
            sleep(RateLimiter::availableIn(self::RATE_LIMIT_KEY));
        }

        RateLimiter::hit(self::RATE_LIMIT_KEY, self::RATE_LIMIT_DECAY_SECONDS);
    }

    /** @param  array<string, mixed>|null  $json */
    private function extractErrorMessage(?array $json): string
    {
        if ($json === null) {
            return 'The payment could not be processed. Please try again.';
        }

        if (isset($json['errors']) && is_array($json['errors'])) {
            $first = collect($json['errors'])->flatten()->first();

            return is_string($first) ? $first : 'Please check your payment details and try again.';
        }

        $message = $json['message'] ?? null;

        if (is_string($message)) {
            $decoded = json_decode($message, true);

            if (is_array($decoded)) {
                return $decoded['error']['message'] ?? $decoded['message'] ?? $message;
            }

            return $message;
        }

        return 'The payment could not be processed. Please try again.';
    }
}
