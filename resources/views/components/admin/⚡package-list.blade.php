<?php

use App\Models\ActivityLog;
use App\Models\Package;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function packages(): Collection
    {
        return Package::orderBy('sort_order')->get();
    }

    public function toggleActive(int $packageId): void
    {
        $package = Package::findOrFail($packageId);
        $package->update(['is_active' => ! $package->is_active]);

        ActivityLog::record(
            $package->is_active ? 'package.activated' : 'package.deactivated',
            "{$package->name} was ".($package->is_active ? 'activated' : 'deactivated').'.',
            user: auth()->user(),
            subject: $package,
        );

        unset($this->packages);

        $this->dispatch('toast', message: "{$package->name} ".($package->is_active ? 'activated' : 'deactivated').'.', type: 'success');
    }

    public function delete(int $packageId): void
    {
        $package = Package::findOrFail($packageId);

        if ($package->orders()->exists()) {
            $this->addError('delete', "{$package->name} has existing orders and can't be deleted. Deactivate it instead.");
            $this->dispatch('toast', message: "{$package->name} has existing orders and can't be deleted.", type: 'error');

            return;
        }

        $name = $package->name;
        $package->delete();

        ActivityLog::record('package.deleted', "{$name} was deleted.", user: auth()->user());

        unset($this->packages);

        $this->dispatch('toast', message: "{$name} deleted.", type: 'success');
    }
};
?>

<div class="space-y-4" x-data="{ confirmId: null, confirmLabel: '' }">
    @error('delete')
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>
    @enderror

    <div class="flex justify-between items-center">
        <h1 class="text-2xl font-bold text-navy">Packages</h1>
        <a href="{{ route('admin.packages.create') }}" wire:navigate
            class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-4 py-2 text-xs font-bold text-white hover:bg-[#087fa9] transition-colors">
            + New Package
        </a>
    </div>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] overflow-hidden">
        <div class="w-full overflow-x-auto">
            <table class="w-full min-w-[860px] text-sm">
            <thead>
                <tr class="bg-page text-left text-xs font-extrabold uppercase tracking-wider text-empower-muted">
                    <th class="px-5 py-3">Package</th>
                    <th class="px-5 py-3">Billing</th>
                    <th class="px-5 py-3">Annual Price</th>
                    <th class="px-5 py-3">Monthly Price</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-empower-border">
                @forelse($this->packages as $package)
                    <tr class="hover:bg-page/60 transition-colors">
                        <td class="px-5 py-3.5">
                            <div class="font-semibold text-navy">{{ $package->name }}</div>
                            <div class="text-xs text-empower-muted">{{ $package->slug }}</div>
                        </td>
                        <td class="px-5 py-3.5 text-empower-text capitalize">{{ $package->billing_type }}</td>
                        <td class="px-5 py-3.5 text-empower-text">
                            {{ $package->annual_price !== null ? '$'.number_format($package->annual_price, 2) : 'Custom quote' }}
                        </td>
                        <td class="px-5 py-3.5 text-empower-text">
                            {{ $package->monthly_price !== null ? '$'.number_format($package->monthly_price, 2) : '—' }}
                        </td>
                        <td class="px-5 py-3.5">
                            <button wire:click="toggleActive({{ $package->id }})" wire:target="toggleActive({{ $package->id }})"
                                wire:loading.attr="disabled" wire:target="toggleActive({{ $package->id }})"
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[0.68rem] font-extrabold uppercase tracking-wider transition-colors {{ $package->is_active ? 'bg-[#dff7f0] text-[#0f7a4f]' : 'bg-[#edf2f7] text-empower-muted' }}">
                                <span wire:loading.remove wire:target="toggleActive({{ $package->id }})">{{ $package->is_active ? 'Active' : 'Inactive' }}</span>
                                <span wire:loading wire:target="toggleActive({{ $package->id }})"><x-spinner class="h-3 w-3" /></span>
                            </button>
                        </td>
                        <td class="px-5 py-3.5 text-right space-x-3">
                            <a href="{{ route('admin.packages.edit', $package) }}" wire:navigate class="text-xs font-bold text-[#0b9ed0] hover:underline">Edit</a>
                            <button type="button" x-on:click="confirmId = {{ $package->id }}; confirmLabel = @js($package->name)"
                                class="text-xs font-bold text-red-600 hover:underline">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-10 text-center text-sm text-empower-muted italic">No packages yet.</td>
                    </tr>
                @endforelse
            </tbody>
            </table>
        </div>
    </div>

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
