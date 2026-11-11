<?php

// Marketplace as an OpenID Connect provider for the Empower LMS (Moodle, IOMAD "OpenID Connect"
// plugin, auth_iomadoidc). Moodle is the only client. Run `php artisan oidc:setup` to generate the
// signing key and the client id/secret, then paste them into Moodle's "IdP and authentication" page.

$moodleBase = rtrim(env('MOODLE_BASE_URL', 'https://education.empowerhci.com'), '/');

return [
    // Must match exactly what Moodle sees in the ID token's "iss" claim.
    'issuer' => rtrim(env('OIDC_ISSUER', env('APP_URL', 'http://localhost')), '/'),

    // RS256 signing key pair; *.key files in storage/ are git-ignored.
    'private_key_path' => env('OIDC_PRIVATE_KEY_PATH', storage_path('oidc-private.key')),
    'public_key_path' => env('OIDC_PUBLIC_KEY_PATH', storage_path('oidc-public.key')),

    // Authorization codes are single use and short-lived (spec §5: valid 5 minutes).
    'code_ttl' => 300,
    'id_token_ttl' => 300,
    'access_token_ttl' => 3600,

    'moodle' => [
        'client_id' => env('OIDC_MOODLE_CLIENT_ID'),
        'client_secret' => env('OIDC_MOODLE_CLIENT_SECRET'),
        'base_url' => $moodleBase,
        // The plugin's "Redirect URI" (read-only on its settings page).
        'redirect_uri' => env('OIDC_MOODLE_REDIRECT_URI', $moodleBase.'/auth/iomadoidc/'),
        // The plugin's "Front-channel Logout URL" — loaded in a hidden iframe on Marketplace logout.
        'frontchannel_logout_uri' => env('OIDC_MOODLE_FRONTCHANNEL_LOGOUT_URI', $moodleBase.'/auth/iomadoidc/logout.php'),
        // Where the "Access LMS" button lands; Moodle's Force redirect bounces it through /oauth/authorize.
        'launch_url' => env('OIDC_MOODLE_LAUNCH_URL', $moodleBase.'/my/'),
    ],
];
