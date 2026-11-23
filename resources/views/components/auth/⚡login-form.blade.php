<?php

use App\Enums\UserRole;
use App\Http\Middleware\RequirePasswordChange;
use App\Mail\WelcomeCredentialsMail;
use App\Models\ActivityLog;
use App\Models\Practice;
use App\Models\User;
use App\Services\EmpowerSsoApiClient;
use App\Services\EmpowerSsoLoginResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    private const MAX_LOGIN_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

    #[Validate('required|email:rfc,filter')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    /** 'talkehr' | 'carecloud' | null — which platform's inline credential form is showing. Each
     *  platform has its own login endpoint (config services.empower_sso_api.providers); only
     *  providers with a configured endpoint can be selected. See sso.md §1a. */
    public ?string $ssoProvider = null;

    public string $ssoUsername = '';

    public string $ssoPassword = '';

    public string $practiceSearch = '';

    public string $pickedPracticeId = '';

    public bool $choosingPractice = false;

    /** Two-step verification: the password (or partner sign-in) checked out and the account has
     *  two-step turned on, so the code screen is showing. Who is signing in waits server-side in
     *  the session (login.two_factor), never in the component's state. */
    public bool $twoFactorChallenge = false;

    public string $twoFactorCode = '';

    public bool $useRecoveryCode = false;

    private const TWO_FACTOR_TIMEOUT_SECONDS = 600;

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    private function ssoThrottleKey(): string
    {
        return Str::transliterate(Str::lower($this->ssoUsername).'|sso|'.request()->ip());
    }

    /** Pre-filled from "?email=" (e.g. checkout's "this email already has an account" link). */
    public function mount(): void
    {
        $email = request()->query('email');
        $this->email = is_string($email) ? $email : '';
    }

    /** @return array<int, string> */
    #[Computed]
    public function ssoProviders(): array
    {
        return app(EmpowerSsoApiClient::class)->enabledProviders();
    }

    /** Shows an error both inline under the field and as a toast, so it can't be missed. */
    private function failWith(string $field, string $message): void
    {
        $this->addError($field, $message);
        $this->dispatch('toast', message: $message, type: 'error');
    }

    /** Where to send a freshly signed-in user, and whether they're now in the "buy a package" flow.
     *  Admins go to their dashboard; a client mid-purchase carries on to that package's checkout; a
     *  client who has nothing yet is sent to the pricing section to choose a package first.
     *
     *  @return array{0: string, 1: bool} [url, isPurchasing]
     */
    private function destinationFor(User $user, bool $newClientToPricing = false): array
    {
        // Arrived from the Empower LMS (OpenID Connect sign-in): finish that handoff back to Moodle.
        if ($oidcResume = session()->pull('oidc_authorize_url')) {
            return [$oidcResume, false];
        }

        if ($user->isAdmin()) {
            return [route('admin.dashboard'), false];
        }

        if ($package = session('intended_package')) {
            return [route('portal', array_filter([
                'package' => $package,
                'billing_cycle' => session('intended_billing_cycle'),
                'billable_providers' => session('intended_billable_providers'),
            ])), true];
        }

        if ($user->orders()->doesntExist()) {
            return [$newClientToPricing ? route('home').'#pricing' : route('home'), $newClientToPricing];
        }

        return [route('portal'), false];
    }

    private function ssoProviderLabel(): string
    {
        return match ($this->ssoProvider) {
            'talkehr' => 'talkEHR',
            'carecloud' => 'CareCloud',
            default => 'your account',
        };
    }

    public function login(): void
    {
        $this->validate();

        if (RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($this->throttleKey());
            $this->failWith('email', "Too many login attempts. Please try again in {$seconds} seconds.");

            return;
        }

        // Check the password without signing in yet: an account with two-step verification still
        // needs its code first.
        if (! Auth::validate(['email' => $this->email, 'password' => $this->password])) {
            RateLimiter::hit($this->throttleKey(), self::LOGIN_DECAY_SECONDS);

            // Shown in that account's Activity Log (My Account). Only for a registered email, and the
            // message below stays the same either way, so it doesn't reveal which emails exist.
            if ($attempted = User::where('email', $this->email)->first()) {
                ActivityLog::record('auth.login_failed', 'Sign-in attempt: incorrect password', user: $attempted);
            }

            $this->failWith('email', 'These credentials do not match our records.');

            return;
        }

        RateLimiter::clear($this->throttleKey());

        /** @var User $user */
        $user = Auth::getLastAttempted();

        if (! $user->is_active) {
            $this->failWith('email', 'This account has been deactivated.');

            return;
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $this->startTwoFactorChallenge($user, $this->remember, sso: null);

            return;
        }

        $this->signIn($user, $this->remember, sso: null);
    }

    /**
     * Finishes any sign-in through this form, once the password (or partner sign-in) and, if it's
     * turned on, the two-step code have been checked.
     *
     * @param  array{label: string, practice: array{id: string, name: ?string}|null}|null  $sso
     */
    private function signIn(User $user, bool $remember, ?array $sso): void
    {
        Auth::login($user, $remember);
        session()->regenerate();

        if ($sso) {
            if ($sso['practice']) {
                session(['sso_active_practice' => $sso['practice']]);

                // The partner only tells us practice names at sign-in: keep them on this client's
                // orders for that practice so admins see the real name (Admin › Users).
                if (filled($sso['practice']['name'] ?? null)) {
                    $user->orders()->where('external_practice_id', $sso['practice']['id'])
                        ->update(['external_practice_name' => $sso['practice']['name']]);
                }
            }

            ActivityLog::record('sso.empower_sso_api_login', "Signed in via {$sso['label']} SSO", user: $user);
        } else {
            ActivityLog::record('auth.login', 'Signed in with email & password', user: $user);
        }

        // My Account › Where you're signed in shows how each device signed in.
        session(['sign_in_method' => $sso ? $sso['label'] : 'password']);

        // Logged in with the temporary password from the welcome email: choose a new one first
        // (spec §4). RequirePasswordChange keeps them on My Account until they do; any LMS
        // handoff stays in the session and continues afterwards.
        if (! $sso && $user->must_change_password) {
            session([RequirePasswordChange::SESSION_KEY => true]);
            session()->flash('toast', 'Welcome! Please choose a new password to finish signing in.');
            session()->flash('toast_type', 'success');

            $this->redirect(route('account').'#security', navigate: true);

            return;
        }

        [$destination, $isPurchasing] = $this->destinationFor($user, newClientToPricing: true);

        // Survives the redirect: the layout's toast reads this flash on the next page.
        if ($isPurchasing || $sso) {
            session()->flash('toast', $isPurchasing ? 'Login successful - Please continue with purchasing the package' : 'Login successful');
            session()->flash('toast_type', 'success');
        }

        // A full page load for the LMS handoff: it ends in a redirect to another site, which SPA navigation can't follow.
        $this->redirect($destination, navigate: ! str_starts_with($destination, route('oidc.authorize')));
    }

    /** @param  array{label: string, practice: array{id: string, name: ?string}|null}|null  $sso */
    private function startTwoFactorChallenge(User $user, bool $remember, ?array $sso): void
    {
        session(['login.two_factor' => [
            'user_id' => $user->id,
            'remember' => $remember,
            'sso' => $sso,
            'expires_at' => now()->getTimestamp() + self::TWO_FACTOR_TIMEOUT_SECONDS,
        ]]);

        $this->password = '';
        $this->ssoPassword = '';
        $this->twoFactorCode = '';
        $this->useRecoveryCode = false;
        $this->twoFactorChallenge = true;
        $this->resetErrorBag();
    }

    public function toggleRecoveryCode(): void
    {
        $this->useRecoveryCode = ! $this->useRecoveryCode;
        $this->twoFactorCode = '';
        $this->resetErrorBag();
    }

    public function cancelTwoFactor(): void
    {
        session()->forget('login.two_factor');
        $this->twoFactorChallenge = false;
        $this->twoFactorCode = '';
        $this->useRecoveryCode = false;
        $this->resetErrorBag();
    }

    public function verifyTwoFactor(TwoFactorAuthenticationProvider $provider): void
    {
        $this->resetErrorBag();

        $pending = session('login.two_factor');
        $user = $pending ? User::find($pending['user_id']) : null;

        if (! $user || $pending['expires_at'] < now()->getTimestamp() || ! $user->is_active || ! $user->hasEnabledTwoFactorAuthentication()) {
            $this->cancelTwoFactor();
            $this->failWith('email', 'Your sign-in timed out. Please sign in again.');

            return;
        }

        $key = 'two-factor|'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_LOGIN_ATTEMPTS)) {
            $this->failWith('twoFactorCode', 'Too many tries. Please wait '.RateLimiter::availableIn($key).' seconds and try again.');

            return;
        }

        $code = trim($this->twoFactorCode);

        if ($this->useRecoveryCode) {
            $match = collect($user->recoveryCodes())->first(fn (string $recovery) => $code !== '' && hash_equals($recovery, $code));

            if ($match) {
                $user->replaceRecoveryCode($match);
            }

            $valid = $match !== null;
        } else {
            $code = preg_replace('/\D/', '', $code);
            $valid = $code !== '' && $provider->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code);
        }

        if (! $valid) {
            RateLimiter::hit($key, self::LOGIN_DECAY_SECONDS);
            $this->failWith('twoFactorCode', $this->useRecoveryCode ? "That recovery code didn't work." : "That code didn't work. Check your authenticator app and try again.");

            return;
        }

        RateLimiter::clear($key);
        session()->forget('login.two_factor');

        if ($this->useRecoveryCode) {
            ActivityLog::record('auth.two_factor_recovery_code_used', 'Signed in with a two-step recovery code.', user: $user);
        }

        $this->signIn($user, (bool) $pending['remember'], $pending['sso']);
    }

    public function selectSsoProvider(string $provider): void
    {
        if (! in_array($provider, app(EmpowerSsoApiClient::class)->enabledProviders(), true)) {
            return;
        }

        $this->ssoProvider = $provider;
        $this->ssoUsername = '';
        $this->ssoPassword = '';
        $this->resetErrorBag();
    }

    public function backFromSso(): void
    {
        $this->forgetPending();
        $this->choosingPractice = false;
        $this->practiceSearch = '';
        $this->ssoProvider = null;
        $this->ssoUsername = '';
        $this->ssoPassword = '';
        $this->pickedPracticeId = '';
        $this->resetErrorBag();
    }

    public function backToSsoCredentials(): void
    {
        $this->choosingPractice = false;
        $this->practiceSearch = '';
        $this->resetErrorBag();

        if ($pending = $this->pending()) {
            $this->pickedPracticeId = $pending['result']->selectedPracticeId ?? (string) $pending['result']->practices[0]['id'];
        }
    }

    /**
     * Verifies the typed talkEHR credentials against MTBC's EmpowerSSOAPI (sso.md §1a)
     * and finds-or-creates the matching Empower User + Practice — mirrors
     * Api\SsoController::issueToken()'s provisioning, since EmpowerSSOAPI's success response is
     * the same kind of "this identity is verified" proof the partner-push token flow relies on.
     */
    public function loginViaSso(EmpowerSsoApiClient $client): void
    {
        try {
            $this->validate([
                'ssoUsername' => $this->ssoProvider === 'carecloud' ? 'required|email' : 'required|string',
                'ssoPassword' => 'required|string',
            ]);
        } catch (ValidationException $e) {
            $this->dispatch('toast', message: $this->ssoProvider === 'carecloud' ? 'Please enter your email and password.' : 'Please enter your username and password.', type: 'error');

            throw $e;
        }

        if (RateLimiter::tooManyAttempts($this->ssoThrottleKey(), self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($this->ssoThrottleKey());
            $this->failWith('ssoPassword', "Too many login attempts. Please try again in {$seconds} seconds.");

            return;
        }

        $result = $client->login($this->ssoUsername, $this->ssoPassword, $this->ssoProvider ?? 'talkehr');

        if (! $result->success) {
            RateLimiter::hit($this->ssoThrottleKey(), self::LOGIN_DECAY_SECONDS);
            $this->failWith('ssoPassword', $result->declineMessage ?? 'Invalid username or password.');

            return;
        }

        RateLimiter::clear($this->ssoThrottleKey());

        if (! $result->email) {
            $this->failWith('ssoPassword', "We couldn't retrieve your email from {$this->ssoProviderLabel()}. Please contact support.");

            return;
        }

        // More than one practice: ask which one they're working in before signing in. The verified
        // result waits server-side in the session, never in the browser.
        if (count($result->practices) > 1) {
            // A CareCloud user can belong to thousands of practices, so the verified result is parked
            // in the cache (10 minutes) rather than in the session or the component's own state.
            $key = 'sso_pending:'.Str::random(40);
            // Plain arrays only: Laravel's cache refuses to unserialize objects by default.
            Cache::put($key, ['result' => get_object_vars($result), 'provider' => $this->ssoProvider], now()->addMinutes(10));
            session(['sso_pending_key' => $key]);

            $this->pickedPracticeId = $result->selectedPracticeId ?? (string) $result->practices[0]['id'];
            $this->practiceSearch = '';
            $this->choosingPractice = true;

            return;
        }

        $this->completeSsoLogin($result, null);
    }

    /** @return array{result: EmpowerSsoLoginResult, provider: string}|null */
    private function pending(): ?array
    {
        $key = session('sso_pending_key');
        $data = $key ? Cache::get($key) : null;

        return $data ? ['result' => new EmpowerSsoLoginResult(...$data['result']), 'provider' => $data['provider']] : null;
    }

    private function forgetPending(): void
    {
        if ($key = session('sso_pending_key')) {
            Cache::forget($key);
        }

        session()->forget('sso_pending_key');
    }

    /** The practices to pick from, filtered by the search box and capped so a huge list stays usable.
     *
     *  @return array{items: array<int, array{id: string, name: string}>, total: int, matched: int}
     */
    #[Computed]
    public function practiceChoices(): array
    {
        $all = collect($this->pending()['result']->practices ?? [])
            ->map(fn (array $p) => ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? $p['id'])]);

        $search = mb_strtolower(trim($this->practiceSearch));
        $matched = $search === '' ? $all : $all->filter(fn (array $p) => str_contains(mb_strtolower($p['name']), $search));

        // Always keep the currently picked practice visible, even if it isn't in the first page.
        $items = $matched->take(40);
        $picked = $all->firstWhere('id', $this->pickedPracticeId);

        if ($picked && ! $items->contains('id', $picked['id']) && $search === '') {
            $items = $items->prepend($picked);
        }

        return ['items' => $items->values()->all(), 'total' => $all->count(), 'matched' => $matched->count()];
    }

    public function choosePractice(): void
    {
        $pending = $this->pending();
        $ids = collect($pending['result']->practices ?? [])->pluck('id')->map(fn ($id) => (string) $id);

        if (! $pending || ! $ids->contains($this->pickedPracticeId)) {
            $this->failWith('pickedPracticeId', 'Please choose one of your practices.');

            return;
        }

        $this->forgetPending();
        $this->ssoProvider = $pending['provider'];

        $this->completeSsoLogin($pending['result'], $this->pickedPracticeId);
    }

    private function completeSsoLogin(EmpowerSsoLoginResult $result, ?string $chosenPracticeId): void
    {
        // Only the practice the login username belongs to comes back with full details; for any
        // other picked practice we know just its id and name.
        $chosenId = $chosenPracticeId ?? $result->selectedPracticeId;
        $detailsAvailable = $chosenId === null || $chosenId === $result->selectedPracticeId;
        $chosenName = collect($result->practices)->firstWhere('id', $chosenId)['name'] ?? $result->practiceName;

        // Match by the partner's permanent user ID first (emails change), then by email.
        $user = ($result->externalUserId ? User::where('external_id', $result->externalUserId)->first() : null)
            ?? User::where('email', $result->email)->first();

        $sourceSystem = $this->ssoProvider === 'carecloud' ? 'cch' : 'talkehr';

        if (! $user) {
            $generatedPassword = Str::password(16);

            $user = User::create([
                'name' => trim("{$result->firstName} {$result->lastName}") ?: $result->email,
                'email' => $result->email,
                'password' => $generatedPassword,
                'role' => UserRole::Client,
                'is_active' => true,
                'external_id' => $result->externalUserId,
                'is_practice_admin' => $result->isPracticeAdmin,
            ]);

            Practice::create([
                'user_id' => $user->id,
                'source_system' => $sourceSystem,
                'external_practice_id' => $chosenId,
                'phone' => $detailsAvailable ? $result->practicePhone : null,
                'specialty' => Practice::normalizeSpecialty($result->specialization),
                'account_status' => $detailsAvailable ? $result->accountStatus : null,
                'subscription_account_id' => $detailsAvailable ? $result->subscriptionAccountId : null,
                'preferred_billing_option' => $detailsAvailable ? $result->preferredBillingOption : null,
                'name' => ($detailsAvailable ? $result->practiceName : $chosenName) ?? '',
                'address' => $detailsAvailable
                    ? (collect([$result->practiceAddress, $result->practiceCity, $result->practiceState, $result->practiceZip])->filter()->implode(', ') ?: null)
                    : null,
            ]);

            try {
                Mail::to($user->email)->queue(new WelcomeCredentialsMail($user, $generatedPassword));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($user->wasRecentlyCreated === false) {
            $user->update(array_filter([
                'external_id' => $result->externalUserId,
                'is_practice_admin' => $result->isPracticeAdmin,
            ], fn ($value) => $value !== null));

            // Link an existing practice to its partner record the first time we see it.
            $practice = $user->practice;

            if ($practice && $practice->external_practice_id === null && $chosenId) {
                $practice->update(array_filter([
                    'source_system' => $practice->source_system ?? $sourceSystem,
                    'external_practice_id' => $chosenId,
                    'phone' => $practice->phone ?? $result->practicePhone,
                ]));
            }

            if ($practice && $practice->specialty === null && ($specialty = Practice::normalizeSpecialty($result->specialization))) {
                $practice->update(['specialty' => $specialty]);
            }

            // Billing standing can change on the partner's side, so refresh it each time we have it.
            if ($practice && $detailsAvailable && ($practice->external_practice_id === null || $practice->external_practice_id === $chosenId)) {
                $practice->update(array_filter([
                    'account_status' => $result->accountStatus,
                    'subscription_account_id' => $result->subscriptionAccountId,
                    'preferred_billing_option' => $result->preferredBillingOption,
                ], fn ($value) => $value !== null));
            }
        }

        if (! $user->is_active) {
            $this->failWith('ssoPassword', 'This account has been deactivated.');

            return;
        }

        $sso = [
            'label' => $this->ssoProviderLabel(),
            'practice' => $chosenId !== null ? ['id' => $chosenId, 'name' => $chosenName] : null,
        ];

        // Two-step verification is part of signing in here too (the partner only checked its own
        // password). Partner-pushed sign-ins (/sso/consume) skip it, see my-account-plan.md.
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $this->startTwoFactorChallenge($user, remember: false, sso: $sso);

            return;
        }

        $this->signIn($user, remember: false, sso: $sso);
    }
};
?>

