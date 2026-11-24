<?php

use App\Services\ClientActivity;
use Livewire\Attributes\Computed;
use Livewire\Component;

/** My Account › Activity Log (v5): the client's sign-ins, security changes, document actions,
 *  compliance review updates and account changes. Read-only: entries are never edited or deleted. */
new class extends Component
{
    public string $type = 'all';

    public string $range = '7';

    public string $search = '';

    public int $page = 1;

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'range', 'search'], true)) {
            $this->page = 1;
        }
    }

    private function filters(): array
    {
        return [
            array_key_exists($this->type, ClientActivity::TYPES) ? $this->type : 'all',
            array_key_exists($this->range, ClientActivity::RANGES) ? $this->range : '7',
            mb_substr($this->search, 0, 100),
        ];
    }

    #[Computed]
    public function total(): int
    {
        return ClientActivity::for(auth()->user())->query(...$this->filters())->count();
    }

    #[Computed]
    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / ClientActivity::PER_PAGE));
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    #[Computed]
    public function rows(): \Illuminate\Support\Collection
    {
        $activity = ClientActivity::for(auth()->user());
        $page = min(max(1, $this->page), $this->pageCount);

        return $activity->query(...$this->filters())
            ->forPage($page, ClientActivity::PER_PAGE)
            ->get()
            ->map(fn ($log) => $activity->present($log));
    }

    public function goTo(int $page): void
    {
        $this->page = min(max(1, $page), $this->pageCount);
    }

    public function exportUrl(string $format): string
    {
        [$type, $range, $search] = $this->filters();

        return route('account.activity.export', array_filter(['format' => $format, 'type' => $type, 'range' => $range, 'search' => $search]));
    }
};
?>

@php
$page = min(max(1, $page), $this->pageCount);
$from = $this->total ? ($page - 1) * ClientActivity::PER_PAGE + 1 : 0;
$to = ($page - 1) * ClientActivity::PER_PAGE + $this->rows->count();
$typeChip = [
    'signin' => 'bg-[#e8f1fa] text-[#145a93]',
    'security' => 'bg-[#f4f0fc] text-[#5b3fa6]',
    'documents' => 'bg-[#eef2f6] text-[#334155]',
    'review' => 'bg-[#e7f5ee] text-[#146c43]',
    'account' => 'bg-[#fdf0d8] text-[#7a4a00]',
];
$practiceName = auth()->user()->practice?->name;
$field = 'h-10 rounded-lg border border-[#cbd5e1] bg-white px-3 text-sm text-[#0f2a44] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0]';
$pageButton = 'inline-flex h-10 items-center justify-center rounded-lg border px-3 text-[13px] font-semibold transition-colors';
$tz = ClientActivity::timezoneLabel();
@endphp

