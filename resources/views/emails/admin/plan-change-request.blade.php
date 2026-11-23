<x-mail::message :message="$message ?? null">
# Plan change requested

{{ $client->name }} ({{ $client->email }}) asked to change their plan from My Account.

<x-mail::panel>
**Package:** {{ $order->package?->name ?? '—' }} (order #{{ $order->id }})<br>
**Billable providers:** {{ $change['current_providers'] }}@if($change['providers'] !== $change['current_providers']) → **{{ $change['providers'] }}**@else (no change)@endif<br>
**Billing period:** {{ ucfirst($change['current_cycle']) }}@if($change['cycle'] !== $change['current_cycle']) → **{{ ucfirst($change['cycle']) }}**@else (no change)@endif<br>
@if($change['note'])
**Note:**<br>
{{ $change['note'] }}
@endif
</x-mail::panel>

To make the change: the provider count is on the client's user page (Practice › Billable providers), and the billing period is on the order. The next renewal charges the new amount; nothing is charged now.

<x-mail::button :url="route('admin.users.edit', $client)">
View Client in Admin Panel
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
