<?php

use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function notifications()
    {
        return auth()->user()->notifications()->limit(10)->get();
    }

    #[Computed]
    public function unreadCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    /** Marks the notification read and sends the admin to whatever it's about (a submission,
     *  order, or user) in one step — a plain wire:click button rather than an <a> with
     *  wire:navigate, since combining both on one element races the Livewire request against
     *  the SPA navigation. */
    public function openNotification(string $id): void
    {
        $notification = auth()->user()->notifications()->whereKey($id)->first();
        $url = $notification?->data['url'] ?? null;

        $notification?->markAsRead();

        unset($this->notifications, $this->unreadCount);

        if ($url) {
            $this->redirect($url, navigate: true);
        }
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->each->markAsRead();

        unset($this->notifications, $this->unreadCount);
    }
};
?>

<div class="relative" x-data="{ open: false }" wire:poll.30s>
    <button type="button" x-on:click="open = !open" aria-label="Notifications"
        class="relative flex items-center justify-center h-10 w-10 rounded-lg border border-empower-border text-empower-muted hover:bg-page hover:text-navy transition-colors">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>
        @if($this->unreadCount > 0)
        <span class="absolute -top-1 -right-1 flex items-center justify-center h-5 min-w-[1.25rem] px-1 rounded-full bg-red-600 text-[10px] font-bold text-white">
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
