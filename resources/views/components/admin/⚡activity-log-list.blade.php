<?php

use App\Exports\ActivityLogsExport;
use App\Models\ActivityLog;
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
    public string $eventType = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedEventType(): void
    {
        $this->resetPage();
    }

    /** @return array<int, string> */
    #[Computed]
    public function eventTypes(): array
    {
        return ActivityLog::query()->distinct()->orderBy('event_type')->pluck('event_type')->all();
    }

    private function baseQuery(): Builder
    {
        return ActivityLog::query()
            ->with('user', 'order')
            ->when($this->search !== '', function ($q) {
                $search = $this->search;
                $q->where(fn ($q) => $q->where('event_type', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%"));
            })
            ->when($this->eventType !== '', fn ($q) => $q->where('event_type', $this->eventType))
            ->latest();
    }

    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        return $this->baseQuery()->paginate(10);
    }

    public function export()
    {
        $rows = $this->baseQuery()->get()->map(fn (ActivityLog $log) => [
            $log->created_at?->toDateTimeString(),
            $log->user?->name ?? 'System',
            $log->event_type,
            $log->description,
            $log->order_id,
        ]);

        return Excel::download(new ActivityLogsExport($rows), 'activity-log-'.now()->format('Y-m-d').'.xlsx');
    }
};
?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3 justify-between">
        <h1 data-tour="page-title" class="text-2xl font-bold text-navy">Activity Log</h1>
        <div class="flex flex-wrap items-center gap-3">
        <select data-tour="filter" wire:model.live="eventType"
            class="rounded-xl border border-empower-border bg-white px-4 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
            <option value="">All events</option>
            @foreach($this->eventTypes as $type)
                <option value="{{ $type }}">{{ $type }}</option>
            @endforeach
        </select>

        <input data-tour="search" wire:model.live.debounce.400ms="search" type="text" placeholder="Search event type or description…"
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
            <table data-tour="table" class="w-full min-w-[820px] text-sm">
            <thead>
                <tr class="bg-page text-left text-xs font-extrabold uppercase tracking-wider text-empower-muted">
                    <th class="px-5 py-3">When</th>
                    <th class="px-5 py-3">Actor</th>
                    <th class="px-5 py-3">Event</th>
                    <th class="px-5 py-3">Description</th>
                    <th class="px-5 py-3">Order</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-empower-border">
                @forelse($this->logs as $log)
                    <tr class="hover:bg-page/60 transition-colors">
                        <td class="px-5 py-3.5 text-empower-muted text-xs whitespace-nowrap">{{ $log->created_at?->format('M j, Y g:ia') }}</td>
                        <td class="px-5 py-3.5 text-empower-text">{{ $log->user?->name ?? 'System' }}</td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.65rem] font-extrabold uppercase tracking-wider bg-[#eef6fb] text-empower-muted">
                                {{ $log->event_type }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-empower-text">{{ $log->description }}</td>
                        <td class="px-5 py-3.5 text-empower-text">
                            @if($log->order)
                                <a href="{{ route('admin.orders.edit', $log->order) }}" wire:navigate class="text-xs font-bold text-[#0b9ed0] hover:underline">#{{ $log->order_id }}</a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-10 text-center text-sm text-empower-muted italic">No activity recorded yet.</td>
                    </tr>
                @endforelse
            </tbody>
            </table>
        </div>
    </div>

    <div>{{ $this->logs->links() }}</div>
</div>
