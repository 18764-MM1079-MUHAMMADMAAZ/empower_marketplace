@php
    $cycle = $order->billing_cycle ?? \App\Enums\BillingCycle::Annual;
    $billing = $order->billing_address ?? [];
    $source = match ($practice?->source_system) {
        'cch' => 'CareCloud',
        'talkehr' => 'talkEHR',
        default => 'Direct (Empower Marketplace)',
    };
    $partnerPracticeId = $order->external_practice_id ?: $practice?->external_practice_id;
    $unit = (float) ($order->package?->priceForCycle($cycle) ?? 0);
    $total = (float) ($order->original_price ?? $order->amount_paid ?? 0);
    // The practice's provider count when it still explains the stored price; otherwise worked back from
    // the price (the package's price or the practice's count may have changed since the order).
    $providers = (int) ($practice?->billable_providers_count ?? 0);
    $quantity = $providers > 0 && abs($unit * $providers - $total) < 0.01
        ? $providers
        : ($unit > 0 ? max(1, (int) round($total / $unit)) : 1);
    $perUnit = $unit > 0 ? $unit : $total;
    $discount = (float) ($order->discount_amount ?? 0);
    $dueToday = (float) ($order->amount_paid ?? 0);
    $isTrial = $order->payment_status === \App\Enums\PaymentStatus::Trialing;
    $period = $cycle === \App\Enums\BillingCycle::Monthly ? 'month' : 'year';
    $reference = 'EM-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
    $billingAddress = collect([$billing['address1'] ?? null, $billing['city'] ?? null, $billing['state'] ?? null, $billing['zip'] ?? null])->filter()->implode(', ');
    $locations = $practice?->oshaLocations()->count() ?? 0;
    $label = 'font-size: 10px; color: #4b5563;';
    $value = 'font-size: 10px; color: #4b5563;';
    $supportEmail = 'support@empowerhci.com';
@endphp
<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td><img src="{{ public_path('images/empower-carecloud-logo.png') }}" height="40"></td>
    </tr>
</table>

<table width="100%" cellpadding="12" cellspacing="0" style="margin-top: 14px;">
    <tr>
        <td colspan="2" style="background-color: #009ade;">
            <span style="color: #ffffff; font-size: 24px; font-weight: bold;">ORDER FORM</span>
        </td>
    </tr>
    <tr>
        <td width="52%" style="background-color: #009ade; vertical-align: top;">
            <p style="color: #ffffff; font-size: 11px; font-weight: bold; margin: 0;">{{ $order->user->name }}</p>
            <p style="color: #ffffff; font-size: 10px; margin: 4px 0 0;">{{ $order->user->email }}</p>
        </td>
        <td width="48%" style="background-color: #009ade; text-align: right; vertical-align: top;">
            <p style="color: #ffffff; font-size: 10px; margin: 0;">Reference: {{ $reference }}</p>
            <p style="color: #ffffff; font-size: 10px; margin: 4px 0 0;">Order created: {{ $order->created_at?->format('F j, Y') }}</p>
            <p style="color: #ffffff; font-size: 10px; margin: 4px 0 0;">Quote expires: N/A (order placed online)</p>
            <p style="color: #ffffff; font-size: 10px; margin: 4px 0 0;">Order created by: Empower Marketplace</p>
            <p style="color: #ffffff; font-size: 10px; margin: 4px 0 0;">{{ $supportEmail }}</p>
        </td>
    </tr>
</table>

