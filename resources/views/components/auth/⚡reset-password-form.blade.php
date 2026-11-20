<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Component;

new class extends Component
{
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $reset = false;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => 'required|email:rfc,filter',
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ];
    }

    public function mount(string $token, string $email = ''): void
    {
        $this->token = $token;
        $this->email = $email;
    }

    public function resetPassword(): void
    {
        $this->validate();

        $user = User::where('email', $this->email)->first();

        if ($user && Hash::check($this->password, $user->password)) {
            $this->addError('password', 'Your new password must be different from your current password.');

            return;
        }

        $status = Password::reset(
            [
                'email' => $this->email,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function ($user) {
                $user->forceFill(['password' => Hash::make($this->password), 'must_change_password' => false])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->addError('email', __($status));

            return;
        }

        $this->reset = true;
    }
};
?>

<div>
    @if($reset)
        <div class="text-center py-6">
            <div class="w-14 h-14 rounded-full bg-[#eef8f3] text-[#117a51] inline-flex items-center justify-center text-2xl mb-3 mx-auto">&#10003;</div>
            <h4 class="text-base font-semibold text-[#173a59] mb-1">Password reset</h4>
            <p class="text-sm text-[#5c778d] mb-4">Your password has been reset successfully.</p>
            <a href="{{ route('login') }}" wire:navigate
                class="inline-flex items-center gap-1 rounded bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors">
                Go to Login &rarr;
            </a>
        </div>
    @else
        <form wire:submit="resetPassword" novalidate>
            <div class="mb-4">
                <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="rpf-email">Email address</label>
                <input wire:model="email" id="rpf-email" type="email" autocomplete="email" autofocus
                    class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition"
                    placeholder="you@practice.com">
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="rpf-password">New password</label>
                <input wire:model="password" id="rpf-password" type="password" autocomplete="new-password"
                    class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition"
                    placeholder="••••••••">
                <p class="mt-1 text-xs text-[#5c778d]">At least 12 characters, with upper and lower case letters, a number and a symbol.</p>
                @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="rpf-password-confirmation">Confirm new password</label>
                <input wire:model="password_confirmation" id="rpf-password-confirmation" type="password" autocomplete="new-password"
                    class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition"
                    placeholder="••••••••">
            </div>

            <button type="submit"
                class="inline-flex items-center gap-1 rounded bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed">
                <span wire:loading.remove>Reset Password &rarr;</span>
                <span wire:loading.inline-flex class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Resetting…</span>
            </button>
        </form>
    @endif
</div>
