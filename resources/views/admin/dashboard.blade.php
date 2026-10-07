@php
    use App\Enums\IntakeSubmissionStatus;
    use App\Models\AiUsageLog;
    use App\Models\GeneratedDocument;
    use App\Models\IntakeSubmission;
    use App\Models\Lead;
    use App\Models\Order;
    use Illuminate\Support\Carbon;

    $pendingReview = IntakeSubmission::whereIn('status', [
        IntakeSubmissionStatus::Submitted,
        IntakeSubmissionStatus::UnderReview,
    ])->count();
    $totalOrders = Order::count();
    $staleDocuments = GeneratedDocument::where('is_stale', true)->count();
    $newLeads = Lead::where('is_contacted', false)->count();

    $aiUsageDays = 14;
    $aiUsageCountsByDate = AiUsageLog::query()
        ->where('created_at', '>=', Carbon::today()->subDays($aiUsageDays - 1))
        ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
        ->groupBy('day')
        ->pluck('total', 'day');
    $aiUsageByDay = collect(range($aiUsageDays - 1, 0))->map(function (int $daysAgo) use ($aiUsageCountsByDate) {
        $date = Carbon::today()->subDays($daysAgo);

        return ['label' => $date->format('M j'), 'count' => (int) ($aiUsageCountsByDate[$date->toDateString()] ?? 0)];
    });
    $aiUsageTotal = $aiUsageByDay->sum('count');
    $aiUsageMax = max($aiUsageByDay->max('count'), 1);
@endphp

<x-layouts.app title="Admin Dashboard">
    <div class="space-y-4">

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach([
                ['Pending Review', $pendingReview, route('admin.submissions')],
                ['Total Orders', $totalOrders, route('admin.orders')],
                ['Stale Documents', $staleDocuments, route('admin.documents')],
                ['New Leads', $newLeads, route('admin.leads')],
            ] as [$label, $value, $link])
                @php $card = '<div class="text-xs font-extrabold uppercase tracking-wider text-empower-muted mb-2">'.$label.'</div><div class="text-3xl font-extrabold text-navy">'.$value.'</div>'; @endphp
                @if($link)
                    <a href="{{ $link }}" wire:navigate
                        class="block bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 hover:border-navy/40 transition-colors">
                        {!! $card !!}
                    </a>
                @else
                    <div class="block bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
                        {!! $card !!}
                    </div>
                @endif
            @endforeach
        </div>

        <livewire:admin.launch-gate />

        <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h2 class="text-xs font-extrabold uppercase tracking-wider text-empower-muted">OpenAI Usage</h2>
                    <p class="text-xs text-empower-muted mt-0.5">API calls per day &mdash; last {{ $aiUsageDays }} days</p>
                </div>
                <div class="text-right">
                    <div class="text-2xl font-extrabold text-navy">{{ $aiUsageTotal }}</div>
                    <div class="text-xs text-empower-muted">total calls</div>
                </div>
            </div>

            <div class="flex items-end gap-1.5 sm:gap-2 h-36">
                @foreach($aiUsageByDay as $day)
                    @php $heightPct = $day['count'] > 0 ? max(6, round($day['count'] / $aiUsageMax * 100)) : 3; @endphp
                    <div class="flex-1 flex flex-col items-center justify-end h-full group">
                        <div class="text-[0.65rem] font-semibold text-navy mb-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            {{ $day['count'] }}
                        </div>
                        <div class="w-full rounded-t-md transition-colors {{ $day['count'] > 0 ? 'bg-accent group-hover:bg-accent-dark' : 'bg-empower-border' }}"
                            style="height: {{ $heightPct }}%"></div>
                        <div class="mt-1.5 text-[0.6rem] text-empower-muted whitespace-nowrap">{{ $day['label'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-layouts.app>