<div>
    @if($twoFactorChallenge)
    {{-- Two-step verification code (after a correct password or partner sign-in) --}}
    <form wire:submit="verifyTwoFactor" novalidate data-two-factor-challenge>
        <h2 class="text-base font-bold text-[#173a59] mb-1">Two-step verification</h2>
        <p class="text-sm text-[#5c778d] mb-4">
            @if($useRecoveryCode)
            Enter one of the recovery codes you saved when you turned on two-step verification. Each code works once.
            @else
            Open your authenticator app and enter the 6-digit code for the Empower portal.
            @endif
        </p>

        <div class="mb-4">
            <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="lf-two-factor-code">{{ $useRecoveryCode ? 'Recovery code' : '6-digit code' }}</label>
            <input wire:model="twoFactorCode" id="lf-two-factor-code" wire:key="two-factor-input-{{ $useRecoveryCode ? 'recovery' : 'code' }}"
                type="text" x-init="$el.focus()"
                @if($useRecoveryCode) autocomplete="off" placeholder="abcde12345-fghij67890" @else inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123456" @endif
                class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition font-mono {{ $useRecoveryCode ? '' : 'tracking-[0.3em]' }}">
            @error('twoFactorCode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <button type="button" wire:click="toggleRecoveryCode" class="mb-4 text-xs font-semibold text-[#0b9ed0] hover:text-[#0e3a61]">
            {{ $useRecoveryCode ? 'Use a code from my authenticator app' : "Can't use your app? Use a recovery code" }}
        </button>

        <div class="flex items-center justify-between gap-3">
            <button type="button" wire:click="cancelTwoFactor"
                class="inline-flex items-center gap-2 rounded bg-white px-4 py-2 text-sm font-semibold text-[#1c3457] hover:bg-[#f5f7fa] transition-colors">
                <span aria-hidden="true" class="text-base font-black leading-none">&#8592;</span>
                <span>Back</span>
            </button>
            <button type="submit"
                class="inline-flex items-center gap-1 rounded bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed">
                <span wire:loading.remove>Verify &rarr;</span>
                <span wire:loading.inline-flex class="inline-flex items-center gap-1.5">
                    <x-spinner class="h-3.5 w-3.5" /> Checking…
                </span>
            </button>
        </div>
    </form>
    @else
    @if($ssoProvider === null)
    {{-- SSO sign-in options — verified server-to-server against MTBC's EmpowerSSOAPI once a
    provider is picked below; see sso.md §1a. --}}
    <div class="flex items-center gap-3 mb-5">
        <div class="h-px flex-1 bg-[#e5edf3]"></div>
        <span class="text-xs font-semibold text-[#5c778d] uppercase tracking-wide">Sign in with</span>
        <div class="h-px flex-1 bg-[#e5edf3]"></div>
    </div>
    @php $ssoLogos = ['talkehr' => 'talk-logo.png', 'carecloud' => 'carcloud-logo.png']; @endphp
    <div class="grid {{ count($this->ssoProviders) > 1 ? 'grid-cols-2' : 'grid-cols-1' }} gap-2.5 mb-5">
        @foreach($this->ssoProviders as $provider)
        <button type="button" wire:click="selectSsoProvider('{{ $provider }}')" wire:key="sso-provider-{{ $provider }}"
            class="flex items-center justify-center gap-1.5 rounded-xl border border-[#d4e5f1] bg-white px-3 py-2.5 text-xs font-semibold text-[#173a59] hover:border-[#0b9ed0] hover:bg-[#f4f9fc] transition-colors">
            <img src="{{ asset('images/'.$ssoLogos[$provider]) }}" alt="" class="flex-shrink-0" style="width: 12rem;">
        </button>
        @endforeach
    </div>

    <div class="flex items-center gap-3 mb-5">
        <div class="h-px flex-1 bg-[#e5edf3]"></div>
        <span class="text-xs font-semibold text-[#5c778d] uppercase tracking-wide">Or sign in with email</span>
        <div class="h-px flex-1 bg-[#e5edf3]"></div>
    </div>
    @else
    {{-- Inline talkEHR credential form — submits to loginViaSso(), which verifies
    against EmpowerSSOAPI server-to-server and signs the user straight in. No redirect: the user
    never leaves this page/modal. --}}
    <div class="mb-4 flex items-center justify-start">
        <img src="{{ asset('images/'.($ssoProvider === 'carecloud' ? 'carcloud-logo.png' : 'talk-logo.png')) }}" alt=""
            class="h-10 w-auto object-contain">
    </div>

    @if($choosingPractice)
    <form wire:submit="choosePractice" novalidate>
        <div class="mb-3 text-left">
            <h3 class="text-base font-semibold text-[#0e3a61]">Which practice are you working in?</h3>
        </div>
        <p class="text-sm text-[#5c778d] mb-4">Your account is linked to more than one practice.</p>

        @if($this->practiceChoices['total'] > 8)
        <input wire:model.live.debounce.300ms="practiceSearch" type="search"
            placeholder="Search your {{ number_format($this->practiceChoices['total']) }} practices…"
            aria-label="Search practices"
            class="w-full mb-3 rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
        @endif

        <div class="space-y-2 mb-2 max-h-72 overflow-y-auto pr-1">
            @forelse($this->practiceChoices['items'] as $option)
            <label wire:key="practice-option-{{ $option['id'] }}"
                class="flex items-center gap-3 rounded-xl border px-4 py-3 cursor-pointer transition-colors {{ $pickedPracticeId === $option['id'] ? 'border-[#0b9ed0] bg-[#f4fbfe]' : 'border-[#d4e5f1] bg-white hover:bg-[#f9fcff]' }}">
                <input type="radio" wire:model.live="pickedPracticeId" value="{{ $option['id'] }}"
                    class="accent-[#0b9ed0]">
                <span class="text-sm font-semibold text-[#173a59]">{{ $option['name'] }}</span>
            </label>
            @empty
            <p class="px-1 py-3 text-sm text-[#5c778d]">No practices match "{{ $practiceSearch }}".</p>
            @endforelse
        </div>
        @if($this->practiceChoices['matched'] > count($this->practiceChoices['items']))
        <p class="mb-3 text-xs text-[#5c778d]">Showing the first {{ count($this->practiceChoices['items']) }} of {{
            number_format($this->practiceChoices['matched']) }}. Type to narrow the list.</p>
        @endif
        @error('pickedPracticeId') <p class="mb-3 text-xs text-red-600">{{ $message }}</p> @enderror
        <div class="mb-2"></div>

        <div class="flex items-center justify-between gap-3">
            <button type="button" wire:click="backToSsoCredentials"
                class="inline-flex items-center gap-2 rounded bg-white px-4 py-2 text-sm font-semibold text-[#1c3457] hover:bg-[#f5f7fa] transition-colors">
                <span aria-hidden="true" class="text-base font-black leading-none">&#8592;</span>
                <span>Back</span>
            </button>

            <button type="submit"
                class="inline-flex items-center gap-1 rounded bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed"
                wire:target="choosePractice">
                <span wire:loading.remove wire:target="choosePractice">Continue &rarr;</span>
                <span wire:loading.inline-flex wire:target="choosePractice" class="inline-flex items-center gap-1.5">
                    <x-spinner class="h-3.5 w-3.5" /> Signing in…
                </span>
            </button>
        </div>
    </form>
    @else
    <form wire:submit="loginViaSso" novalidate>
        <div class="mb-4">
            <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="sso-username">{{ $ssoProvider ===
                'carecloud' ? 'Email' : 'Username' }}</label>
            <input wire:model="ssoUsername" id="sso-username"
                type="{{ $ssoProvider === 'carecloud' ? 'email' : 'text' }}"
                autocomplete="{{ $ssoProvider === 'carecloud' ? 'email' : 'username' }}" autofocus
                class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
            @error('ssoUsername') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="sso-password">Password</label>
            <input wire:model="ssoPassword" id="sso-password" type="password" autocomplete="current-password"
                class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
            @error('ssoPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center justify-between gap-3">
            <button type="button" wire:click="backFromSso"
                class="inline-flex items-center gap-2 rounded bg-white px-4 py-2 text-sm font-semibold text-[#1c3457] hover:bg-[#f5f7fa] transition-colors">
                <span aria-hidden="true" class="text-base font-black leading-none">&#8592;</span>
                <span>Back</span>
            </button>

            <button type="submit"
                class="inline-flex items-center gap-1 rounded bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed">
                <span wire:loading.remove>Log In &rarr;</span>
                <span wire:loading.inline-flex class="inline-flex items-center gap-1.5">
                    <x-spinner class="h-3.5 w-3.5" /> Signing in…
                </span>
            </button>
        </div>
    </form>
    @endif
    @endif

    @if($ssoProvider === null)
    <form wire:submit="login" novalidate>
        <div class="mb-4">
            <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="lf-email">Email address</label>
            <input wire:model="email" id="lf-email" type="email" autocomplete="email" autofocus
                class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition"
                placeholder="you@practice.com">
            @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <div class="flex items-center justify-between mb-1.5">
                <label class="block text-sm font-medium text-[#173a59]" for="lf-password">Password</label>
                <a href="{{ route('password.request') }}" wire:navigate
                    class="text-xs font-semibold text-[#0b9ed0] hover:text-[#0e3a61] transition-colors">Forgot
                    password?</a>
            </div>
            <input wire:model="password" id="lf-password" type="password" autocomplete="current-password"
                class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition"
                placeholder="••••••••">
            @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label class="flex items-center gap-2 cursor-pointer">
                <input wire:model="remember" type="checkbox"
                    class="h-4 w-4 rounded border-[#d4e5f1] text-[#0b9ed0] focus:ring-[#0b9ed0]">
                <span class="text-sm text-[#5c778d]">Remember me</span>
            </label>
        </div>

        <div class="flex justify-end">
            <button type="submit"
                class="inline-flex items-center gap-1 rounded bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed">
                <span wire:loading.remove>Log In &rarr;</span>
                <span wire:loading.inline-flex class="inline-flex items-center gap-1.5">
                    <x-spinner class="h-3.5 w-3.5" /> Signing in…
                </span>
            </button>
        </div>
    </form>
    @endif
    @endif
</div>