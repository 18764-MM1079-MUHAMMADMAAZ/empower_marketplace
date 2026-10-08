<?php

use App\Enums\OrderStatus;
use App\Exports\OrdersExport;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
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

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function clearDateRange(): void
    {
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->resetPage();
    }

    /** Finance's "mark as processed" toggle (sso.md §10) — nothing in the daily report depends on
     *  this being set; it's purely so Finance doesn't double-apply the same order to CareCloud
     *  billing after it's already been handled. */
    public function toggleFinanceProcessed(Order $order): void
    {
        $order->update(['finance_processed_at' => $order->finance_processed_at ? null : now()]);
    }

    private function baseQuery(): Builder
    {
        return Order::query()
            ->with(['user', 'package'])
            ->when($this->search !== '', function ($q) {
                $search = $this->search;
                $q->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            })
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->dateFrom !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->latest();
    }

    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return $this->baseQuery()->paginate(10);
    }

    public function export()
    {
        $rows = $this->baseQuery()->get()->map(fn (Order $order) => [
            $order->user->name,
            $order->user->email,
            $order->package?->name,
            ucwords(str_replace('_', ' ', $order->status->value)),
            $order->amount_paid !== null ? number_format((float) $order->amount_paid, 2, '.', '') : null,
            $order->created_at?->toDateTimeString(),
            $order->finance_processed_at ? 'Yes' : 'No',
        ]);

        return Excel::download(new OrdersExport($rows), 'orders-'.now()->format('Y-m-d').'.xlsx');
    }

    /** On-demand trigger for the same `reports:finance-daily` command the schedule runs at 07:00
     *  (SendFinanceDailyReport) — lets an admin send yesterday's digest right now instead of
     *  waiting for the cron, without duplicating any of that command's logic. */
    public function sendFinanceReport(): void
    {
        Artisan::call('reports:finance-daily');

        $this->dispatch('toast', message: trim(Artisan::output()) ?: 'Finance report sent.', type: 'success');
    }
};
?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3 justify-between">
        <h1 data-tour="page-title" class="text-2xl font-bold text-navy">Orders</h1>

        <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-1.5">
            <input data-tour="date-range" wire:model.live="dateFrom" type="date" aria-label="From date"
                class="rounded-xl border border-empower-border bg-white px-3 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
            <span class="text-sm text-empower-muted">&ndash;</span>
            <input wire:model.live="dateTo" type="date" aria-label="To date"
                class="rounded-xl border border-empower-border bg-white px-3 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
            @if($dateFrom !== '' || $dateTo !== '')
                <button type="button" wire:click="clearDateRange" class="text-xs font-bold text-[#0b9ed0] hover:underline">Clear</button>
            @endif
        </div>

        <select data-tour="filter" wire:model.live="status"
            class="rounded-xl border border-empower-border bg-white px-4 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
            <option value="">All statuses</option>
            @foreach(OrderStatus::cases() as $case)
                <option value="{{ $case->value }}">{{ ucwords(str_replace('_', ' ', $case->value)) }}</option>
            @endforeach
        </select>

        <input data-tour="search" wire:model.live.debounce.400ms="search" type="text" placeholder="Search client name or email…"
            class="w-full sm:w-64 rounded-xl border border-empower-border bg-white px-4 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">

        <button data-tour="export" type="button" wire:click="export" wire:loading.attr="disabled" wire:target="export"
            class="inline-flex items-center gap-1 rounded-lg border border-empower-border bg-[#dff7f0] px-4 py-2 text-xs font-bold text-[#0f7a4f] hover:bg-[#c7ebdc] transition-colors disabled:opacity-50">
            <span wire:loading.remove wire:target="export">Export to Excel</span>
            <span wire:loading.inline-flex wire:target="export" class="inline-flex items-center gap-1.5"><x-spinner class="h-3 w-3" /> Exporting…</span>
        </button>

        <button data-tour="finance-report" type="button" wire:click="sendFinanceReport" wire:loading.attr="disabled" wire:target="sendFinanceReport"
            class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-4 py-2 text-xs font-bold text-white hover:bg-[#087fa9] transition-colors disabled:opacity-50">
            <span wire:loading.remove wire:target="sendFinanceReport">Send Finance Report</span>
            <span wire:loading.inline-flex wire:target="sendFinanceReport" class="inline-flex items-center gap-1.5"><x-spinner class="h-3 w-3" /> Sending…</span>
        </button>
        </div>
    </div>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] overflow-hidden">
        <div class="w-full overflow-x-auto">
            <table data-tour="table" class="w-full min-w-[820px] text-sm">
            <thead>
                <tr class="bg-page text-left text-xs font-extrabold uppercase tracking-wider text-empower-muted">
                    <th class="px-5 py-3">Client</th>
                    <th class="px-5 py-3">Package</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3">Amount Paid</th>
                    <th class="px-5 py-3">Placed</th>
                    <th class="px-5 py-3">Finance</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-empower-border">
                @forelse($this->orders as $order)
                    <tr class="hover:bg-page/60 transition-colors">
                        <td class="px-5 py-3.5">
                            <div class="font-semibold text-navy">{{ $order->user->name }}</div>
                            <div class="text-xs text-empower-muted">{{ $order->user->email }}</div>
                        </td>
                        <td class="px-5 py-3.5 text-empower-text">{{ $order->package->name }}</td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.68rem] font-extrabold uppercase tracking-wider bg-[#eef6fb] text-empower-muted">
                                {{ ucwords(str_replace('_', ' ', $order->status->value)) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-empower-text">
                            {{ $order->amount_paid !== null ? '$'.number_format((float) $order->amount_paid, 2) : '—' }}
                        </td>
                        <td class="px-5 py-3.5 text-empower-muted text-xs">{{ $order->created_at?->diffForHumans() }}</td>
                        <td class="px-5 py-3.5">
                            <button type="button" wire:click="toggleFinanceProcessed({{ $order->id }})"
                                wire:loading.attr="disabled" wire:target="toggleFinanceProcessed({{ $order->id }})"
                                class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.68rem] font-extrabold uppercase tracking-wider transition-colors disabled:opacity-50 {{ $order->finance_processed_at ? 'bg-[#d7f3ea] text-[#117a51] hover:bg-[#c3ecdd]' : 'bg-page text-empower-muted hover:bg-[#eef6fb]' }}">
                                {{ $order->finance_processed_at ? 'Processed' : 'Mark processed' }}
                            </button>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <a href="{{ route('admin.orders.edit', $order) }}" wire:navigate class="text-xs font-bold text-[#0b9ed0] hover:underline">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-10 text-center text-sm text-empower-muted italic">No orders yet.</td>
                    </tr>
                @endforelse
            </tbody>
            </table>
        </div>
    </div>

    <div>{{ $this->orders->links() }}</div>
</div>
