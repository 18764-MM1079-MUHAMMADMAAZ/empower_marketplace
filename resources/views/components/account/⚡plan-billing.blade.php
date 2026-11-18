<?php

use App\Enums\BillingCycle;
use App\Enums\CardBrand;
use App\Enums\PaymentStatus;
use App\Exceptions\EmpowerPaymentApiException;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\PaymentLog;
use App\Services\ClientAccount;
use App\Services\EmpowerPaymentApiClient;
use App\Services\PlanChangeService;
use App\Support\CardRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /** The plan the client is building (provider stepper + billing period switch). */
    public int $providers = 1;

    public string $cycle = 'annual';

    /** Second click needed before a prorated charge is made. */
    public bool $confirmingCharge = false;

    public bool $editingAddress = false;

    public string $address1 = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    #[Computed]
    public function account(): ClientAccount
    {
        return ClientAccount::for(auth()->user());
    }

    #[On('account-updated')]
    public function refreshAccount(): void
    {
        unset($this->account, $this->history);
    }

    /** The order whose card and billing address are shown: the newest active subscription that has
     *  them saved (a partner-linked or simulated order may have neither), else the main one. */
    #[Computed]
    public function billingOrder(): ?Order
    {
        return $this->account->activeOrders()->first(fn (Order $order) => $order->card_last_four || filled($order->billing_address['address1'] ?? null))
            ?? $this->account->primaryOrder();
    }

    /** Past charges (newest first) plus each subscription's next scheduled charge on top. */
    #[Computed]
    public function history(): Collection
    {
        $scheduled = $this->account->activeOrders()
            ->map(fn (Order $order) => [$order, $this->account->nextCharge($order)])
            ->filter(fn ($pair) => $pair[1] !== null)
            ->map(fn ($pair) => [
                'date' => $pair[1]['date'],
                'description' => ($pair[0]->payment_status === PaymentStatus::Trialing ?'First payment after free trial' : ($pair[0]->billing_cycle === BillingCycle::Monthly ? 'Monthly renewal' : 'Annual renewal'))
                    .' · '.$pair[0]->package?->name,
                'amount' => $pair[1]['amount'],
                'status' => ['Scheduled', 'muted'],
                'receipt' => null,
            ]);

        $past = PaymentLog::where('user_id', auth()->id())
            ->with('package')
            ->latest('id')
            ->limit(24)
            ->get()
            ->map(fn (PaymentLog $log) => [
                'date' => $log->created_at,
                'description' => ($log->package?->name ?? 'Empower package').($log->success ? '' : ' · '.($log->message ?: 'Payment declined')),
                'amount' => (float) $log->amount,
                'status' => $log->success ? ['Paid', 'good'] : ['Declined', 'bad'],
                'receipt' => $log->success ? route('payments.receipt', $log) : null,
            ]);

        return $scheduled->sortBy('date')->values()->concat($past);
    }

    public function mount(): void
    {
        $this->resetPlan();
    }

    private function plans(): PlanChangeService
    {
        return app(PlanChangeService::class);
    }

    /** Subscriptions the stepper and period switch apply to. */
    #[Computed]
    public function manageable(): Collection
    {
        return $this->plans()->orders(auth()->user());
    }

    /** @return array{changed: bool, charge: float, notes: list<string>, error: ?string, next: ?array} */
    #[Computed]
    public function planPreview(): array
    {
        return $this->plans()->preview(auth()->user(), $this->providers, BillingCycle::tryFrom($this->cycle) ?? BillingCycle::Annual);
    }

    public function resetPlan(): void
    {
        $current = $this->plans()->current(auth()->user());
        $this->providers = $current['providers'];
        $this->cycle = $current['cycle']->value;
        $this->confirmingCharge = false;
        unset($this->planPreview);
    }

    public function changeProviders(int $by): void
    {
        $this->providers = max(1, min(9999, $this->providers + $by));
        $this->confirmingCharge = false;
    }

    public function updatedProviders(): void
    {
        $this->providers = max(1, min(9999, (int) $this->providers));
        $this->confirmingCharge = false;
    }

    public function setCycle(string $cycle): void
    {
        $this->cycle = BillingCycle::tryFrom($cycle)?->value ?? $this->cycle;
        $this->confirmingCharge = false;
    }

    /** Saves the plan. When it means charging the card, the first click only asks to confirm. */
    public function savePlan(): void
    {
        $preview = $this->planPreview;

        if ($preview['error']) {
            $this->dispatch('toast', message: $preview['error'], type: 'error');

            return;
        }

        if ($preview['charge'] > 0 && ! $this->confirmingCharge) {
            $this->confirmingCharge = true;

            return;
        }

        $result = $this->plans()->apply(auth()->user(), $this->providers, BillingCycle::from($this->cycle));
        $this->dispatch('toast', message: $result['message'], type: $result['ok'] ? 'success' : 'error');
        $this->confirmingCharge = false;

        if ($result['ok']) {
            auth()->user()->unsetRelation('practice');
            unset($this->account, $this->billingOrder, $this->history, $this->manageable);
            $this->resetPlan();
            $this->dispatch('account-updated');
        }
    }

    /** Subscriptions charged to a card we hold, so the ones a new card is saved on. */
    private function cardOrders(): Collection
    {
        return $this->account->activeOrders()->reject(fn (Order $order) => $this->account->isPartnerBilled($order))->values();
    }

    /**
     * Saves a new card on every active subscription. The card fields arrive as arguments from the
     * form (never as component properties) so the number is never serialized into the page; only
     * the vault token, last four, brand, expiry and name are stored. Returns true on success so
     * the form can close.
     */
    public function updateCard(string $cardName = '', string $cardNumber = '', string $cardExpiry = '', string $cardCvc = ''): bool
    {
        $this->resetErrorBag();
        $orders = $this->cardOrders();

        if ($orders->isEmpty()) {
            $this->addError('card', 'There is no active subscription to save a card on.');

            return false;
        }

        $cardNumber = preg_replace('/\D/', '', $cardNumber);
        $validator = Validator::make(compact('cardName', 'cardNumber', 'cardExpiry', 'cardCvc'), CardRules::rules(), CardRules::messages(), CardRules::attributes());

        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return false;
        }

        try {
            $token = app(EmpowerPaymentApiClient::class)->tokenize($cardNumber, $cardCvc);
        } catch (EmpowerPaymentApiException $e) {
            report($e);
            $this->addError('card', 'We could not save your card. Please check your details and try again.');

            return false;
        }

        [$month, $year] = explode('/', $cardExpiry);

        foreach ($orders as $order) {
            $order->update([
                'clover_card_token' => $token['token'],
                'card_expiry_month' => (int) $month,
                'card_expiry_year' => (int) ('20'.$year),
                'card_last_four' => $token['lastFour'] ?? substr($cardNumber, -4),
                'card_brand' => CardBrand::fromNumber($token['firstSix'] ?? $cardNumber),
                'card_holder_name' => trim($cardName),
                'mtbc_reference_number' => $token['referenceNumber'],
            ]);

            ActivityLog::record('order.card_updated', "Payment method updated for {$order->package?->name} from My Account.", user: auth()->user(), order: $order);
        }

        unset($this->account, $this->billingOrder);
        $pastDue = $orders->contains(fn (Order $order) => $order->payment_status === PaymentStatus::PastDue);
        $this->dispatch('toast', message: $pastDue
            ? 'Card saved. We\'ll retry your payment with it automatically.'
            : 'Payment method updated.', type: 'success');
        $this->dispatch('account-updated');

        return true;
    }

    public function clearCardErrors(): void
    {
        $this->resetErrorBag(['card', 'cardName', 'cardNumber', 'cardExpiry', 'cardCvc']);
    }

    public function editAddress(): void
    {
        $address = $this->billingOrder?->billing_address ?? [];
        $this->address1 = (string) ($address['address1'] ?? '');
        $this->city = (string) ($address['city'] ?? '');
        $this->state = (string) ($address['state'] ?? '');
        $this->zip = (string) ($address['zip'] ?? '');
        $this->resetErrorBag();
        $this->editingAddress = true;
    }

    public function cancelAddress(): void
    {
        $this->editingAddress = false;
        $this->resetErrorBag();
    }

    /** Saved on every active subscription, since each one charges the card with this address. */
    public function saveAddress(): void
    {
        $validated = $this->validate([
            'address1' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:50',
            'zip' => ['required', 'string', 'regex:/^\d{5}(-\d{4})?$/'],
        ], ['zip.regex' => 'Enter a 5-digit ZIP code (or ZIP+4).'], ['address1' => 'street address', 'zip' => 'ZIP code']);

        $orders = $this->account->activeOrders()->reject(fn (Order $order) => $this->account->isPartnerBilled($order));

        foreach ($orders as $order) {
            $order->update(['billing_address' => array_merge($order->billing_address ?? [], $validated)]);
        }

        ActivityLog::record('billing.address_updated', 'Billing address updated from My Account.', user: auth()->user(), order: $this->billingOrder);

        $this->editingAddress = false;
        unset($this->account, $this->billingOrder);
        $this->dispatch('toast', message: 'Billing address updated.', type: 'success');
    }
};
?>

