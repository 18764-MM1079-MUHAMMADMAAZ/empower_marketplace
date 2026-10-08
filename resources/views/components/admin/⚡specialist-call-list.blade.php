<?php

use App\Enums\SpecialistCallStatus;
use App\Exports\SpecialistCallsExport;
use App\Mail\ClientSpecialistCallMail;
use App\Models\SpecialistCallRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;

new class extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function setStatus(int $callId, string $status): void
    {
        $newStatus = SpecialistCallStatus::tryFrom($status);

        if ($newStatus === null) {
            return;
        }

        $call = SpecialistCallRequest::with('user')->findOrFail($callId);
        $call->update(['status' => $newStatus]);

        if ($call->user && in_array($newStatus, [SpecialistCallStatus::Scheduled, SpecialistCallStatus::Cancelled], true)) {
            // After the response, so the slow SMTP round trip doesn't hold up the badge update.
            defer(function () use ($call, $newStatus) {
                try {
                    Mail::to($call->user->email)->send(new ClientSpecialistCallMail($call, $newStatus->value));
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }

        unset($this->calls);

        $this->dispatch('toast', message: "Call marked {$newStatus->label()}.", type: 'success');
    }

    private function baseQuery(): Builder
    {
        return SpecialistCallRequest::query()
            ->with(['user', 'order'])
            ->when(SpecialistCallStatus::tryFrom($this->status), fn (Builder $q, SpecialistCallStatus $s) => $q->where('status', $s))
            ->orderBy('requested_date')
            ->orderBy('id');
    }

    #[Computed]
    public function calls(): LengthAwarePaginator
    {
        return $this->baseQuery()->paginate(15);
    }

    public function export()
    {
        $rows = $this->baseQuery()->get()->map(fn (SpecialistCallRequest $call) => [
            $call->user?->name,
            $call->user?->email,
            $call->phone,
            $call->order_id ? '#'.$call->order_id : '',
            $call->requested_date?->toDateString(),
            $call->requested_time,
            $call->topic,
            $call->notes,
            $call->status->label(),
            $call->created_at?->toDateTimeString(),
        ]);

        return Excel::download(new SpecialistCallsExport($rows), 'specialist-calls-'.now()->format('Y-m-d').'.xlsx');
    }
};
?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3 justify-between">
        <h1 data-tour="page-title" class="text-2xl font-bold text-navy">Specialist Calls</h1>
        <div class="flex flex-wrap items-center gap-3">
            <select data-tour="filter" wire:model.live="status"
                class="rounded-xl border border-empower-border bg-white px-4 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent">
                <option value="">All statuses</option>
                @foreach(App\Enums\SpecialistCallStatus::cases() as $case)
                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
            <button data-tour="export" type="button" wire:click="export" wire:loading.attr="disabled" wire:target="export"
                class="inline-flex items-center gap-1 rounded-lg border border-empower-border bg-[#dff7f0] px-4 py-2 text-xs font-bold text-[#0f7a4f] hover:bg-[#c7ebdc] transition-colors disabled:opacity-50">
                <span wire:loading.remove wire:target="export">Export to Excel</span>
                <span wire:loading.inline-flex wire:target="export" class="inline-flex items-center gap-1.5"><x-spinner class="h-3 w-3" /> Exporting…</span>
            </button>
        </div>
    </div>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] overflow-hidden">
        <div class="w-full overflow-x-auto">
            <table data-tour="table" class="w-full min-w-[860px] text-sm">
                <thead>
                    <tr class="bg-page text-left text-xs font-extrabold uppercase tracking-wider text-empower-muted">
                        <th class="px-5 py-3">Client</th>
                        <th class="px-5 py-3">Call time (ET)</th>
                        <th class="px-5 py-3">Phone</th>
                        <th class="px-5 py-3">Topic</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-empower-border">
                    @forelse($this->calls as $call)
                    <tr wire:key="call-{{ $call->id }}" x-data="{ busy: false }" class="hover:bg-page/60 transition-colors align-top">
                        <td class="px-5 py-3">
                            <div class="font-semibold text-navy">{{ $call->user?->name ?? 'Unknown' }}</div>
                            <div class="text-xs text-empower-muted">{{ $call->user?->email }}</div>
                            @if($call->notes)
                            <div class="mt-1 text-xs text-empower-muted max-w-xs">&ldquo;{{ $call->notes }}&rdquo;</div>
                            @endif
                        </td>
                        <td class="px-5 py-3 whitespace-nowrap">{{ $call->requested_date?->format('D, M j, Y') }}<br>
                            <span class="text-empower-muted">{{ $call->requested_time }}</span></td>
                        <td class="px-5 py-3 whitespace-nowrap">{{ $call->phone }}</td>
                        <td class="px-5 py-3">{{ $call->topic }}</td>
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-2">
                            <span x-bind:class="busy && 'opacity-40'" class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold
                                {{ match($call->status) {
                                    App\Enums\SpecialistCallStatus::Pending => 'bg-[#fff3cd] text-[#9a6700]',
                                    App\Enums\SpecialistCallStatus::Scheduled => 'bg-[#e6f3fb] text-[#087fa9]',
                                    App\Enums\SpecialistCallStatus::Completed => 'bg-[#dff7f0] text-[#0f7a4f]',
                                    App\Enums\SpecialistCallStatus::Cancelled => 'bg-[#eef1f5] text-[#5f6b7a]',
                                } }}">{{ $call->status->label() }}</span>
                            <span x-show="busy" x-cloak class="inline-flex items-center gap-1 text-xs font-semibold text-empower-muted" role="status">
                                <x-spinner class="h-3.5 w-3.5" /> Updating&hellip;
                            </span>
                            </div>
                        </td>
                        <td class="px-5 py-3">
                            <select data-tour="row-status" x-on:change="busy = true; $wire.setStatus({{ $call->id }}, $event.target.value).finally(() => busy = false)" x-bind:disabled="busy"
                                class="rounded-lg border border-empower-border bg-white px-2 py-1 text-xs text-empower-text">
                                <option value="">Change status…</option>
                                @foreach(App\Enums\SpecialistCallStatus::cases() as $case)
                                @if($case !== $call->status)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                @endif
                                @endforeach
                            </select>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-5 py-8 text-center text-empower-muted">No call requests yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $this->calls->links() }}
</div>