<section id="activity" data-account-section aria-labelledby="h-activity"
    class="scroll-mt-32 lg:scroll-mt-24 bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex-1 min-w-[18rem]">
            <h2 id="h-activity" class="text-lg font-semibold text-[#12304f]">Activity Log</h2>
            <p class="text-sm text-[#5d6e7f] mt-0.5">
                Every sign-in, document action and review update on your account.@if(auth()->user()->is_practice_admin && $practiceName) As practice administrator, you see activity for everyone at {{ $practiceName }}.@endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach(['csv' => 'Export CSV', 'pdf' => 'Export PDF'] as $format => $label)
            <a href="{{ $this->exportUrl($format) }}" data-activity-export="{{ $format }}"
                class="inline-flex h-10 items-center gap-1.5 rounded-lg border border-[#cbd5e1] bg-white px-3.5 text-[13px] font-semibold text-[#173045] hover:bg-[#f4f7fb] transition-colors">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3" /></svg>
                {{ $label }}
            </a>
            @endforeach
        </div>
    </div>

    <div class="mt-4 flex items-center gap-2.5 rounded-xl border border-[#e3e8ef] bg-[#f8fafc] px-3.5 py-3 text-[13px] text-[#334155]">
        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2" /><path d="M8 11V7a4 4 0 018 0v4" /></svg>
        <span><strong class="font-semibold">Kept permanently.</strong> Activity entries are never deleted and can't be edited. Export them any time for support or audits.</span>
    </div>

    <div class="mt-4 flex flex-wrap items-end gap-3">
        <label class="flex flex-1 basis-44 flex-col gap-1.5 text-xs font-medium text-[#334155]">Activity type
            <select wire:model.live="type" class="{{ $field }}" data-activity-type>
                <option value="all">All activity</option>
                @foreach(ClientActivity::TYPES as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-1 basis-40 flex-col gap-1.5 text-xs font-medium text-[#334155]">Date range
            <select wire:model.live="range" class="{{ $field }}" data-activity-range>
                <option value="7">Last 7 days</option>
                <option value="30">Last 30 days</option>
                <option value="90">Last 90 days</option>
                <option value="all">All time</option>
            </select>
        </label>
        <label class="flex flex-[2] basis-60 flex-col gap-1.5 text-xs font-medium text-[#334155]">Search
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by activity, document or person" class="{{ $field }}" data-activity-search>
        </label>
    </div>

    <div class="mt-4 overflow-x-auto rounded-xl border border-[#e3e8ef]">
        <table aria-label="Activity Log" class="w-full min-w-[52rem] text-sm">
            <thead class="bg-[#f8fafc] text-left text-[11px] font-bold uppercase tracking-wider text-[#5d6e7f]">
                <tr>
                    <th class="px-4 py-2.5 w-16">Sr. No.</th>
                    <th class="px-4 py-2.5 w-32">When</th>
                    <th class="px-4 py-2.5">Activity</th>
                    <th class="px-4 py-2.5 w-60">Device &amp; location</th>
                    <th class="px-4 py-2.5 w-40">Type</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#eef2f6]">
                @forelse($this->rows as $i => $row)
                <tr class="{{ $row['failed'] ? 'bg-[#fff7f6]' : 'bg-white' }}" data-activity-row="{{ $row['type'] }}" wire:key="activity-{{ $page }}-{{ $i }}">
                    <td class="px-4 py-2.5 text-[13px] font-semibold text-[#5d6e7f]">{{ $from + $i }}</td>
                    <td class="px-4 py-2.5">
                        <p class="text-[13px] font-medium text-[#173045]">{{ $row['when']->format('g:i A') }}</p>
                        <p class="text-xs text-[#5d6e7f]">{{ $row['when']->format('M j, Y') }}</p>
                    </td>
                    <td class="px-4 py-2.5 min-w-0">
                        <p class="flex flex-wrap items-center gap-2 font-medium text-[#173045]">
                            {{ $row['title'] }}
                            @if($row['failed'])<span class="rounded-full bg-[#fee4e2] px-2 py-px text-xs font-semibold text-[#b42318]">Failed</span>@endif
                        </p>
                        <p class="text-xs text-[#5d6e7f]">{{ $row['actor'] }}</p>
                    </td>
                    <td class="px-4 py-2.5">
                        <p class="text-[13px] text-[#173045]">{{ $row['where'] }}</p>
                        @if($row['detail'])<p class="text-xs text-[#5d6e7f]">{{ $row['detail'] }}</p>@endif
                    </td>
                    <td class="px-4 py-2.5"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $typeChip[$row['type']] }}">{{ $row['type_label'] }}</span></td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-7 text-center text-[13px] text-[#5d6e7f]">No activity matches these filters.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3.5 flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs text-[#5d6e7f]" data-activity-summary>Showing {{ $from }}–{{ $to }} of {{ $this->total }} {{ Str::plural('activity', $this->total) }} · Times shown in {{ $tz }}</p>
        @if($this->pageCount > 1)
        <nav aria-label="Activity Log pages" class="flex flex-wrap items-center gap-1.5">
            <button type="button" wire:click="goTo({{ $page - 1 }})" @disabled($page <= 1) class="{{ $pageButton }} border-[#cbd5e1] bg-white text-[#173045] disabled:opacity-50">Previous</button>
            @for($n = 1; $n <= $this->pageCount; $n++)
            <button type="button" wire:click="goTo({{ $n }})" aria-label="Page {{ $n }}" aria-current="{{ $n === $page ? 'page' : 'false' }}"
                class="{{ $pageButton }} w-10 px-0 {{ $n === $page ? 'border-[#12304f] bg-[#12304f] text-white' : 'border-[#cbd5e1] bg-white text-[#173045]' }}">{{ $n }}</button>
            @endfor
            <button type="button" wire:click="goTo({{ $page + 1 }})" @disabled($page >= $this->pageCount) class="{{ $pageButton }} border-[#cbd5e1] bg-white text-[#173045] disabled:opacity-50">Next</button>
        </nav>
        @endif
    </div>
</section>