@php
$account = $this->account;
$orders = $account->activeOrders();
if ($orders->isEmpty() && $account->primaryOrder()) {
    $orders = collect([$account->primaryOrder()]);
}
$billingOrder = $this->billingOrder;
$billingState = $billingOrder ? $account->billingState($billingOrder) : null;
$providerCount = auth()->user()->practice?->billable_providers_count;
$money = fn (float $amount, bool $cents = false) => '$'.number_format($amount, $cents || $amount != floor($amount) ? 2 : 0);
$address = $billingOrder?->billing_address ?? [];
$inputClass = 'w-full rounded-lg border border-[#d4e5f1] bg-white px-3 py-2 text-sm text-[#173a59] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent';
@endphp

<section id="plan" data-account-section aria-labelledby="h-plan"
    class="scroll-mt-32 lg:scroll-mt-24 bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
        <div>
            <h2 id="h-plan" class="text-lg font-semibold text-[#12304f]">Plan &amp; billing</h2>
            <p class="text-sm text-[#5d6e7f]">Your package is priced per billable provider.</p>
        </div>
        <a href="{{ route('home') }}#pricing" class="rounded-lg border border-[#dbe4ee] px-3.5 py-1.5 text-xs font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">Compare packages</a>
    </div>

    <div class="grid gap-4 md:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="space-y-4">
            @foreach($orders as $order)
            @php
            $state = $account->billingState($order);
            $cycle = $order->billing_cycle ?? \App\Enums\BillingCycle::Annual;
            $perProvider = $order->package?->priceForCycle($cycle);
            $next = $account->nextCharge($order);
            [$stateLabel, $stateTone] = match ($state) {
                'trial' => ['Free trial', 'info'],
                'past_due' => ['Payment due', 'warn'],
                'cancelled' => ['Cancelled', 'bad'],
                'ending' => ['Ends '.$order->cancels_at->format('M j, Y'), 'warn'],
                'partner' => ['Partner billed', 'muted'],
                'unpaid' => ['Not paid', 'muted'],
                default => ['Active', 'good'],
            };
            // The stepper and period switch live in the main manageable subscription's panel.
            $editable = $order->id === $this->manageable->first()?->id && $state !== 'ending';
            @endphp
            <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] p-4 sm:p-5" data-plan-order="{{ $order->id }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-base font-bold text-[#12304f]">{{ $order->package?->name ?? 'Empower package' }}</h3>
                    @include('account._chip', ['label' => $stateLabel, 'tone' => $stateTone])
                </div>
                @if($perProvider !== null && $state !== 'partner')
                <p class="mt-2"><span class="text-2xl font-extrabold text-[#12304f]">{{ $money($perProvider) }}</span>
                    <span class="text-sm text-[#5d6e7f]">per provider / {{ $cycle->period() }}</span></p>
                @endif

                @if(! empty($order->package?->features))
                <ul class="mt-3 grid gap-1.5 sm:grid-cols-2 text-sm text-[#173045]">
                    @foreach($order->package->features as $feature)
                    <li class="flex items-start gap-2">
                        <svg class="h-4 w-4 mt-0.5 shrink-0 text-[#1f9d6b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.6" d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                        <span>{{ is_array($feature) ? ($feature['label'] ?? $feature['name'] ?? '') : $feature }}</span>
                    </li>
                    @endforeach
                </ul>
                @endif

                @if($editable)
                @php
                $preview = $this->planPreview;
                $practice = auth()->user()->practice;
                $nextShown = $preview['changed'] && $preview['next'] ? $preview['next'] : $next;
                $monthlyAvailable = $order->package?->hasMonthlyPricing();
                @endphp
                <dl class="mt-4 divide-y divide-[#e6eef5] border-t border-[#e6eef5] text-sm" data-plan-editor>
                    <div class="flex items-center justify-between gap-3 py-2">
                        <dt class="text-[#5d6e7f]">Billable providers</dt>
                        <dd class="inline-flex items-center rounded-lg border border-[#dbe4ee] bg-white" role="group" aria-label="Billable providers">
                            <button type="button" wire:click="changeProviders(-1)" aria-label="Fewer providers" @disabled($this->providers <= 1)
                                class="h-8 w-8 text-lg font-bold text-[#12304f] hover:bg-[#f4f7fb] rounded-l-lg disabled:opacity-40">−</button>
                            <output class="min-w-10 text-center font-bold text-[#173045]" data-providers>{{ $this->providers }}</output>
                            <button type="button" wire:click="changeProviders(1)" aria-label="More providers"
                                class="h-8 w-8 text-lg font-bold text-[#12304f] hover:bg-[#f4f7fb] rounded-r-lg">+</button>
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 py-2">
                        <dt class="text-[#5d6e7f]">Billing period</dt>
                        <dd class="inline-flex rounded-lg border border-[#dbe4ee] bg-white p-0.5" role="group" aria-label="Billing period">
                            @foreach(['monthly' => 'Monthly', 'annual' => 'Annually'] as $value => $label)
                            @continue($value === 'monthly' && ! $monthlyAvailable && $this->cycle !== 'monthly')
                            <button type="button" wire:click="setCycle('{{ $value }}')" aria-pressed="{{ $this->cycle === $value ? 'true' : 'false' }}"
                                class="rounded-md px-3 py-1 text-xs font-bold transition-colors {{ $this->cycle === $value ? 'bg-[#12304f] text-white' : 'text-[#5d6e7f] hover:text-[#12304f]' }}">{{ $label }}</button>
                            @endforeach
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3 py-2.5">
                        <dt class="text-[#5d6e7f]">{{ $state === 'trial' ? 'First payment' : 'Next invoice' }}</dt>
                        <dd class="font-semibold text-[#173045] text-right" data-next-invoice>
                            @if($nextShown)
                            {{ $money($nextShown['amount']) }} on {{ $nextShown['date']->format('M j, Y') }}
                            @else
                            —
                            @endif
                        </dd>
                    </div>
                </dl>

                @if(! $preview['changed'] && ($practice?->scheduled_billable_providers || $order->scheduled_billing_cycle))
                <p class="mt-3 text-xs text-[#5d6e7f]" data-scheduled-change>
                    From your next renewal{{ $order->next_bill_date ? ' on '.$order->next_bill_date->format('M j, Y') : '' }}:
                    {{ collect([
                        $practice?->scheduled_billable_providers ? $practice->scheduled_billable_providers.' '.Str::plural('provider', $practice->scheduled_billable_providers).' (now '.$practice->billable_providers_count.')' : null,
                        $order->scheduled_billing_cycle ? $order->scheduled_billing_cycle->label().' billing' : null,
                    ])->filter()->implode(', ') }}.
                </p>
                @endif

                @if($preview['changed'])
                <div class="mt-3 space-y-1.5 text-xs" data-plan-notes>
                    @if($preview['error'])
                    <p class="text-[#c2362b] font-semibold">{{ $preview['error'] }}</p>
                    @endif
                    @foreach($preview['notes'] as $note)
                    <p class="text-[#5d6e7f]">{{ $note }}</p>
                    @endforeach
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @if($confirmingCharge)
                    <span class="text-xs font-semibold text-[#173045]">Charge {{ $money($preview['charge'], true) }} to your {{ $order->card_brand?->label() ?? 'card' }}{{ $order->card_last_four ? ' ending '.$order->card_last_four : '' }} now?</span>
                    @endif
                    <button type="button" wire:click="savePlan" wire:loading.attr="disabled" wire:target="savePlan" @disabled($preview['error']) data-save-plan
                        class="inline-flex items-center gap-1.5 rounded-lg bg-[#12304f] px-4 py-1.5 text-xs font-bold text-white hover:bg-[#0c233b] transition-colors disabled:opacity-50">
                        <span wire:loading.remove wire:target="savePlan">{{ $confirmingCharge ? 'Confirm and pay' : 'Save plan changes' }}</span>
                        <span wire:loading.inline-flex wire:target="savePlan" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving…</span>
                    </button>
                    <button type="button" wire:click="resetPlan"
                        class="rounded-lg border border-[#dbe4ee] px-4 py-1.5 text-xs font-semibold text-[#5d6e7f] hover:bg-white transition-colors">Undo</button>
                </div>
                @endif
                @else
                <dl class="mt-4 divide-y divide-[#e6eef5] border-t border-[#e6eef5] text-sm">
                    <div class="flex justify-between gap-3 py-2.5">
                        <dt class="text-[#5d6e7f]">Billable providers</dt>
                        <dd class="font-semibold text-[#173045]">{{ $providerCount ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 py-2.5">
                        <dt class="text-[#5d6e7f]">Billing period</dt>
                        <dd class="font-semibold text-[#173045]">{{ $state === 'partner' ? 'Through your partner' : ($cycle === \App\Enums\BillingCycle::Monthly ? 'Monthly' : 'Annually') }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 py-2.5">
                        <dt class="text-[#5d6e7f]">{{ $state === 'trial' ? 'Free trial ends' : 'Next invoice' }}</dt>
                        <dd class="font-semibold text-[#173045] text-right">
                            @if($next)
                            {{ $money($next['amount']) }} on {{ $next['date']->format('M j, Y') }}
                            @elseif($state === 'partner')
                            Invoiced by your partner
                            @elseif($state === 'ending')
                            None, your plan ends {{ $order->cancels_at->format('M j, Y') }}
                            @elseif($state === 'cancelled')
                            None, cancelled {{ $order->cancelled_at?->format('M j, Y') }}
                            @else
                            —
                            @endif
                        </dd>
                    </div>
                </dl>
                @endif
                @if($state === 'past_due' && $order->last_renewal_error)
                <p class="mt-3 text-xs text-[#b7791f]">Your last renewal payment didn't go through: {{ $order->last_renewal_error }}. We'll retry automatically.</p>
                @endif
            </div>
            @endforeach
        </div>

        <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] p-4 sm:p-5 space-y-3 self-start">
            <h3 class="text-base font-bold text-[#12304f]">Payment method</h3>
            @if($billingState === 'partner')
            <p class="text-sm text-[#5d6e7f]">Your subscription is billed through your partner, so there's no card on file with Empower.</p>
            @else
            @if($billingOrder?->card_last_four)
            @php
            $brand = $billingOrder->card_brand;
            $hasExpiry = $billingOrder->card_expiry_month && $billingOrder->card_expiry_year;
            $cardExpired = $hasExpiry && now()->startOfMonth()->gt(\Illuminate\Support\Carbon::create($billingOrder->card_expiry_year, $billingOrder->card_expiry_month, 1));
            $cardSubline = collect([
                $hasExpiry ? ($cardExpired ? 'Expired ' : 'Expires ').str_pad((string) $billingOrder->card_expiry_month, 2, '0', STR_PAD_LEFT).'/'.substr((string) $billingOrder->card_expiry_year, -2) : null,
                $billingOrder->card_holder_name,
            ])->filter()->implode(' · ');
            @endphp
            <div class="flex items-center gap-3" data-payment-method>
                <span class="inline-flex h-8 w-12 shrink-0 items-center justify-center rounded-md bg-[#12304f] text-[10px] font-extrabold tracking-wide text-white">{{ $brand?->badge() ?? 'CARD' }}</span>
                <div class="min-w-0">
                    <p class="text-sm font-bold text-[#173045]">{{ $brand?->label() ?? 'Card' }} ending {{ $billingOrder->card_last_four }}</p>
                    @if($cardSubline)
                    <p class="text-xs {{ $cardExpired ? 'text-[#c2362b] font-semibold' : 'text-[#5d6e7f]' }}">{{ $cardSubline }}</p>
                    @endif
                </div>
            </div>
            @else
            <p class="text-sm text-[#5d6e7f]">No card on file.</p>
            @endif

            @if($account->activeOrders()->isNotEmpty())
            <div x-data="{ open: false }" x-on:card-saved.window="open = false; $refs.cardNumber.value = ''; $refs.cardExpiry.value = ''; $refs.cardCvc.value = ''">
                <button type="button" x-show="! open" x-on:click="open = true; $nextTick(() => $refs.cardName.focus())" data-update-card
                    class="text-sm font-semibold text-[#1a7aad] hover:underline">{{ $billingOrder?->card_last_four ? 'Update payment method' : 'Add a card' }}</button>

                <div x-show="open" x-cloak class="space-y-2.5" data-card-form>
                    <div>
                        <label for="pm-name" class="block text-xs font-semibold text-[#31465b] mb-1">Name on card</label>
                        <input id="pm-name" x-ref="cardName" type="text" autocomplete="cc-name" maxlength="255" value="{{ $billingOrder?->card_holder_name }}" class="{{ $inputClass }}">
                        @error('cardName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="pm-number" class="block text-xs font-semibold text-[#31465b] mb-1">Card number</label>
                        <input id="pm-number" x-ref="cardNumber" type="text" inputmode="numeric" autocomplete="cc-number" maxlength="19"
                            x-on:input="$el.value = $el.value.replace(/[^0-9]/g, '').slice(0, 16).replace(/(.{4})(?=.)/g, '$1 ')" class="{{ $inputClass }}">
                        @error('cardNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label for="pm-expiry" class="block text-xs font-semibold text-[#31465b] mb-1">Expiry</label>
                            <input id="pm-expiry" x-ref="cardExpiry" type="text" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/YY" maxlength="5"
                                x-on:input="
                                    let digits = $el.value.replace(/[^0-9]/g, '').slice(0, 4);
                                    let deleting = ($event.inputType || '').startsWith('delete');
                                    $el.value = (digits.length >= 2 && ! deleting) ? `${digits.slice(0, 2)}/${digits.slice(2)}` : digits;
                                " class="{{ $inputClass }}">
                            @error('cardExpiry') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="pm-cvc" class="block text-xs font-semibold text-[#31465b] mb-1">Security code</label>
                            <input id="pm-cvc" x-ref="cardCvc" type="text" inputmode="numeric" autocomplete="cc-csc" maxlength="4"
                                x-on:input="$el.value = $el.value.replace(/[^0-9]/g, '')" class="{{ $inputClass }}">
                            @error('cardCvc') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    @error('card') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    <p class="text-xs text-[#5d6e7f]">Used for every active subscription. Nothing is charged now.</p>
                    <div class="flex gap-2 pt-1">
                        <button type="button" wire:loading.attr="disabled" wire:target="updateCard"
                            x-on:click="$wire.updateCard($refs.cardName.value, $refs.cardNumber.value, $refs.cardExpiry.value, $refs.cardCvc.value).then(saved => { if (saved) $dispatch('card-saved') })"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-[#12304f] px-4 py-1.5 text-xs font-bold text-white hover:bg-[#0c233b] transition-colors">
                            <span wire:loading.remove wire:target="updateCard">Save card</span>
                            <span wire:loading.inline-flex wire:target="updateCard" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving…</span>
                        </button>
                        <button type="button" x-on:click="open = false; $wire.clearCardErrors()"
                            class="rounded-lg border border-[#dbe4ee] px-4 py-1.5 text-xs font-semibold text-[#5d6e7f] hover:bg-white transition-colors">Cancel</button>
                    </div>
                </div>
            </div>
            @endif
            @endif

            @if($billingState !== 'partner')
            <h3 class="text-base font-bold text-[#12304f] pt-2">Billing address</h3>
            @if($editingAddress)
            <form wire:submit="saveAddress" class="space-y-2.5" novalidate>
                <div>
                    <label for="ba-address1" class="block text-xs font-semibold text-[#31465b] mb-1">Street address</label>
                    <input id="ba-address1" wire:model="address1" type="text" autocomplete="address-line1" class="{{ $inputClass }}">
                    @error('address1') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-[minmax(0,1fr)_5rem] gap-2">
                    <div>
                        <label for="ba-city" class="block text-xs font-semibold text-[#31465b] mb-1">City</label>
                        <input id="ba-city" wire:model="city" type="text" autocomplete="address-level2" class="{{ $inputClass }}">
                        @error('city') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="ba-state" class="block text-xs font-semibold text-[#31465b] mb-1">State</label>
                        <input id="ba-state" wire:model="state" type="text" autocomplete="address-level1" class="{{ $inputClass }}">
                        @error('state') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label for="ba-zip" class="block text-xs font-semibold text-[#31465b] mb-1">ZIP code</label>
                    <input id="ba-zip" wire:model="zip" type="text" inputmode="numeric" autocomplete="postal-code" class="{{ $inputClass }} max-w-40">
                    @error('zip') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex gap-2 pt-1">
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveAddress"
                        class="rounded-lg bg-[#12304f] px-4 py-1.5 text-xs font-bold text-white hover:bg-[#0c233b] transition-colors">Save address</button>
                    <button type="button" wire:click="cancelAddress"
                        class="rounded-lg border border-[#dbe4ee] px-4 py-1.5 text-xs font-semibold text-[#5d6e7f] hover:bg-white transition-colors">Cancel</button>
                </div>
            </form>
            @else
            <p class="text-sm text-[#173045]" data-billing-address>
                @if(! empty($address['address1']))
                {{ $address['address1'] }}<br>{{ trim(($address['city'] ?? '').', '.($address['state'] ?? '').' '.($address['zip'] ?? ''), ', ') }}
                @else
                <span class="text-[#5d6e7f]">No billing address on file.</span>
                @endif
            </p>
            @if($orders->isNotEmpty() && $account->activeOrders()->isNotEmpty())
            <button type="button" wire:click="editAddress" class="text-sm font-semibold text-[#1a7aad] hover:underline">Edit billing address</button>
            @endif
            @endif
            @endif
        </div>
    </div>

    <h3 class="text-base font-bold text-[#12304f] mt-6 mb-3">Billing history</h3>
    <div class="overflow-x-auto rounded-xl border border-[#eef2f6]">
        <table class="w-full min-w-xl text-sm">
            <thead class="bg-[#f8fbfd] text-left text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1]">
                <tr>
                    <th class="px-4 py-2.5">Date</th>
                    <th class="px-4 py-2.5">Description</th>
                    <th class="px-4 py-2.5 text-right">Amount</th>
                    <th class="px-4 py-2.5">Status</th>
                    <th class="px-4 py-2.5 text-right">Receipt</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#eef2f6]">
                @forelse($this->history as $row)
                <tr data-billing-row="{{ $row['status'][0] }}">
                    <td class="px-4 py-3 whitespace-nowrap text-[#173045]">{{ $row['date']->format('M j, Y') }}</td>
                    <td class="px-4 py-3 text-[#173045]">{{ $row['description'] }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap text-[#173045]">{{ $money($row['amount'], true) }}</td>
                    <td class="px-4 py-3">@include('account._chip', ['label' => $row['status'][0], 'tone' => $row['status'][1]])</td>
                    <td class="px-4 py-3 text-right">
                        @if($row['receipt'])
                        <a href="{{ $row['receipt'] }}" target="_blank" rel="noopener" class="font-semibold text-[#1a7aad] hover:underline">View</a>
                        @else
                        <span class="text-[#8592a1]">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-4 text-sm italic text-[#5d6e7f]">No payments yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
