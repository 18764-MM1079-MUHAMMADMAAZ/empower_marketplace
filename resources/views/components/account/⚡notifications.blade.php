<?php

use App\Enums\AdminEmail;
use App\Models\ActivityLog;
use Livewire\Component;

/**
 * My Account › Notifications (admins): turn each optional admin email on or off. Saved straight
 * away on the user's notification_preferences; the senders check User::wantsEmail(). The in-app
 * notification bell is unaffected.
 */
new class extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
    }

    public function toggle(string $key): void
    {
        $email = AdminEmail::tryFrom($key);
        $user = auth()->user();

        if (! $email || ! $user->isAdmin()) {
            return;
        }

        $on = ! $user->wantsEmail($email);
        $user->notification_preferences = [...($user->notification_preferences ?? []), $email->value => $on];
        $user->save();

        ActivityLog::record('account.notification_preference', ($on ? 'Turned on' : 'Turned off')." \"{$email->label()}\" emails.", user: $user);

        $this->dispatch('toast', message: "{$email->label()} emails turned ".($on ? 'on' : 'off').'.', type: 'success');
    }
};
?>

<section id="notifications" data-account-section aria-labelledby="h-notifications"
    class="scroll-mt-32 lg:scroll-mt-24 bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 sm:p-6">
    <h2 id="h-notifications" class="text-lg font-semibold text-[#12304f]">Notifications</h2>
    <p class="text-sm text-[#5d6e7f] mb-5">Choose which emails you get at <span class="font-semibold text-[#173045] break-all">{{ auth()->user()->email }}</span>. Everything still shows in the notification bell.</p>

    <ul class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] divide-y divide-[#eef2f6]">
        @foreach(AdminEmail::cases() as $email)
        @php $on = auth()->user()->wantsEmail($email); @endphp
        <li class="flex items-center justify-between gap-4 px-4 sm:px-5 py-3.5" wire:key="pref-{{ $email->value }}" data-notification-pref="{{ $email->value }}">
            <div class="min-w-0">
                <p id="pref-{{ $email->value }}" class="text-sm font-semibold text-[#173045]">{{ $email->label() }}</p>
                <p class="text-xs text-[#5d6e7f]">{{ $email->description() }}</p>
            </div>
            <button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-labelledby="pref-{{ $email->value }}"
                wire:click="toggle(@js($email->value))" wire:loading.attr="disabled" wire:target="toggle"
                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[#0b9ed0] focus-visible:ring-offset-2 {{ $on ? 'bg-[#2299dd]' : 'bg-[#cdd8e3]' }}">
                <span class="sr-only">{{ $on ? 'On' : 'Off' }}</span>
                <span aria-hidden="true" class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform {{ $on ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
            </button>
        </li>
        @endforeach
    </ul>
</section>
