<?php

use App\Enums\UserRole;
use App\Mail\WelcomeCredentialsMail;
use App\Models\ActivityLog;
use App\Models\Practice;
use App\Models\User;
use App\Services\EmpowerSsoApiClient;
use App\Services\EmpowerSsoLoginResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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

    /** @var array<int, array{id: string, name: string}> */
    public array $practiceOptions = [];

    public string $pickedPracticeId = '';

    public bool $choosingPractice = false;

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    private function ssoThrottleKey(): string
    {
        return Str::transliterate(Str::lower($this->ssoUsername).'|sso|'.request()->ip());
    }

    /** @return array<int, string> */
    #[Computed]
    public function ssoProviders(): array
    {
        return app(EmpowerSsoApiClient::class)->enabledProviders();
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
            $this->addError('email', "Too many login attempts. Please try again in {$seconds} seconds.");

            return;
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey(), self::LOGIN_DECAY_SECONDS);
            $this->addError('email', 'These credentials do not match our records.');

            return;
        }

        RateLimiter::clear($this->throttleKey());

        if (! Auth::user()->is_active) {
            Auth::logout();
            $this->addError('email', 'This account has been deactivated.');

            return;
        }

        session()->regenerate();

        $user = Auth::user();

        $destination = match (true) {
            $user->isAdmin() => route('admin.dashboard'),
            // A fresh client who has never chosen a package or completed a payment (orders
            // are only ever created after a successful charge — see pay() in the portal
            // component) has nothing to do in the portal yet, so send them to the home page.
            $user->orders()->doesntExist() => route('home'),
            default => route('portal'),
        };

        $this->redirect($destination, navigate: true);
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
        session()->forget('sso_pending');
        $this->choosingPractice = false;
        $this->practiceOptions = [];
        $this->ssoProvider = null;
        $this->ssoUsername = '';
        $this->ssoPassword = '';
        $this->resetErrorBag();
    }

    /**
     * Verifies the typed talkEHR credentials against MTBC's EmpowerSSOAPI (sso.md §1a)
     * and finds-or-creates the matching Empower User + Practice — mirrors
     * Api\SsoController::issueToken()'s provisioning, since EmpowerSSOAPI's success response is
     * the same kind of "this identity is verified" proof the partner-push token flow relies on.
     */
    public function loginViaSso(EmpowerSsoApiClient $client): void
    {
        $this->validate([
            'ssoUsername' => 'required|string',
            'ssoPassword' => 'required|string',
        ]);

        if (RateLimiter::tooManyAttempts($this->ssoThrottleKey(), self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($this->ssoThrottleKey());
            $this->addError('ssoPassword', "Too many login attempts. Please try again in {$seconds} seconds.");

            return;
        }

        $result = $client->login($this->ssoUsername, $this->ssoPassword, $this->ssoProvider ?? 'talkehr');

        if (! $result->success) {
            RateLimiter::hit($this->ssoThrottleKey(), self::LOGIN_DECAY_SECONDS);
            $this->addError('ssoPassword', $result->declineMessage ?? 'Invalid username or password.');

            return;
        }

        RateLimiter::clear($this->ssoThrottleKey());

        if (! $result->email) {
            $this->addError('ssoPassword', "We couldn't retrieve your email from {$this->ssoProviderLabel()}. Please contact support.");

            return;
        }

        // More than one practice: ask which one they're working in before signing in. The verified
        // result waits server-side in the session, never in the browser.
        if (count($result->practices) > 1) {
            session(['sso_pending' => ['result' => $result, 'provider' => $this->ssoProvider]]);
            $this->practiceOptions = collect($result->practices)->map(fn (array $p) => ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? $p['id'])])->all();
            $this->pickedPracticeId = $result->selectedPracticeId ?? $this->practiceOptions[0]['id'];
            $this->choosingPractice = true;

            return;
        }

        $this->completeSsoLogin($result, null);
    }

    public function choosePractice(): void
    {
        $pending = session('sso_pending');
        $ids = collect($pending['result']->practices ?? [])->pluck('id')->map(fn ($id) => (string) $id);

        if (! $pending || ! $ids->contains($this->pickedPracticeId)) {
            $this->addError('pickedPracticeId', 'Please choose one of your practices.');

            return;
        }

        session()->forget('sso_pending');
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
        }

        Auth::login($user);
        session()->regenerate();

        if ($chosenId !== null) {
            session(['sso_active_practice' => ['id' => $chosenId, 'name' => $chosenName]]);
        }

        if (! $user->is_active) {
            Auth::logout();
            $this->addError('ssoPassword', 'This account has been deactivated.');

            return;
        }

        ActivityLog::record(
            'sso.empower_sso_api_login',
            "Logged in via {$this->ssoProviderLabel()} (EmpowerSSOAPI).",
            user: $user,
        );

        $destination = match (true) {
            $user->isAdmin() => route('admin.dashboard'),
            $user->orders()->doesntExist() => route('home'),
            default => route('portal'),
        };

        $this->redirect($destination, navigate: true);
    }
};
?>

<div>
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
    <button type="button" wire:click="backFromSso"
        class="inline-flex items-center gap-1 text-xs font-semibold text-[#5c778d] hover:text-[#0e3a61] transition-colors mb-4">
        &larr; Back
    </button>

    <div class="flex items-center justify-center mb-5">
        <img src="{{ asset('images/'.($ssoProvider === 'carecloud' ? 'carcloud-logo.png' : 'talk-logo.png')) }}" alt=""
            style="width: 10rem;">
    </div>

    @if($choosingPractice)
    <form wire:submit="choosePractice" novalidate>
        <h3 class="text-base font-semibold text-[#0e3a61] mb-1">Which practice are you working in?</h3>
        <p class="text-sm text-[#5c778d] mb-4">Your account is linked to more than one practice.</p>

        <div class="space-y-2 mb-4">
            @foreach($practiceOptions as $option)
            <label wire:key="practice-option-{{ $option['id'] }}" class="flex items-center gap-3 rounded-xl border px-4 py-3 cursor-pointer transition-colors {{ $pickedPracticeId === $option['id'] ? 'border-[#0b9ed0] bg-[#f4fbfe]' : 'border-[#d4e5f1] bg-white hover:bg-[#f9fcff]' }}">
                <input type="radio" wire:model.live="pickedPracticeId" value="{{ $option['id'] }}" class="accent-[#0b9ed0]">
                <span class="text-sm font-semibold text-[#173a59]">{{ $option['name'] }}</span>
            </label>
            @endforeach
            @error('pickedPracticeId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex justify-end">
            <button type="submit"
                class="inline-flex items-center gap-1 rounded bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="choosePractice">
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
            <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="sso-username">Username</label>
            <input wire:model="ssoUsername" id="sso-username" type="text" autocomplete="username" autofocus
                class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
            @error('ssoUsername') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="sso-password">Password</label>
            <input wire:model="ssoPassword" id="sso-password" type="password" autocomplete="current-password"
                class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition">
            @error('ssoPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
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
</div>