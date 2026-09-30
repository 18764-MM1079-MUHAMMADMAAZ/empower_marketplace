<?php

use App\Models\ActivityLog;
use App\Models\Lead;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function markContacted(int $leadId): void
    {
        Lead::where('id', $leadId)->update([
            'is_contacted' => true,
            'contacted_at' => now(),
            'contacted_by' => auth()->id(),
        ]);

        unset($this->leads);

        $this->dispatch('toast', message: 'Lead marked as contacted.', type: 'success');
    }

    public function delete(int $leadId): void
    {
        $lead = Lead::findOrFail($leadId);
        $name = $lead->name;
        $lead->delete();

        ActivityLog::record('lead.deleted', "{$name} was deleted.", user: auth()->user());

        unset($this->leads);

        $this->dispatch('toast', message: "{$name} deleted.", type: 'success');
    }

    #[Computed]
    public function leads(): LengthAwarePaginator
    {
        return Lead::query()
            ->when($this->search !== '', function ($q) {
                $search = $this->search;
                $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            })
            ->latest()
            ->paginate(10);
    }
};
?>

<div class="space-y-4" x-data="{ confirmId: null, confirmLabel: '' }">
    <div class="flex flex-wrap items-center gap-3 justify-end">
        <input wire:model.live.debounce.400ms="search" type="text" placeholder="Search name or email…"
            class="w-full sm:w-64 rounded-xl border border-empower-border bg-white px-4 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">

        <a href="{{ route('admin.leads.create') }}" wire:navigate
            class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-4 py-2 text-xs font-bold text-white hover:bg-[#087fa9] transition-colors">
            + New Lead
        </a>
    </div>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] overflow-hidden">
        <div class="w-full overflow-x-auto">
            <table class="w-full min-w-[760px] text-sm">
            <thead>
                <tr class="bg-page text-left text-xs font-extrabold uppercase tracking-wider text-empower-muted">
                    <th class="px-5 py-3">Name</th>
                    <th class="px-5 py-3">Contact</th>
                    <th class="px-5 py-3">Package Interest</th>
                    <th class="px-5 py-3">Received</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-empower-border">
                @forelse($this->leads as $lead)
                    <tr class="hover:bg-page/60 transition-colors">
                        <td class="px-5 py-3.5 font-semibold text-navy">{{ $lead->name }}</td>
                        <td class="px-5 py-3.5 text-empower-text">
                            <div>{{ $lead->email }}</div>
                            <div class="text-xs text-empower-muted">{{ $lead->phone }}</div>
                        </td>
                        <td class="px-5 py-3.5 text-empower-text">{{ $lead->package_interest ?: '—' }}</td>
                        <td class="px-5 py-3.5 text-empower-muted text-xs">{{ $lead->created_at?->diffForHumans() }}</td>
                        <td class="px-5 py-3.5 text-right space-x-3 whitespace-nowrap">
                            @if($lead->is_contacted)
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.68rem] font-extrabold uppercase tracking-wider bg-[#dff7f0] text-[#0f7a4f]">Contacted</span>
                            @else
                                <button wire:click="markContacted({{ $lead->id }})" wire:target="markContacted({{ $lead->id }})"
                                    wire:loading.attr="disabled" wire:loading.class="opacity-70" wire:target="markContacted({{ $lead->id }})"
                                    class="text-xs font-bold text-[#0b9ed0] hover:underline">
                                    <span wire:loading.remove wire:target="markContacted({{ $lead->id }})">Mark Contacted</span>
                                    <span wire:loading.inline-flex wire:target="markContacted({{ $lead->id }})" class="inline-flex items-center gap-1"><x-spinner class="h-3 w-3" /> Marking…</span>
                                </button>
                            @endif
                            <a href="{{ route('admin.leads.edit', $lead) }}" wire:navigate class="text-xs font-bold text-[#0b9ed0] hover:underline">Edit</a>
                            <button type="button" x-on:click="confirmId = {{ $lead->id }}; confirmLabel = @js($lead->name)"
                                class="text-xs font-bold text-red-600 hover:underline">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-10 text-center text-sm text-empower-muted italic">No leads yet.</td>
                    </tr>
                @endforelse
            </tbody>
            </table>
        </div>
    </div>

    <div>{{ $this->leads->links() }}</div>

    <div x-show="confirmId !== null" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
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