<table width="100%" cellpadding="8" cellspacing="0" style="margin-top: 14px; border: 1px solid #1f2937;">
    <tr>
        <td>
            <p style="font-size: 11px; font-weight: bold; color: #1f2937; margin: 0 0 6px;">Your Practice At A Glance</p>
            <table width="100%" cellpadding="5" cellspacing="0" style="border: 1px solid #94a3b8;">
                <tr><td width="34%" style="{{ $label }}">Bill-to Account:</td><td style="{{ $value }}">{{ $practice?->name ?: $order->user->name }}</td></tr>
                <tr><td style="{{ $label }}">Practice Address:</td><td style="{{ $value }}">{{ $practice?->address ?: '—' }}</td></tr>
                <tr><td style="{{ $label }}">Practice Phone:</td><td style="{{ $value }}">{{ $practice?->phone ?: '—' }}</td></tr>
                <tr><td style="{{ $label }}">Specialty:</td><td style="{{ $value }}">{{ $practice?->specialty ?: '—' }}</td></tr>
                <tr><td style="{{ $label }}">Total Locations:</td><td style="{{ $value }}">{{ $locations }}</td></tr>
                <tr><td style="{{ $label }}">Non-Billable Users:</td><td style="{{ $value }}">—</td></tr>
                <tr><td style="{{ $label }}">Core Product:</td><td style="{{ $value }}">{{ $order->package?->name ?? 'Compliance Package' }}</td></tr>
                <tr><td style="{{ $label }}">Total Providers:</td><td style="{{ $value }}">{{ $practice?->billable_providers_count ?: $quantity }}</td></tr>
                <tr><td style="{{ $label }}">Source System:</td><td style="{{ $value }}">{{ $source }}</td></tr>
                <tr><td style="{{ $label }}">Subscription / Account ID:</td><td style="{{ $value }}">{{ $practice?->subscription_account_id ?: '—' }}</td></tr>
                <tr><td style="{{ $label }}">Practice ID:</td><td style="{{ $value }}">{{ $practice?->id }}{{ $partnerPracticeId ? ' / '.$partnerPracticeId : '' }}</td></tr>
                <tr><td style="{{ $label }}">Subscribed By:</td><td style="{{ $value }}">{{ $order->user->name }} ({{ $order->user->email }}){{ $order->user->is_practice_admin ? ', practice admin' : '' }}</td></tr>
                <tr>
                    <td style="{{ $label }}">Payment Term:</td>
                    <td style="{{ $value }}">
                        @if($practice?->preferred_billing_option)
                            Billed to the client's current form of payment: {{ $practice->preferred_billing_option }}@if($practice->subscription_account_id) (account {{ $practice->subscription_account_id }})@endif
                        @elseif($order->card_last_four)
                            Billed to the client's current form of payment: card ending {{ $order->card_last_four }}@if($order->card_expiry_month && $order->card_expiry_year) (exp. {{ str_pad((string) $order->card_expiry_month, 2, '0', STR_PAD_LEFT) }}/{{ $order->card_expiry_year }})@endif
                        @else
                            Not specified
                        @endif
                    </td>
                </tr>
                <tr><td style="{{ $label }}">Billing Address:</td><td style="{{ $value }}">{{ ($billing['name'] ?? null) ? $billing['name'].', ' : '' }}{{ $billingAddress ?: '—' }}</td></tr>
                <tr>
                    <td style="{{ $label }}">First Billing Cycle:</td>
                    <td style="{{ $value }}">{{ $isTrial && $order->trial_ends_at ? 'After the free trial ends on '.$order->trial_ends_at->format('F j, Y') : 'On the order date, '.($order->paid_at ?? $order->created_at)?->format('F j, Y') }}</td>
                </tr>
                <tr><td style="{{ $label }}">Initial Term:</td><td style="{{ $value }}">{{ $cycle === \App\Enums\BillingCycle::Monthly ? '1 Month (renews monthly)' : '12 Months' }}</td></tr>
                @if($order->next_bill_date)
                <tr><td style="{{ $label }}">Next Renewal:</td><td style="{{ $value }}">{{ $order->next_bill_date->format('F j, Y') }}</td></tr>
                @endif
                <tr><td style="{{ $label }}">Comments:</td><td style="{{ $value }}">{{ $order->notes ?: '' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<p style="margin-top: 16px; margin-bottom: 4px; font-size: 12px; font-weight: bold; color: #1f2937;">Products &amp; Services</p>

<table width="100%" cellpadding="6" cellspacing="0" border="1" style="border: 1px solid #94a3b8;">
    <tr style="background-color: #f1f5f9;">
        <td width="38%" style="font-size: 10px; font-weight: bold; color: #1f2937;">Item</td>
        <td width="12%" style="font-size: 10px; font-weight: bold; color: #1f2937; text-align: center;">Quantity</td>
        <td width="12%" style="font-size: 10px; font-weight: bold; color: #1f2937;">UOM</td>
        <td width="19%" style="font-size: 10px; font-weight: bold; color: #1f2937; text-align: right;">Price</td>
        <td width="19%" style="font-size: 10px; font-weight: bold; color: #1f2937; text-align: right;">Total</td>
    </tr>
    <tr style="border-bottom: 1px solid #009ade;">
        <td style="font-size: 10px; color: #4b5563;">{{ $order->package?->name ?? 'Compliance Package' }} ({{ $cycle->label() }})</td>
        <td style="font-size: 10px; color: #4b5563; text-align: center;">{{ $quantity }}</td>
        <td style="font-size: 10px; color: #4b5563;">provider</td>
        <td style="font-size: 10px; color: #4b5563; text-align: right;">${{ number_format($perUnit, 2) }} / {{ $period }}</td>
        <td style="font-size: 10px; color: #4b5563; text-align: right;">${{ number_format($total, 2) }} / {{ $period }}</td>
    </tr>
    @if($order->discount_code)
    <tr style="border-bottom: 1px solid #009ade;">
        <td colspan="4" style="font-size: 10px; color: #4b5563;">Discount ({{ $order->discount_code }})</td>
        <td style="font-size: 10px; color: #4b5563; text-align: right;">-${{ number_format($discount, 2) }}</td>
    </tr>
    @endif
    <tr style="border-bottom: 1px solid #009ade;">
        <td colspan="4" style="font-size: 10px; font-weight: bold; color: #1f2937;">{{ $isTrial ? 'Due today (free trial)' : 'Total charged' }}</td>
        <td style="font-size: 10px; font-weight: bold; color: #1f2937; text-align: right;">${{ number_format($dueToday, 2) }}</td>
    </tr>
</table>

<p style="font-size: 11px; font-weight: bold; color: #1f2937; margin-top: 16px;">Terms</p>
<p style="font-size: 9px; line-height: 1.5; color: #4b5563;">
    If applicable and quoted above, fees for Professional Services and/or Integration Services are due immediately and prior to CareCloud commencing any work. If applicable, Lab interface fees, if quoted at $0 on this Order Form are subject to approval by Client’s lab(s). If Client’s lab(s) do not approve Client’s interface(s), Client will have the option to cover the cost of the Lab Interface or proceed without a Lab Interface. Any products, work or services beyond the scope of any Overview are not included in this Order Form and will be quoted on a separate SOW or addendum.
</p>
<p style="font-size: 9px; line-height: 1.5; color: #4b5563;">
    By signing this Order Form, Client agrees to pay the fees as quoted above for the period of the Initial Term and, as applicable, any Renewal Term. This Order Form is governed by CareCloud Inc.'s Master Service Agreement ("MSA") and Business Associate Agreement ("BAA") found at [<a href="https://carecloud.app.box.com/v/ProactiveComplianceTerms">https://carecloud.app.box.com/v/ProactiveComplianceTerms</a>] and the Product Specific Terms ("PST") found at [<a href="https://carecloud.app.box.com/v/ProactiveComplianceTerms">https://carecloud.app.box.com/v/ProactiveComplianceTerms</a>]. The MSA, BAA, and PST are incorporated by reference and are made a part hereof. This Order Form may be superseded by a subsequent order form executed by Client and CareCloud.
</p>
@if($order->terms_accepted_at)
<p style="font-size: 9px; color: #4b5563;">
    Terms &amp; Conditions and the CareCloud Master Services Agreement accepted online on {{ $order->terms_accepted_at->format('F j, Y g:i A') }}@if($order->terms_accepted_ip) from IP {{ $order->terms_accepted_ip }}@endif.
</p>
@endif

<p style="font-size: 16px; font-weight: bold; color: #334155; margin-top: 18px;">Questions? Contact me</p>
<p style="font-size: 10px; color: #4b5563;">{{ $supportEmail }}</p>
