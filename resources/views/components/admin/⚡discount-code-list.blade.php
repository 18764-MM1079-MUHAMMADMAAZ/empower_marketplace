<?php

use App\Enums\DiscountType;
use App\Models\ActivityLog;
use App\Models\DiscountCode;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function discountCodes(): Collection
    {
        return DiscountCode::latest()->get();
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function statusFor(DiscountCode $discountCode): array
    {
        return match (true) {
            ! $discountCode->is_active => ['Inactive', 'bg-[#edf2f7] text-empower-muted'],
            $discountCode->isExpired() => ['Expired', 'bg-red-50 text-red-700'],
            $discountCode->hasReachedUsageLimit() => ['Limit Reached', 'bg-[#fdf3e0] text-[#9a6700]'],
            default => ['Active', 'bg-[#dff7f0] text-[#0f7a4f]'],
        };
    }

    public function toggleActive(int $discountCodeId): void
    {
        $discountCode = DiscountCode::findOrFail($discountCodeId);
        $discountCode->update(['is_active' => ! $discountCode->is_active]);

        ActivityLog::record(
            $discountCode->is_active ? 'discount_code.activated' : 'discount_code.deactivated',
            "{$discountCode->code} was ".($discountCode->is_active ? 'activated' : 'deactivated').'.',
            user: auth()->user(),
            subject: $discountCode,
        );

        unset($this->discountCodes);

        $this->dispatch('toast', message: "{$discountCode->code} ".($discountCode->is_active ? 'activated' : 'deactivated').'.', type: 'success');
    }

    public function delete(int $discountCodeId): void
    {
        $discountCode = DiscountCode::findOrFail($discountCodeId);
        $code = $discountCode->code;
        $discountCode->delete();

        ActivityLog::record('discount_code.deleted', "{$code} was deleted.", user: auth()->user());

        unset($this->discountCodes);

        $this->dispatch('toast', message: "{$code} deleted.", type: 'success');
    }
};
?>

<div class="space-y-4" x-data="{ confirmId: null, confirmLabel: '' }">
    <div class="flex justify-between items-center">
        <h1 data-tour="page-title" class="text-2xl font-bold text-navy">Discount Codes</h1>
        <a data-tour="new" href="{{ route('admin.discount-codes.create') }}" wire:navigate
            class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-4 py-2 text-xs font-bold text-white hover:bg-[#087fa9] transition-colors">
            + New Discount Code
        </a>
    </div>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] overflow-hidden">
        <div class="w-full overflow-x-auto">
            <table data-tour="table" class="w-full min-w-[820px] text-sm">
            <thead>
                <tr class="bg-page text-left text-xs font-extrabold uppercase tracking-wider text-empower-muted">
                    <th class="px-5 py-3">Code</th>
                    <th class="px-5 py-3">Type</th>
                    <th class="px-5 py-3">Value</th>
                    <th class="px-5 py-3">Expires</th>
                    <th class="px-5 py-3">Used</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-empower-border">
                @forelse($this->discountCodes as $discountCode)
                    @php [$statusLabel, $statusClasses] = $this->statusFor($discountCode); @endphp
                    <tr class="hover:bg-page/60 transition-colors">
                        <td class="px-5 py-3.5">
                            <div class="font-mono font-semibold text-navy">{{ $discountCode->code }}</div>
                        </td>
                        <td class="px-5 py-3.5 text-empower-text">{{ $discountCode->type->label() }}</td>
                        <td class="px-5 py-3.5 text-empower-text">
                            {{ $discountCode->type === DiscountType::Percentage
                                ? $discountCode->percentage.'%'
                                : $discountCode->trial_days.' days' }}
                        </td>
                        <td class="px-5 py-3.5 text-empower-text">
                            {{ $discountCode->expires_at?->format('M j, Y') ?? 'Never' }}
                        </td>
                        <td class="px-5 py-3.5 text-empower-text">
                            {{ $discountCode->used_count }}{{ $discountCode->max_uses ? ' / '.$discountCode->max_uses : '' }}
                        </td>
                        <td class="px-5 py-3.5">
                            <button wire:click="toggleActive({{ $discountCode->id }})" wire:target="toggleActive({{ $discountCode->id }})"
                                wire:loading.attr="disabled" wire:target="toggleActive({{ $discountCode->id }})"
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[0.68rem] font-extrabold uppercase tracking-wider transition-colors {{ $statusClasses }}">
                                <span wire:loading.remove wire:target="toggleActive({{ $discountCode->id }})">{{ $statusLabel }}</span>
                                <span wire:loading wire:target="toggleActive({{ $discountCode->id }})"><x-spinner class="h-3 w-3" /></span>
                            </button>
                        </td>
                        <td class="px-5 py-3.5 text-right space-x-3">
                            <a href="{{ route('admin.discount-codes.send', $discountCode) }}" wire:navigate class="text-xs font-bold text-[#1a7aad] hover:underline">Send</a>
                            <a href="{{ route('admin.discount-codes.edit', $discountCode) }}" wire:navigate class="text-xs font-bold text-[#0b9ed0] hover:underline">Edit</a>
                            <button type="button" x-on:click="confirmId = {{ $discountCode->id }}; confirmLabel = @js($discountCode->code)"
                                class="text-xs font-bold text-red-600 hover:underline">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-10 text-center text-sm text-empower-muted italic">No discount codes yet.</td>
                    </tr>
                @endforelse
            </tbody>
            </table>
        </div>
    </div>

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
