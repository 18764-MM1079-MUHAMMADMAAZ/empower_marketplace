<?php

use App\Services\UserSessions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

new class extends Component
{
    public string $currentPassword = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $updated = false;

    /** How many other devices were signed out by the last change (shown in the success note). */
    public int $signedOutDevices = 0;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'currentPassword' => 'required|current_password',
            'password' => ['required', 'string', 'confirmed', 'different:currentPassword', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.different' => 'Your new password must be different from your current password.',
        ];
    }

    public function updatePassword(): void
    {
        $this->validate();

        $user = Auth::user();
        $user->forceFill(['password' => Hash::make($this->password)])->save();

        // Anyone signed in elsewhere with the old password is signed out.
        $this->signedOutDevices = UserSessions::for($user)->signOutOthers();

        $this->reset(['currentPassword', 'password', 'password_confirmation']);
        $this->updated = true;
        $this->dispatch('password-updated');
    }
};
?>

<div>
    @if($updated)
        <div class="flex items-center gap-3 rounded-xl bg-[#eef8f3] border border-[#bfe3d2] px-4 py-3.5 mb-4">
            <span class="text-[#117a51]">&#10003;</span>
            <p class="text-sm font-semibold text-[#0f7a4f]">
                Your password has been updated.
                @if($signedOutDevices > 0)
                <span class="block font-normal">We signed you out on {{ $signedOutDevices }} other {{ Str::plural('device', $signedOutDevices) }}.</span>
                @endif
            </p>
        </div>
    @endif

    <form wire:submit="updatePassword" novalidate
        x-data="{
            pw: '',
            get rules() {
                return [
                    { label: 'At least 12 characters', met: this.pw.length >= 12 },
                    { label: 'Upper and lower case letters', met: /[a-z]/.test(this.pw) && /[A-Z]/.test(this.pw) },
                    { label: 'A number', met: /[0-9]/.test(this.pw) },
                    { label: 'A symbol, like ! ? # or %', met: /[^A-Za-z0-9]/.test(this.pw) },
                ];
            },
            get strength() {
                const met = this.rules.filter(r => r.met).length;
                const strong = met === 4 && this.pw.length >= 16;
                if (! this.pw.length) return { bars: 0, label: '', color: '', text: '' };
                if (met <= 1) return { bars: 1, label: 'Weak', color: 'bg-[#d14343]', text: 'text-[#b42318]' };
                if (met === 2) return { bars: 2, label: 'Fair', color: 'bg-[#e08a1e]', text: 'text-[#9a5b0b]' };
                if (met === 3) return { bars: 3, label: 'Almost there', color: 'bg-[#e0b51e]', text: 'text-[#8a6d0b]' };
                return { bars: 4, label: strong ? 'Strong' : 'Good', color: 'bg-[#1c9a63]', text: 'text-[#0f7a4f]' };
            },
        }"
        x-on:password-updated.window="pw = ''">
        <div class="mb-4">
            <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="cpf-current-password">Current password</label>
            <input wire:model="currentPassword" id="cpf-current-password" type="password" autocomplete="current-password"
                class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition"
                placeholder="••••••••">
            @error('currentPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="cpf-password">New password</label>
            <input wire:model="password" id="cpf-password" type="password" autocomplete="new-password"
                x-on:input="pw = $event.target.value" aria-describedby="cpf-password-rules"
                class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition"
                placeholder="••••••••">
            @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

            {{-- Strength meter: the same rules as Password::defaults() in AppServiceProvider --}}
            <div class="mt-2" data-password-meter>
                <div class="flex gap-1" aria-hidden="true">
                    <template x-for="i in 4" :key="i">
                        <span class="h-1.5 flex-1 rounded-full transition-colors"
                            :class="i <= strength.bars ? strength.color : 'bg-[#e3eaf1]'"></span>
                    </template>
                </div>
                <p class="mt-1 text-xs font-semibold" :class="strength.text" x-show="pw.length" x-text="strength.label" x-cloak></p>
                <ul id="cpf-password-rules" class="mt-1.5 grid grid-cols-1 sm:grid-cols-2 gap-x-3 gap-y-0.5 text-xs">
                    <template x-for="rule in rules" :key="rule.label">
                        <li class="flex items-center gap-1.5" :class="rule.met ? 'text-[#0f7a4f]' : 'text-[#5d6e7f]'">
                            <span class="w-3 text-center" x-text="rule.met ? '✓' : '•'" aria-hidden="true"></span>
                            <span x-text="rule.label"></span>
                            <span class="sr-only" x-text="rule.met ? '(done)' : '(not yet)'"></span>
                        </li>
                    </template>
                </ul>
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-[#173a59] mb-1.5" for="cpf-password-confirmation">Confirm new password</label>
            <input wire:model="password_confirmation" id="cpf-password-confirmation" type="password" autocomplete="new-password"
                class="w-full rounded-xl border border-[#d4e5f1] bg-white px-4 py-2.5 text-sm text-[#173a59] placeholder-[#5c778d]/60 focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent transition"
                placeholder="••••••••">
        </div>

        <button type="submit"
            class="inline-flex items-center gap-1 rounded bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
            wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed">
            <span wire:loading.remove>Update Password &rarr;</span>
            <span wire:loading.inline-flex class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Updating…</span>
        </button>
    </form>
</div>
