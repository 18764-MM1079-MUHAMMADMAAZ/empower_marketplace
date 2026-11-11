<?php

namespace App\Console\Commands;

use App\Services\OidcTokenIssuer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** One-time setup for Empower LMS single sign-on: creates the ID-token signing key and prints the
 *  client credentials to put in .env and in Moodle's "IdP and authentication" page. */
#[Signature('oidc:setup {--force : Replace an existing signing key (key rotation)}')]
#[Description('Generate the OpenID Connect signing key and Moodle client credentials for Empower LMS single sign-on')]
class SetupOidc extends Command
{
    public function handle(): int
    {
        $privatePath = config('oidc.private_key_path');
        $publicPath = config('oidc.public_key_path');

        if (is_file($privatePath) && ! $this->option('force')) {
            $this->components->info("Signing key already exists at {$privatePath} (use --force to replace it).");
        } else {
            $keys = OidcTokenIssuer::generateKeyPair();
            file_put_contents($privatePath, $keys['private']);
            file_put_contents($publicPath, $keys['public']);
            @chmod($privatePath, 0600);
            $this->components->info("Signing key written to {$privatePath}.");
        }

        $clientId = config('oidc.moodle.client_id') ?: 'empower-lms';
        $clientSecret = config('oidc.moodle.client_secret') ?: Str::random(48);

        if (! config('oidc.moodle.client_secret')) {
            $this->newLine();
            $this->line('Add these to .env:');
            $this->line("OIDC_MOODLE_CLIENT_ID={$clientId}");
            $this->line("OIDC_MOODLE_CLIENT_SECRET={$clientSecret}");
        }

        $this->newLine();
        $this->line('Moodle → Plugins → Authentication → OpenID Connect → IdP and authentication:');
        $this->table(['Setting', 'Value'], [
            ['Identity Provider (IdP) Type', 'Other'],
            ['Application ID', $clientId],
            ['Client authentication method', 'Secret'],
            ['Client Secret', $clientSecret],
            ['Authorization Endpoint', route('oidc.authorize')],
            ['Token Endpoint', route('oidc.token')],
            ['Scope', 'openid profile email'],
            ['IdP Logout Endpoint', route('oidc.logout')],
        ]);

        return self::SUCCESS;
    }
}
