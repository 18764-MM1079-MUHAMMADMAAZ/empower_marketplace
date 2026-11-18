<?php

use Laravel\Fortify\Features;

/*
 * Fortify is used ONLY for two-step verification (authenticator app + recovery codes). Its routes
 * are switched off in AppServiceProvider (Fortify::ignoreRoutes()) so they can't replace our own
 * Livewire login/logout; My Account (components/account/⚡two-factor.blade.php) and the login form
 * call Fortify's actions directly, and ask for the password themselves.
 */
return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/',
    'prefix' => '',
    'domain' => null,
    'middleware' => ['web'],
    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        'passkeys' => 'passkeys',
    ],
    'views' => false,

    'features' => [
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => false,
        ]),
    ],
];
