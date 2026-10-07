<?php

use App\Models\ActivityLog;
use App\Models\PaymentLog;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $paymentLogId;

    public function mount(PaymentLog $paymentLog): void
    {
        $this->paymentLogId = $paymentLog->id;
    }

    #[Computed]
    public function log(): PaymentLog
    {
        return PaymentLog::with('user', 'package', 'order')->findOrFail($this->paymentLogId);
    }

    public function delete(): void
    {
        $log = $this->log;
        $label = $log->transaction_id ?? $log->guest_email ?? $log->user?->name ?? "#{$log->id}";
        $log->delete();

        ActivityLog::record('payment_log.deleted', "Payment log {$label} was deleted.", user: auth()->user());

        session()->flash('toast', "Payment log {$label} deleted.");
        $this->redirect(route('admin.payment-logs'), navigate: true);
    }
};
?>

<div class="space-y-6" x-data="{ confirmOpen: false }">
    <div class="flex items-center justify-between">
        <a href="{{ route('admin.payment-logs') }}" wire:navigate class="text-sm font-bold text-[#0b9ed0] hover:underline">&larr; Back to Payment Logs</a>
        <button type="button" x-on:click="confirmOpen = true"
            class="inline-flex items-center gap-1 rounded bg-red-600 px-4 py-2 text-xs font-bold text-white hover:bg-red-700 transition-colors">
            Delete Log
        </button>
    </div>

    <div x-show="confirmOpen" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm px-4">
        <div class="w-full max-w-sm bg-white rounded-[1.25rem] shadow-xl p-6" x-on:click.outside="confirmOpen = false">
            <h3 class="text-base font-semibold text-navy mb-2">Delete this payment log?</h3>
            <p class="text-sm text-empower-muted mb-5">This cannot be undone.</p>
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

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-6 space-y-6">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-extrabold text-navy">Payment Attempt #{{ $this->log->id }}</h2>
            @if($this->log->success)
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-extrabold uppercase tracking-wider bg-[#e7f7ee] text-[#1c8a4c]">Successful</span>
            @else
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-extrabold uppercase tracking-wider bg-[#fdecec] text-[#c53030]">Declined</span>
            @endif
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-5 text-sm">
            <div>
                <dt class="text-xs font-extrabold uppercase tracking-wider text-empower-muted">When</dt>
                <dd class="mt-1 text-empower-text">{{ $this->log->created_at?->format('M j, Y g:ia') }}</dd>
            </div>
            <div>
                <dt class="text-xs font-extrabold uppercase tracking-wider text-empower-muted">Customer</dt>
                <dd class="mt-1 text-empower-text">
                    {{ $this->log->user?->name ?? $this->log->guest_email ?? 'Guest' }}
                    @if($this->log->user)
                        <div class="text-xs text-empower-muted">{{ $this->log->user->email }}</div>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-extrabold uppercase tracking-wider text-empower-muted">Package</dt>
                <dd class="mt-1 text-empower-text">{{ $this->log->package?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-extrabold uppercase tracking-wider text-empower-muted">Amount</dt>
                <dd class="mt-1 text-empower-text">${{ number_format((float) $this->log->amount, 2) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-extrabold uppercase tracking-wider text-empower-muted">Transaction ID</dt>
                <dd class="mt-1 text-empower-text">{{ $this->log->transaction_id ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-extrabold uppercase tracking-wider text-empower-muted">Order</dt>
                <dd class="mt-1 text-empower-text">
                    @if($this->log->order)
                        <a href="{{ route('admin.orders.edit', $this->log->order) }}" wire:navigate class="text-xs font-bold text-[#0b9ed0] hover:underline">#{{ $this->log->order_id }}</a>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-xs font-extrabold uppercase tracking-wider text-empower-muted">{{ $this->log->success ? 'Gateway Message' : 'Decline Reason' }}</dt>
                <dd class="mt-1 text-empower-text">{{ $this->log->message ?? '—' }}</dd>
            </div>
        </dl>

        @if($this->log->billing_address)
            <div>
                <h3 class="text-xs font-extrabold uppercase tracking-wider text-empower-muted mb-2">Billing Address</h3>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
                    <div>
                        <dt class="text-xs text-empower-muted">Name</dt>
                        <dd class="mt-0.5 text-empower-text">{{ $this->log->billing_address['name'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-empower-muted">Address</dt>
                        <dd class="mt-0.5 text-empower-text">{{ $this->log->billing_address['address1'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-empower-muted">City</dt>
                        <dd class="mt-0.5 text-empower-text">{{ $this->log->billing_address['city'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-empower-muted">State</dt>
                        <dd class="mt-0.5 text-empower-text">{{ $this->log->billing_address['state'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-empower-muted">Zip</dt>
                        <dd class="mt-0.5 text-empower-text">{{ $this->log->billing_address['zip'] ?? '—' }}</dd>
                    </div>
                </dl>
            </div>
        @endif
    </div>
</div>
