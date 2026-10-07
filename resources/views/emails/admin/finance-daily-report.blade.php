<x-mail::message>
# Daily Subscription Report — {{ $reportDate->format('M j, Y') }}

{{ $rowCount }} new subscription{{ $rowCount === 1 ? '' : 's' }} and cancellation{{ $rowCount === 1 ? '' : 's' }} from {{ $reportDate->format('l, F j') }} {{ $rowCount === 1 ? 'is' : 'are' }} attached as a CSV.

Apply each new subscription to the practice's current CareCloud billing, and mark it processed in the admin Orders list once done.

<x-mail::button :url="route('admin.orders')">
View Orders
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
