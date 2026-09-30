<?php

use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\Order;
use Livewire\Component;

new class extends Component
{
    public int $orderId;

    public string $clientName = '';

    public string $clientEmail = '';

    public string $packageName = '';

    public string $status = '';

    public ?string $amountPaid = null;

    public string $paymentReference = '';

    public string $notes = '';

    public function mount(Order $order): void
    {
        $order->loadMissing('user', 'package');

        $this->orderId = $order->id;
        $this->clientName = $order->user->name;
        $this->clientEmail = $order->user->email;
        $this->packageName = $order->package->name;
        $this->status = $order->status->value;
        $this->amountPaid = $order->amount_paid !== null ? (string) $order->amount_paid : null;
        $this->paymentReference = $order->payment_reference ?? '';
        $this->notes = $order->notes ?? '';
    }

    public function save(): void
    {
        $this->validate([
            'status' => 'required|in:'.implode(',', array_map(fn ($case) => $case->value, OrderStatus::cases())),
            'amountPaid' => 'nullable|numeric|min:0',
            'paymentReference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        $order = Order::findOrFail($this->orderId);

        $order->update([
            'status' => $this->status,
            'amount_paid' => $this->amountPaid !== null && $this->amountPaid !== '' ? $this->amountPaid : null,
            'payment_reference' => $this->paymentReference ?: null,
            'notes' => $this->notes ?: null,
        ]);

        ActivityLog::record(
            'order.updated',
            "Order #{$order->id} for {$this->clientName} was updated by an admin.",
            user: auth()->user(),
            order: $order,
        );

        session()->flash('toast', "Order #{$order->id} updated.");
        $this->redirect(route('admin.orders'), navigate: true);
    }

    public function delete(): void
    {
        $order = Order::findOrFail($this->orderId);
        $order->deleteCascadingFiles();
        $order->delete();

        ActivityLog::record('order.deleted', "Order #{$this->orderId} for {$this->clientName} was deleted by an admin.", user: auth()->user());

        session()->flash('toast', "Order #{$this->orderId} deleted.");
        $this->redirect(route('admin.orders'), navigate: true);
    }
};
?>

<div class="space-y-4" x-data="{ confirmOpen: false }">
    <a href="{{ route('admin.orders') }}" wire:navigate class="text-sm font-semibold text-[#0b9ed0] hover:underline">&larr; Back to orders</a>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <h2 class="text-lg font-semibold text-navy mb-1">Order #{{ $orderId }}</h2>
        <p class="text-sm text-empower-muted mb-4">{{ $clientName }} ({{ $clientEmail }}) — {{ $packageName }}</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Status</label>
                <select wire:model="status"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                    @foreach(OrderStatus::cases() as $case)
                        <option value="{{ $case->value }}">{{ ucwords(str_replace('_', ' ', $case->value)) }}</option>
                    @endforeach
                </select>
                @error('status') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Amount Paid ($)</label>
                <input wire:model="amountPaid" type="number" step="0.01" min="0"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('amountPaid') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Payment Reference</label>
                <input wire:model="paymentReference" type="text"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('paymentReference') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Notes</label>
                <textarea wire:model="notes" rows="3"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition resize-none"></textarea>
                @error('notes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-5 flex items-center justify-between">
            <button type="button" x-on:click="confirmOpen = true"
                class="text-sm font-bold text-red-600 hover:underline">Delete Order</button>

            <button wire:click="save" wire:target="save"
                class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="save">
                <span wire:loading.remove wire:target="save">Save Changes &rarr;</span>
                <span wire:loading.inline-flex wire:target="save" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving…</span>
            </button>
        </div>
    </div>

    <div x-show="confirmOpen" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-sm bg-white rounded-[1.25rem] shadow-xl p-6" x-on:click.outside="confirmOpen = false">
            <h3 class="text-base font-semibold text-navy mb-2">Delete order #{{ $orderId }} for {{ $clientName }}?</h3>
            <p class="text-sm text-empower-muted mb-5">This permanently deletes its intake submission, uploads, and generated documents. This cannot be undone.</p>
            <div class="flex justify-end gap-3">
                <button type="button" x-on:click="confirmOpen = false"
                    class="rounded-lg border border-empower-border px-4 py-2 text-sm font-semibold text-empower-muted hover:bg-page transition-colors">
                    Cancel
                </button>
                <button type="button" wire:target="delete"
                    x-on:click="$wire.delete().then(() => confirmOpen = false).catch(() => {})"
                    wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="delete"
                    class="inline-flex items-center gap-1 rounded px-5 py-2 text-sm font-bold transition-colors bg-red-600 text-white hover:bg-red-700">
                    <span wire:loading.remove wire:target="delete">Delete</span>
                    <span wire:loading.inline-flex wire:target="delete" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Deleting…</span>
                </button>
            </div>
        </div>
    </div>
</div>
