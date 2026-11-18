<x-mail::message :message="$message ?? null">
# Your Plan Has Been Updated

Your plan now covers **{{ $providers }} billable {{ Str::plural('provider', $providers) }}**. We've charged
the difference for the rest of your current billing period.

<x-mail::panel>
**Description:** {{ $payment->message }}<br>
**Amount Charged:** ${{ number_format((float) $payment->amount, 2) }}<br>
**Card:** {{ $order?->card_brand?->label() ?? 'Card' }} ending {{ $order?->card_last_four ?? '····' }}<br>
**Date:** {{ $payment->created_at->format('F j, Y') }}<br>
@if($order?->next_bill_date)
**Next Renewal:** {{ $order->next_bill_date->format('F j, Y') }}
@endif
</x-mail::panel>

<x-mail::button :url="route('payments.receipt', $payment)">
View Receipt
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
