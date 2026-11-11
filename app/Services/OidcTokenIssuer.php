<?php

namespace App\Services;

use App\Models\User;
use RuntimeException;

/** Signs the OpenID Connect ID tokens Marketplace hands to the Empower LMS (RS256, no JWT library needed). */
class OidcTokenIssuer
{
    /**
     * The identity claims Moodle maps onto its user record. `sub` and `preferred_username` are both
     * derived from the Marketplace user id, never the email, so an email change can't fork the
     * LMS account (spec §4). preferred_username must match MoodleClient's username for the user.
     *
     * @return array<string, mixed>
     */
    public function claimsFor(User $user): array
    {
        $parts = preg_split('/\s+/', trim($user->name), 2);

        return [
            'sub' => (string) $user->id,
            'preferred_username' => $user->lmsUsername(),
            'name' => $user->name,
            'given_name' => $parts[0] !== '' ? $parts[0] : 'Learner',
            'family_name' => $parts[1] ?? '-',
            'email' => strtolower($user->email),
            'email_verified' => true,
        ];
    }

    public function idToken(User $user, string $clientId, ?string $nonce, int $authTime): string
    {
        $now = time();

        return $this->sign(array_filter([
            'iss' => config('oidc.issuer'),
            'aud' => $clientId,
            'iat' => $now,
            'exp' => $now + (int) config('oidc.id_token_ttl'),
            'auth_time' => $authTime,
            'nonce' => $nonce,
        ], fn ($value) => $value !== null) + $this->claimsFor($user));
    }

    /** @param  array<string, mixed>  $payload */
    public function sign(array $payload): string
    {
        $segments = [
            $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->keyId()])),
            $this->base64Url(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];

        if (! openssl_sign(implode('.', $segments), $signature, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the OpenID Connect ID token.');
        }

        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    /** @return array{keys: array<int, array<string, string>>} */
    public function jwks(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public($this->publicKeyPem()));

        return ['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $this->keyId(),
            'n' => $this->base64Url($details['rsa']['n']),
            'e' => $this->base64Url($details['rsa']['e']),
        ]]];
    }

    /** Stable id derived from the public key, so rotating the key changes it automatically. */
    public function keyId(): string
    {
        return substr(hash('sha256', $this->publicKeyPem()), 0, 16);
    }

    /**
     * A fresh 2048-bit RSA key pair. On Windows PHP can't find openssl.cnf on its own, so the copy
     * shipped with PHP (extras/ssl) is used unless OPENSSL_CONF says otherwise.
     *
     * @return array{private: string, public: string}
     */
    public static function generateKeyPair(): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $bundledConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';

        if (! getenv('OPENSSL_CONF') && is_file($bundledConfig)) {
            $options['config'] = $bundledConfig;
        }

        $key = openssl_pkey_new($options) ?: throw new RuntimeException('Could not generate an RSA key: '.openssl_error_string());
        openssl_pkey_export($key, $privatePem, null, $options);

        return ['private' => $privatePem, 'public' => openssl_pkey_get_details($key)['key']];
    }

    public function publicKeyPem(): string
    {
        return $this->read(config('oidc.public_key_path'));
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        return openssl_pkey_get_private($this->read(config('oidc.private_key_path')))
            ?: throw new RuntimeException('The OpenID Connect private key is not a valid RSA key.');
    }

    private function read(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("OpenID Connect key not found at {$path}. Run `php artisan oidc:setup`.");
        }

        return file_get_contents($path);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
