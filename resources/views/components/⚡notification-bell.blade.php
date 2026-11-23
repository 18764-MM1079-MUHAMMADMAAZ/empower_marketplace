<?php

use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    // Every method tolerates a signed-out user: this component polls in the background, and a page
    // left open after the session ended (or a request that lost its session) must not 500 here.
    #[Computed]
    public function notifications()
    {
        return auth()->user()?->notifications()->limit(10)->get() ?? collect();
    }

    #[Computed]
    public function unreadCount(): int
    {
        return auth()->user()?->unreadNotifications()->count() ?? 0;
    }

    /** Marks the notification read and sends the admin to whatever it's about (a submission,
     *  order, or user) in one step — a plain wire:click button rather than an <a> with
     *  wire:navigate, since combining both on one element races the Livewire request against
     *  the SPA navigation. */
    public function openNotification(string $id): void
    {
        $notification = auth()->user()?->notifications()->whereKey($id)->first();
        $url = $notification?->data['url'] ?? null;

        $notification?->markAsRead();

        unset($this->notifications, $this->unreadCount);

        if ($url) {
            $this->redirect($url, navigate: true);
        }
    }

    public function markAllAsRead(): void
    {
        auth()->user()?->unreadNotifications->each->markAsRead();

        unset($this->notifications, $this->unreadCount);
    }
};
?>

<div class="relative" x-data="{ open: false }" wire:poll.30s>
    <button type="button" x-on:click="open = !open" aria-label="Notifications{{ $this->unreadCount ? ', '.$this->unreadCount.' unread' : '' }}"
        class="relative flex items-center justify-center h-11 w-11 rounded-[9px] border border-[#cbd5e1] bg-white text-[#0f2a44] hover:bg-page transition-colors">
        <svg class="h-[17px] w-[17px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0" />
        </svg>
        @if($this->unreadCount > 0)
        <span aria-hidden="true" class="absolute -top-[5px] -right-[5px] flex items-center justify-center h-[18px] min-w-[18px] px-1 rounded-full border-2 border-white bg-[#c62828] text-[10px] font-bold text-white">
            {{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}
        </span>
        @endif
    </button>

    <div x-show="open" x-on:click.outside="open = false" x-transition
        class="absolute right-0 mt-2 w-96 max-w-[calc(100vw-2rem)] max-h-96 overflow-y-auto rounded-xl bg-white shadow-lg ring-1 ring-black/5 z-50">
        <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-empower-border">
            <span class="text-sm font-semibold text-navy">Notifications</span>
            @if($this->unreadCount > 0)
            <button type="button" wire:click="markAllAsRead" class="flex-shrink-0 text-xs font-semibold text-accent hover:underline whitespace-nowrap">Mark all as read</button>
            @endif
        </div>

        @forelse($this->notifications as $notification)
        <button type="button" wire:click="openNotification('{{ $notification->id }}')"
            class="block w-full text-left px-4 py-3 border-b border-empower-border last:border-b-0 hover:bg-page transition-colors {{ $notification->read_at ? '' : 'bg-[#eef6fb]' }}">
            <p class="text-sm font-semibold text-empower-text">{{ $notification->data['title'] ?? 'Notification' }}</p>
            <p class="text-xs text-empower-muted mt-0.5">{{ $notification->data['message'] ?? '' }}</p>
            <p class="text-[11px] text-empower-muted mt-1">{{ $notification->created_at->diffForHumans() }}</p>
        </button>
        @empty
        <p class="px-4 py-6 text-sm text-empower-muted text-center italic">No notifications yet.</p>
        @endforelse
    </div>
</div>
