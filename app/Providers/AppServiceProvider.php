<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fortify only provides two-step verification here; our own Livewire forms handle sign-in.
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        // Every password a person chooses (My Account, password reset, admin user form). The
        // strength meter in components/auth/⚡change-password-form.blade.php shows the same rules.
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols());
    }
}
