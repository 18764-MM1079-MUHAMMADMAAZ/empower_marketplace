<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
    ],

    'clover_mtbc' => [
        'base_url' => env('CLOVER_MTBC_BASE_URL'),
        'username' => env('CLOVER_MTBC_USERNAME'),
        'password' => env('CLOVER_MTBC_PASSWORD'),
        'business_name' => env('CLOVER_MTBC_BUSINESS_NAME', config('app.name')),
    ],

    'empower_payment_api' => [
        'base_url' => env('EMPOWER_PAYMENT_API_BASE_URL'),
        'username' => env('EMPOWER_PAYMENT_API_USERNAME'),
        'password' => env('EMPOWER_PAYMENT_API_PASSWORD'),
        'aes_key' => env('EMPOWER_PAYMENT_API_AES_KEY'),
        // Create_Charge's body-level username/password are the same CLOVER_MTBC_* merchant
        // credentials already used for the existing single-payment flow (confirmed) — not the
        // auth-token credentials above (those are rejected here: "Invalid username or password").
        'charge_username' => env('EMPOWER_PAYMENT_API_CHARGE_USERNAME', env('CLOVER_MTBC_USERNAME')),
        'charge_password' => env('EMPOWER_PAYMENT_API_CHARGE_PASSWORD', env('CLOVER_MTBC_PASSWORD')),
        'business_name' => env('EMPOWER_PAYMENT_API_BUSINESS_NAME', env('CLOVER_MTBC_BUSINESS_NAME', config('app.name'))),
        'trial_reminder_days_before' => env('EMPOWER_PAYMENT_API_TRIAL_REMINDER_DAYS', 3),
    ],

    // MTBC's EmpowerSSOAPI — a single-endpoint credential-verification API for the "Sign in with
    // CareCloud"/"Sign in with talkEHR" login buttons (see sso.md §1a). Separate host and
    // credentials from empower_payment_api above; do not merge the two.
    // One login service per platform: talkEHR and CareCloud (CCH) are different platforms with their
    // own endpoints. A provider's sign-in button only appears once its base URL is configured.
    'empower_sso_api' => [
        'providers' => [
            'talkehr' => env('EMPOWER_SSO_API_BASE_URL'),
            'carecloud' => env('EMPOWER_SSO_CCH_API_BASE_URL'),
        ],
    ],

    'carecloud' => [
        'msa_url' => env('CARECLOUD_MSA_URL', '#'),
    ],

    // Recipient for the daily Finance report (sso.md §10). Falls back to every Admin-role user
    // (SendFinanceDailyReport::recipients()) when unset, same as every other admin notification
    // in this app — set this only if Finance should get it instead of/in addition to that list.
    'finance' => [
        'report_email' => env('FINANCE_REPORT_EMAIL'),
    ],

    // Empower LMS (Moodle). Course ids are the shared training courses every LMS-enabled client gets;
    // the state-specific course is added on top, chosen from the practice's state.
    'moodle' => [
        'base_url' => env('MOODLE_BASE_URL', 'https://education.empowerhci.com'),
        'token' => env('MOODLE_TOKEN'),
        'student_role_id' => env('MOODLE_STUDENT_ROLE_ID', 5),
        'general_course_ids' => [6, 12, 13, 14, 15, 16, 18],
        'state_course_ids' => ['TX' => 7, 'NC' => 8, 'NY' => 9, 'IL' => 10, 'CO' => 11],
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
