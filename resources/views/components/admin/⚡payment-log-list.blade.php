<?php

use App\Exports\PaymentLogsExport;
use App\Models\ActivityLog;
use App\Models\PaymentLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

new class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function delete(int $paymentLogId): void
    {
        $log = PaymentLog::findOrFail($paymentLogId);
        $label = $log->transaction_id ?? $log->guest_email ?? $log->user?->name ?? "#{$log->id}";
        $log->delete();

        ActivityLog::record('payment_log.deleted', "Payment log {$label} was deleted.", user: auth()->user());

        unset($this->logs);

        $this->dispatch('toast', message: "Payment log {$label} deleted.", type: 'success');
    }

    private function baseQuery(): Builder
    {
        return PaymentLog::query()
            ->with('user', 'package', 'order')
            ->when($this->status === 'successful', fn ($q) => $q->where('success', true))
            ->when($this->status === 'declined', fn ($q) => $q->where('success', false))
            ->when($this->search !== '', function ($q) {
                $search = $this->search;
                $q->where(fn ($q) => $q->where('guest_email', 'like', "%{$search}%")
                    ->orWhere('transaction_id', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")));
            })
            ->latest();
    }

    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        return $this->baseQuery()->paginate(10);
    }

    public function export()
    {
        $rows = $this->baseQuery()->get()->map(fn (PaymentLog $log) => [
            $log->created_at?->toDateTimeString(),
            $log->user?->name ?? $log->guest_email ?? 'Guest',
            $log->package?->name,
            number_format((float) $log->amount, 2, '.', ''),
            $log->success ? 'Successful' : 'Declined',
            $log->transaction_id,
            $log->message,
            $log->order_id,
        ]);

        return Excel::download(new PaymentLogsExport($rows), 'payment-logs-'.now()->format('Y-m-d').'.xlsx');
    }
};
?>

<div class="space-y-4" x-data="{ confirmId: null, confirmLabel: '' }">
    <div class="flex flex-wrap items-center gap-3 justify-between">
        <h1 data-tour="page-title" class="text-2xl font-bold text-navy">Payment Logs</h1>
        <div class="flex flex-wrap items-center gap-3">
        <select data-tour="filter" wire:model.live="status"
            class="rounded-xl border border-empower-border bg-white px-4 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
            <option value="">All statuses</option>
            <option value="successful">Successful</option>
            <option value="declined">Declined</option>
        </select>

        <input data-tour="search" wire:model.live.debounce.400ms="search" type="text" placeholder="Search email, name, transaction ID…"
            class="w-full sm:w-80 rounded-xl border border-empower-border bg-white px-4 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">

        <button data-tour="export" type="button" wire:click="export" wire:loading.attr="disabled" wire:target="export"
            class="inline-flex items-center gap-1 rounded-lg border border-empower-border bg-[#dff7f0] px-4 py-2 text-xs font-bold text-[#0f7a4f] hover:bg-[#c7ebdc] transition-colors disabled:opacity-50">
            <span wire:loading.remove wire:target="export">Export to Excel</span>
            <span wire:loading.inline-flex wire:target="export" class="inline-flex items-center gap-1.5"><x-spinner class="h-3 w-3" /> Exporting…</span>
        </button>
        </div>
    </div>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] overflow-hidden">
        <div class="w-full overflow-x-auto">
            <table data-tour="table" class="w-full min-w-[900px] text-sm">
            <thead>
                <tr class="bg-page text-left text-xs font-extrabold uppercase tracking-wider text-empower-muted">
                    <th class="px-5 py-3">When</th>
                    <th class="px-5 py-3">Customer</th>
                    <th class="px-5 py-3">Package</th>
                    <th class="px-5 py-3">Amount</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3">Transaction / Message</th>
                    <th class="px-5 py-3">Order</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-empower-border">
                @forelse($this->logs as $log)
                    <tr class="hover:bg-page/60 transition-colors">
                        <td class="px-5 py-3.5 text-empower-muted text-xs whitespace-nowrap">{{ $log->created_at?->format('M j, Y g:ia') }}</td>
                        <td class="px-5 py-3.5 text-empower-text">{{ $log->user?->name ?? $log->guest_email ?? 'Guest' }}</td>
                        <td class="px-5 py-3.5 text-empower-text">{{ $log->package?->name ?? '—' }}</td>
                        <td class="px-5 py-3.5 text-empower-text">${{ number_format((float) $log->amount, 2) }}</td>
                        <td class="px-5 py-3.5">
                            @if($log->success)
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.65rem] font-extrabold uppercase tracking-wider bg-[#e7f7ee] text-[#1c8a4c]">Successful</span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.65rem] font-extrabold uppercase tracking-wider bg-[#fdecec] text-[#c53030]">Declined</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-empower-text text-xs">
                            {{ $log->transaction_id ?? $log->message ?? '—' }}
                        </td>
                        <td class="px-5 py-3.5 text-empower-text">
                            @if($log->order)
                                <a href="{{ route('admin.orders.edit', $log->order) }}" wire:navigate class="text-xs font-bold text-[#0b9ed0] hover:underline">#{{ $log->order_id }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right space-x-3 whitespace-nowrap">
                            <a href="{{ route('admin.payment-logs.show', $log) }}" wire:navigate class="text-xs font-bold text-[#0b9ed0] hover:underline">View</a>
                            <button type="button"
                                x-on:click="confirmId = {{ $log->id }}; confirmLabel = @js($log->transaction_id ?? $log->guest_email ?? $log->user?->name ?? "payment #{$log->id}")"
                                class="text-xs font-bold text-red-600 hover:underline">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-10 text-center text-sm text-empower-muted italic">No payment attempts recorded yet.</td>
                    </tr>
                @endforelse
            </tbody>
            </table>
        </div>
    </div>

    <div>{{ $this->logs->links() }}</div>

    <div x-show="confirmId !== null" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm px-4">
        <div class="w-full max-w-sm bg-white rounded-[1.25rem] shadow-xl p-6" x-on:click.outside="confirmId = null">
            <h3 class="text-base font-semibold text-navy mb-2">Delete <span x-text="confirmLabel"></span>?</h3>
            <p class="text-sm text-empower-muted mb-5">This cannot be undone.</p>
            <div class="flex justify-end gap-3">
                <button type="button" x-on:click="confirmId = null"
                    class="rounded-lg border border-empower-border px-4 py-2 text-sm font-semibold text-empower-muted hover:bg-page transition-colors">
                    Cancel
                </button>
                <button type="button" wire:target="delete"
                    x-on:click="$wire.delete(confirmId).then(() => confirmId = null).catch(() => {})"
                    wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="delete"
                    class="inline-flex items-center gap-1 rounded px-5 py-2 text-sm font-bold transition-colors bg-red-600 text-white hover:bg-red-700">
                    <span wire:loading.remove wire:target="delete">Delete</span>
                    <span wire:loading.inline-flex wire:target="delete" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Deleting…</span>
                </button>
            </div>
        </div>
    </div>
</div>
