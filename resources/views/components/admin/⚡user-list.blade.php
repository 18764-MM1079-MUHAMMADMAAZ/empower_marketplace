<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exports\UsersExport;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\User;
use App\Services\TrialBillingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
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
    public string $role = '';

    public ?string $endTrialSuccessMessage = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    private function baseQuery(): Builder
    {
        return User::query()
            ->withCount('orders')
            ->with('practice')
            ->with(['orders' => fn ($q) => $q->where('payment_status', PaymentStatus::Trialing)->latest()])
            ->when($this->search !== '', function ($q) {
                $search = $this->search;
                $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            })
            ->when($this->role !== '', fn ($q) => $q->where('role', $this->role))
            ->latest();
    }

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return $this->baseQuery()->paginate(10);
    }

    /**
     * The packages each client on this page has bought (live orders: paid, trialling or partner
     * billed, not cancelled), grouped by practice. Partner (CCH / talkEHR) orders can belong to
     * different practices of the same login; Marketplace only keeps the partner's practice id for
     * those, so a practice other than the user's own is shown by that id.
     *
     * @return array<int, array<int, array{practice: string, packages: array<int, array{name: string, note: ?string}>}>>
     */
    #[Computed]
    public function packageGroups(): array
    {
        $users = collect($this->users->items())->keyBy('id');

        return Order::query()
            ->whereIn('user_id', $users->keys())
            ->whereIn('payment_status', Order::PAID_STATUSES)
            ->where('status', '!=', OrderStatus::Cancelled)
            ->whereNull('cancelled_at')
            ->with('package')
            ->oldest()->oldest('id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($orders, $userId) => $this->groupByPractice($users[$userId], $orders))
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     * @return array<int, array{practice: string, packages: array<int, array{name: string, note: ?string}>}>
     */
    private function groupByPractice(User $user, $orders): array
    {
        $ownId = (string) $user->practice?->external_practice_id;
        $ownName = $user->practice?->name ?: 'Practice not named yet';

        // Keys are prefixed: PHP turns a numeric-looking key like "1011163" into an int, which then
        // never matched the practice profile's id and showed "Partner practice 1011163" instead.
        return $orders
            ->groupBy(fn (Order $order) => 'p:'.($order->external_practice_id ?? $ownId))
            ->map(function ($practiceOrders, $key) use ($ownId, $ownName) {
                $practiceId = substr($key, 2);

                return [
                    'practice' => match (true) {
                        $practiceId === '' || $practiceId === $ownId => $ownName,
                        default => $practiceOrders->pluck('external_practice_name')->filter()->last() ?? "Partner practice {$practiceId}",
                    },
                'packages' => $practiceOrders->map(fn (Order $order) => [
                    'name' => $order->package?->name ?? 'Deleted package',
                    'note' => match (true) {
                        $order->payment_status === PaymentStatus::Trialing => 'trial',
                        $order->cancels_at !== null => 'ends '.$order->cancels_at->format('M j'),
                        default => null,
                    },
                ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /** "Professional (trial); Essential" per practice, for the Excel export. */
    private function packagesText(User $user): string
    {
        $orders = Order::where('user_id', $user->id)
            ->whereIn('payment_status', Order::PAID_STATUSES)
            ->where('status', '!=', OrderStatus::Cancelled)
            ->whereNull('cancelled_at')
            ->with('package')
            ->oldest()->oldest('id')
            ->get();

        return collect($this->groupByPractice($user, $orders))
            ->map(fn ($group) => $group['practice'].': '.collect($group['packages'])
                ->map(fn ($package) => $package['name'].($package['note'] ? " ({$package['note']})" : ''))
                ->implode(', '))
            ->implode('; ');
    }

    public function export()
    {
        $rows = $this->baseQuery()->get()->map(fn (User $user) => [
            $user->name,
            $user->email,
            ucfirst($user->role->value),
            $user->practice?->name,
            $this->packagesText($user),
            $user->orders_count,
            $user->is_active ? 'Active' : 'Inactive',
            $user->created_at?->toDateTimeString(),
        ]);

        return Excel::download(new UsersExport($rows), 'users-'.now()->format('Y-m-d').'.xlsx');
    }

    /**
     * Admin testing tool: forces an immediate charge attempt on a trial order's stored card — the
     * exact same code path the client's own "Proceed with Payment" action uses — so an admin can
     * verify the real MTBC Create_Charge integration works without waiting for a client to convert.
     */
    public function endTrial(int $orderId): void
    {
        $this->resetErrorBag('endTrial');
        $this->endTrialSuccessMessage = null;

        $order = Order::where('payment_status', PaymentStatus::Trialing)->findOrFail($orderId);

        $result = app(TrialBillingService::class)->convertTrialToPaid($order);

        if ($result->success) {
            $this->endTrialSuccessMessage = 'Charge succeeded'.($result->transactionId ? " (transaction {$result->transactionId})" : '').' — trial converted to paid.';
            $this->dispatch('toast', message: 'Trial converted to paid.', type: 'success');
        } else {
            $this->addError('endTrial', $result->declineMessage ?? 'The charge failed.');
            $this->dispatch('toast', message: $result->declineMessage ?? 'The trial charge failed.', type: 'error');
        }

        unset($this->users);
    }

    public function delete(int $userId): void
    {
        if ($userId === auth()->id()) {
            $this->addError('delete', "You can't delete your own account.");
            $this->dispatch('toast', message: "You can't delete your own account.", type: 'error');

            return;
        }

        $user = User::findOrFail($userId);
        $name = "{$user->name} ({$user->email})";

        if ($user->practice?->logo_path) {
            Storage::disk('local')->delete($user->practice->logo_path);
        }

        foreach ($user->orders as $order) {
            $order->deleteCascadingFiles();
        }

        $user->delete();

        ActivityLog::record('user.deleted', "{$name} was deleted.", user: auth()->user());

        unset($this->users);

        $this->dispatch('toast', message: "{$name} deleted.", type: 'success');
    }
};
?>

<div class="space-y-4" x-data="{ confirmId: null, confirmLabel: '', confirmEndTrialOrderId: null, confirmEndTrialLabel: '' }">
    @error('delete')
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>
    @enderror

    @error('endTrial')
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">Charge failed: {{ $message }}</div>
    @enderror

    @if($endTrialSuccessMessage)
        <div class="rounded-xl border border-[#bfe3d2] bg-[#eef8f3] px-4 py-3 text-sm text-[#0f7a4f]">{{ $endTrialSuccessMessage }}</div>
    @endif

    <div class="flex flex-wrap items-center gap-3 justify-between">
        <h1 data-tour="page-title" class="text-2xl font-bold text-navy">Users</h1>
        <div class="flex flex-wrap items-center gap-3">
        <select data-tour="filter" wire:model.live="role"
            class="rounded-xl border border-empower-border bg-white px-4 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
            <option value="">All roles</option>
            @foreach(UserRole::cases() as $case)
                <option value="{{ $case->value }}">{{ ucfirst($case->value) }}</option>
            @endforeach
        </select>

        <input data-tour="search" wire:model.live.debounce.400ms="search" type="text" placeholder="Search name or email…"
            class="w-full sm:w-64 rounded-xl border border-empower-border bg-white px-4 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">

        <button data-tour="export" type="button" wire:click="export" wire:loading.attr="disabled" wire:target="export"
            class="inline-flex items-center gap-1 rounded-lg border border-empower-border bg-[#dff7f0] px-4 py-2 text-xs font-bold text-[#0f7a4f] hover:bg-[#c7ebdc] transition-colors disabled:opacity-50">
            <span wire:loading.remove wire:target="export">Export to Excel</span>
            <span wire:loading.inline-flex wire:target="export" class="inline-flex items-center gap-1.5"><x-spinner class="h-3 w-3" /> Exporting…</span>
        </button>

        <a data-tour="new" href="{{ route('admin.users.create') }}" wire:navigate
            class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-4 py-2 text-xs font-bold text-white hover:bg-[#087fa9] transition-colors">
            + New User
        </a>
        </div>
    </div>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] overflow-hidden">
        <div class="w-full overflow-x-auto">
            <table data-tour="table" class="w-full min-w-[820px] text-sm">
            <thead>
                <tr class="bg-page text-left text-xs font-extrabold uppercase tracking-wider text-empower-muted">
                    <th class="px-5 py-3">Name</th>
                    <th class="px-5 py-3">Email</th>
                    <th class="px-5 py-3">Role</th>
                    <th class="px-5 py-3">Practice &amp; packages</th>
                    <th class="px-5 py-3">Orders</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-empower-border">
                @forelse($this->users as $user)
                    <tr class="hover:bg-page/60 transition-colors">
                        <td class="px-5 py-3.5 font-semibold text-navy">
                            {{ $user->name }}
                            @if($user->id === auth()->id())
                                <span class="text-xs font-normal text-empower-muted">(you)</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-empower-text">{{ $user->email }}</td>
                        <td class="px-5 py-3.5 text-empower-text capitalize">{{ $user->role->value }}</td>
                        <td class="px-5 py-3.5 text-empower-text" data-user-packages>
                            @php($groups = $this->packageGroups[$user->id] ?? [])
                            @forelse($groups as $group)
                                <div class="{{ $loop->first ? '' : 'mt-2' }}" data-practice-group>
                                    <p class="font-medium">{{ $group['practice'] }}</p>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @foreach($group['packages'] as $package)
                                            <span class="inline-flex items-center rounded-full bg-[#eaf4ff] px-2 py-0.5 text-[0.68rem] font-bold text-[#1a7aad]" data-package-chip>
                                                {{ $package['name'] }}@if($package['note'])<span class="ml-1 font-semibold text-empower-muted">· {{ $package['note'] }}</span>@endif
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @empty
                                {{ $user->practice?->name ?: '—' }}
                                @if($user->role === UserRole::Client && $user->practice)
                                    <p class="text-xs text-empower-muted">No active package</p>
                                @endif
                            @endforelse
                        </td>
                        <td class="px-5 py-3.5 text-empower-text">{{ $user->orders_count }}</td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.68rem] font-extrabold uppercase tracking-wider {{ $user->is_active ? 'bg-[#dff7f0] text-[#0f7a4f]' : 'bg-[#fde8e8] text-red-700' }}">
                                {{ $user->is_active ? 'Active' : 'Deactivated' }}
                            </span>
                            @if($trialOrder = $user->orders->first())
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.68rem] font-extrabold uppercase tracking-wider bg-[#eaf4ff] text-[#1a7aad]">
                                    Trialing
                                </span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right space-x-3">
                            @if($trialOrder)
                                <button type="button"
                                    x-on:click="confirmEndTrialOrderId = {{ $trialOrder->id }}; confirmEndTrialLabel = @js($user->name.' ('.$user->email.')')"
                                    class="text-xs font-bold text-[#1a7aad] hover:underline">End Trial</button>
                            @endif
                            <a href="{{ route('admin.users.edit', $user) }}" wire:navigate title="Edit" aria-label="Edit" class="inline-flex items-center p-1 rounded hover:bg-page text-[#0b9ed0]"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/></svg></a>
                            @if($user->id !== auth()->id())
                                <button type="button" x-on:click="confirmId = {{ $user->id }}; confirmLabel = @js($user->name.' ('.$user->email.')')"
                                    title="Delete" aria-label="Delete" class="inline-flex items-center p-1 rounded hover:bg-page text-red-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg></button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-10 text-center text-sm text-empower-muted italic">No users yet.</td>
                    </tr>
                @endforelse
            </tbody>
            </table>
        </div>
    </div>

    <div>{{ $this->users->links() }}</div>

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

    <div x-show="confirmEndTrialOrderId !== null" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm px-4">
        <div class="w-full max-w-sm bg-white rounded-[1.25rem] shadow-xl p-6" x-on:click.outside="confirmEndTrialOrderId = null">
            <h3 class="text-base font-semibold text-navy mb-2">End trial for <span x-text="confirmEndTrialLabel"></span>?</h3>
            <p class="text-sm text-empower-muted mb-5">This immediately charges the card on file via the real payment gateway — exactly as if the client had clicked "Proceed with Payment" themselves. Use this to verify the live payment integration is working.</p>
            <div class="flex justify-end gap-3">
                <button type="button" x-on:click="confirmEndTrialOrderId = null"
                    class="rounded-lg border border-empower-border px-4 py-2 text-sm font-semibold text-empower-muted hover:bg-page transition-colors">
                    Cancel
                </button>
                <button type="button" wire:target="endTrial"
                    x-on:click="$wire.endTrial(confirmEndTrialOrderId).then(() => confirmEndTrialOrderId = null).catch(() => {})"
                    wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="endTrial"
                    class="inline-flex items-center gap-1 rounded px-5 py-2 text-sm font-bold transition-colors bg-[#1a7aad] text-white hover:bg-[#0b9ed0]">
                    <span wire:loading.remove wire:target="endTrial">Charge Now</span>
                    <span wire:loading.inline-flex wire:target="endTrial" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Charging…</span>
                </button>
            </div>
        </div>
    </div>
</div>
