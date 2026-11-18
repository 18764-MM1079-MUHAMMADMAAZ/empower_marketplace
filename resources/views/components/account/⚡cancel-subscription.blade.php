<?php

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\PlanChangeService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /** The subscription whose "are you sure?" row is open. */
    public ?int $confirmingOrderId = null;

    #[Computed]
    public function orders(): Collection
    {
        return app(PlanChangeService::class)->orders(auth()->user());
    }

    #[On('account-updated')]
    public function refreshOrders(): void
    {
        unset($this->orders);
    }

    public function confirm(int $orderId): void
    {
        $this->confirmingOrderId = $orderId;
    }

    public function dismiss(): void
    {
        $this->confirmingOrderId = null;
    }

    public function cancel(int $orderId): void
    {
        $order = $this->orders->firstWhere('id', $orderId);
        abort_unless($order instanceof Order, 404);

        $result = app(PlanChangeService::class)->cancel(auth()->user(), $order);
        $this->confirmingOrderId = null;
        unset($this->orders);
        $this->dispatch('toast', message: $result['message'], type: $result['ok'] ? 'success' : 'error');
        $this->dispatch('account-updated');
    }

    public function keep(int $orderId): void
    {
        $order = $this->orders->firstWhere('id', $orderId);
        abort_unless($order instanceof Order, 404);

        $result = app(PlanChangeService::class)->keep(auth()->user(), $order);
        unset($this->orders);
        $this->dispatch('toast', message: $result['message'], type: $result['ok'] ? 'success' : 'error');
        $this->dispatch('account-updated');
    }
};
?>

<div>
    @if($this->orders->isNotEmpty())
    <section aria-labelledby="h-cancel" data-cancel-section
        class="bg-white border border-[#f3c5c0] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 sm:p-6">
        <h2 id="h-cancel" class="text-base font-semibold text-[#9b2c22]">Cancel subscription</h2>

        <div class="mt-3 divide-y divide-[#f6dcd8]">
            @foreach($this->orders as $order)
            @php
            $endsLater = $order->payment_status === PaymentStatus::Paid && app(PlanChangeService::class)->isBilledTerm($order);
            $name = $order->package?->name ?? 'your package';
            @endphp
            <div class="py-3 first:pt-0 last:pb-0" wire:key="cancel-{{ $order->id }}" data-cancel-order="{{ $order->id }}">
                @if($order->cancels_at)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-[#5d6e7f]"><strong class="text-[#173045]">{{ $name }}</strong> ends on <strong class="text-[#173045]">{{ $order->cancels_at->format('M j, Y') }}</strong>. You won't be charged again. You can keep your plan any time before then.</p>
                    <button type="button" wire:click="keep({{ $order->id }})" wire:loading.attr="disabled" wire:target="keep({{ $order->id }})"
                        class="shrink-0 rounded-lg bg-[#12304f] px-3.5 py-1.5 text-xs font-bold text-white hover:bg-[#0c233b] transition-colors">Keep my plan</button>
                </div>
                @else
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <p class="text-sm text-[#5d6e7f] max-w-2xl">
                        <strong class="text-[#173045]">{{ $name }}.</strong>
                        @if($order->payment_status === PaymentStatus::Trialing)
                        Cancelling ends your free trial now, and your card won't be charged.
                        @elseif($endsLater)
                        Your documents stay available to download. New document generation, the hotline and exclusions screening stop when your paid term ends on {{ $order->next_bill_date->format('M j, Y') }}.
                        @else
                        Cancelling ends this subscription now. Your documents stay available to download.
                        @endif
                    </p>
                    @if($confirmingOrderId !== $order->id)
                    <button type="button" wire:click="confirm({{ $order->id }})"
                        class="shrink-0 rounded-lg border border-[#e7a59d] px-3.5 py-1.5 text-xs font-bold text-[#c2362b] hover:bg-[#fdecea] transition-colors">Cancel subscription</button>
                    @endif
                </div>

                @if($confirmingOrderId === $order->id)
                <div class="mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-[#fdecea] px-4 py-3" role="alert">
                    <span class="text-sm font-semibold text-[#9b2c22] mr-auto">
                        @if($endsLater)
                        Cancel {{ $name }} at the end of your term on {{ $order->next_bill_date->format('M j, Y') }}?
                        @else
                        Cancel {{ $name }} now?
                        @endif
                    </span>
                    <button type="button" wire:click="cancel({{ $order->id }})" wire:loading.attr="disabled" wire:target="cancel({{ $order->id }})"
                        class="rounded-lg bg-[#c2362b] px-3.5 py-1.5 text-xs font-bold text-white hover:bg-[#a62d23] transition-colors">{{ $endsLater ? 'Yes, cancel at term end' : 'Yes, cancel now' }}</button>
                    <button type="button" wire:click="dismiss"
                        class="rounded-lg border border-[#e7a59d] bg-white px-3.5 py-1.5 text-xs font-semibold text-[#5d6e7f] transition-colors">Keep my plan</button>
                </div>
                @endif
                @endif
            </div>
            @endforeach
        </div>
    </section>
    @endif
</div>
