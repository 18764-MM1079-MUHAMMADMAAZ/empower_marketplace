<?php

use App\Enums\AdminEmail;
use App\Enums\BillingCycle;
use App\Enums\UserRole;
use App\Mail\PlanChangeRequestMail;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\User;
use App\Notifications\PlanChangeRequestedNotification;
use App\Services\ClientAccount;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * My Account › Plan (v5): the package, provider count and what's included. Card-paying clients can
 * "Request a change" (provider count, billing period, note): the Empower team is emailed and
 * notified and makes the change in the admin panel; nothing is charged here. talkEHR / CareCloud
 * clients change their plan with the partner.
 */
new class extends Component
{
    public const MAX_REQUESTS_PER_HOUR = 3;

    public bool $requesting = false;

    public int $requestedProviders = 1;

    public string $requestedCycle = 'annual';

    public string $note = '';

    #[Computed]
    public function account(): ClientAccount
    {
        return ClientAccount::for(auth()->user());
    }

    /** The subscription a request is about: the newest active one. */
    #[Computed]
    public function order(): ?Order
    {
        return $this->account->primaryOrder();
    }

    #[Computed]
    public function canRequest(): bool
    {
        $order = $this->order;

        return $order !== null
            && ! $this->account->isPartnerBilled($order)
            && in_array($this->account->billingState($order), ['active', 'trial', 'past_due'], true);
    }

    /** The latest request in the last 30 days, shown under the button. */
    #[Computed]
    public function lastRequest(): ?ActivityLog
    {
        return ActivityLog::where('user_id', auth()->id())
            ->where('event_type', 'plan.change_requested')
            ->where('created_at', '>=', now()->subDays(30))
            ->latest('id')
            ->first();
    }

    private function currentProviders(): int
    {
        return max(1, (int) ($this->account->user->practice?->billable_providers_count ?? 1));
    }

    private function currentCycle(): string
    {
        return ($this->order?->billing_cycle ?? BillingCycle::Annual)->value;
    }

    public function startRequest(): void
    {
        abort_unless($this->canRequest, 403);

        $this->requestedProviders = $this->currentProviders();
        $this->requestedCycle = $this->currentCycle();
        $this->note = '';
        $this->resetErrorBag();
        $this->requesting = true;
    }

    public function cancelRequest(): void
    {
        $this->requesting = false;
        $this->resetErrorBag();
    }

    public function sendRequest(): void
    {
        abort_unless($this->canRequest, 403);

        $cycles = $this->order->package?->hasMonthlyPricing() ? ['annual', 'monthly'] : ['annual'];

        $this->validate([
            'requestedProviders' => 'required|integer|min:1|max:9999',
            'requestedCycle' => 'required|in:'.implode(',', $cycles),
            'note' => 'nullable|string|max:1000',
        ], [
            'requestedCycle.in' => "Monthly billing isn't available for your package.",
        ], ['requestedProviders' => 'billable providers']);

        $change = [
            'current_providers' => $this->currentProviders(),
            'providers' => $this->requestedProviders,
            'current_cycle' => $this->currentCycle(),
            'cycle' => $this->requestedCycle,
            'note' => trim($this->note) ?: null,
        ];

        if ($change['providers'] === $change['current_providers'] && $change['cycle'] === $change['current_cycle'] && $change['note'] === null) {
            $this->addError('requestedProviders', 'Change the provider count or billing period, or add a note.');

            return;
        }

        $key = 'plan-change-request:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, self::MAX_REQUESTS_PER_HOUR)) {
            $this->addError('requestedProviders', 'You have sent several requests already. The Empower team will be in touch; please try again later.');

            return;
        }

        RateLimiter::hit($key, 3600);

        $user = auth()->user();
        $summary = collect([
            $change['providers'] !== $change['current_providers'] ? "{$change['current_providers']} → {$change['providers']} billable providers" : null,
            $change['cycle'] !== $change['current_cycle'] ? 'billed '.($change['cycle'] === 'monthly' ? 'monthly' : 'annually') : null,
            $change['note'] ? 'note added' : null,
        ])->filter()->implode(', ');

        ActivityLog::record('plan.change_requested', "Requested a plan change: {$summary}", user: $user, order: $this->order, metadata: $change);

        $admins = User::where('role', UserRole::Admin)->get();

        $admins->filter(fn (User $admin) => $admin->wantsEmail(AdminEmail::PlanChangeRequests))
            ->each(function (User $admin) use ($user, $change) {
                try {
                    Mail::to($admin->email)->send(new PlanChangeRequestMail($user, $this->order, $change));
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        try {
            Notification::send($admins, new PlanChangeRequestedNotification($user, $summary));
        } catch (\Throwable $e) {
            report($e);
        }

        $this->requesting = false;
        unset($this->lastRequest);
        $this->dispatch('toast', message: 'Request sent. The Empower team will update your plan and let you know.', type: 'success');
    }
};
?>

@php
$account = $this->account;
$orders = $account->activeOrders()->isNotEmpty() ? $account->activeOrders() : collect([$account->primaryOrder()])->filter();
$providers = (int) ($account->user->practice?->billable_providers_count ?? 0);
$partnerBilled = $account->isPartnerBilled($orders->first());
$partner = $account->user->practice?->source_system === 'cch' ? 'CareCloud' : 'talkEHR';
$plainFeature = fn (string $feature) => trim(preg_replace('/[\x{00B9}\x{00B2}\x{00B3}\x{2070}-\x{2079}]/u', '', $feature));
$title = $orders->map(fn ($o) => $o->package ? str($o->package->name)->replaceLast(' Compliance', '')->value() : null)->filter()->unique()->implode(' + ');
$features = $orders->flatMap(fn ($o) => $o->package?->features ?? [])->map($plainFeature)->filter()->unique()->values();
$monthlyAvailable = (bool) $this->order?->package?->hasMonthlyPricing();
$button = 'inline-flex h-10 items-center rounded-lg border px-4 text-[13px] font-semibold transition-colors';
$field = 'h-10 rounded-lg border border-[#cbd5e1] bg-white px-3 text-sm text-[#0f2a44] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0]';
@endphp

<section id="plan" data-account-section aria-labelledby="h-plan"
    class="scroll-mt-32 lg:scroll-mt-24 bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 id="h-plan" class="text-lg font-semibold text-[#12304f]">Plan: {{ $title ?: '—' }}</h2>
            @if($providers > 0)
            <div class="mt-2.5 flex flex-wrap gap-2">
                <span class="rounded-full bg-[#eef2f6] px-2.5 py-0.5 text-xs font-semibold text-[#334155]">{{ $providers }} billable {{ Str::plural('provider', $providers) }}</span>
            </div>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            @if($this->canRequest && ! $requesting)
            <button type="button" wire:click="startRequest" data-request-plan-change class="{{ $button }} border-[#cbd5e1] bg-white text-[#173045] hover:bg-[#f4f7fb]">Request a change</button>
            @endif
            <a href="{{ route('home') }}#pricing" data-compare-packages class="{{ $button }} border-[#cbd5e1] bg-white text-[#173045] hover:bg-[#f4f7fb]">Compare packages</a>
        </div>
    </div>

    @if($requesting)
    <form wire:submit="sendRequest" novalidate data-plan-change-form class="mt-5 rounded-xl border border-[#e3e8ef] bg-[#f8fafc] p-4">
        <h3 class="text-sm font-semibold text-[#173045]">Request a change</h3>
        <p class="mt-0.5 text-[13px] text-[#5d6e7f]">Tell us what you'd like. The Empower team updates your plan and lets you know. Nothing is charged now; your next renewal reflects the change.</p>
        <div class="mt-3.5 grid grid-cols-1 sm:grid-cols-2 gap-3.5 max-w-2xl">
            <label class="flex flex-col gap-1.5 text-[13px] font-medium text-[#173045]">Billable providers
                <input type="number" min="1" max="9999" wire:model="requestedProviders" class="{{ $field }}" data-requested-providers>
            </label>
            <label class="flex flex-col gap-1.5 text-[13px] font-medium text-[#173045]">Billing period
                <select wire:model="requestedCycle" class="{{ $field }}" data-requested-cycle>
                    <option value="annual">Annually</option>
                    @if($monthlyAvailable)<option value="monthly">Monthly</option>@endif
                </select>
            </label>
            <label class="sm:col-span-2 flex flex-col gap-1.5 text-[13px] font-medium text-[#173045]">Note <span class="font-normal text-[#5d6e7f]">(optional)</span>
                <textarea wire:model="note" rows="2" maxlength="1000" placeholder="Anything we should know, e.g. when the change should start" class="rounded-lg border border-[#cbd5e1] bg-white px-3 py-2 text-sm text-[#0f2a44] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] resize-none"></textarea>
            </label>
        </div>
        @error('requestedProviders') <p class="mt-2 text-xs text-[#b42318]">{{ $message }}</p> @enderror
        @error('requestedCycle') <p class="mt-2 text-xs text-[#b42318]">{{ $message }}</p> @enderror
        @error('note') <p class="mt-2 text-xs text-[#b42318]">{{ $message }}</p> @enderror
        <div class="mt-3.5 flex flex-wrap gap-2">
            <button type="submit" wire:loading.attr="disabled" wire:target="sendRequest" class="{{ $button }} border-[#2299dd] bg-[#2299dd] text-white hover:bg-[#087fa9]">Send request</button>
            <button type="button" wire:click="cancelRequest" class="{{ $button }} border-[#cbd5e1] bg-white text-[#173045] hover:bg-[#f4f7fb]">Cancel</button>
        </div>
    </form>
    @elseif($this->canRequest && $this->lastRequest)
    <p role="status" class="mt-4 rounded-lg bg-[#e8f1fa] px-3.5 py-2.5 text-[13px] text-[#145a93]" data-plan-change-pending>
        Change requested on {{ $this->lastRequest->created_at->format('M j, Y') }}. The Empower team will update your plan and let you know.
    </p>
    @endif

    @if($features->isNotEmpty())
    <ul aria-label="Included in your package" class="mt-5 pt-5 border-t border-[#eef2f6] grid grid-cols-1 md:grid-cols-2 gap-x-7 gap-y-3 text-sm text-[#334155]">
        @foreach($features as $feature)
        <li class="flex items-start gap-2.5">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-[#1f9d61]" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5" /></svg>
            {{ $feature }}
        </li>
        @endforeach
    </ul>
    @endif

    <p class="mt-4.5 flex items-center gap-2 text-[13px] text-[#5d6e7f]" data-plan-billing-note>
        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" /></svg>
        @if($partnerBilled)
        Invoices and payments for this plan are managed through your {{ $partner }} Plan. No payment method is stored with Empower. To change your plan, contact {{ $partner }}.
        @else
        Payments for this plan are charged to your card on file. Receipts, card details and cancellation are on your portal dashboard.
        @endif
    </p>
</section>
