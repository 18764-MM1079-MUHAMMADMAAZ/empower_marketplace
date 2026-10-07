<x-mail::message :message="$message ?? null">
# New Lead Received

A new contact/quote request just came in{{ $lead->package_interest ? ' for the '.ucfirst($lead->package_interest).' package' : '' }}.

<x-mail::panel>
**Name:** {{ $lead->name }}<br>
**Email:** {{ $lead->email }}<br>
@if($lead->practice_name)
**Practice:** {{ $lead->practice_name }}{{ $lead->billable_providers ? ' ('.$lead->billable_providers.' billable provider'.($lead->billable_providers === 1 ? '' : 's').')' : '' }}<br>
@endif
@if($lead->phone)
**Phone:** {{ $lead->phone }}<br>
@endif
@if($lead->package_interest)
**Package interest:** {{ ucfirst($lead->package_interest) }}<br>
@endif
@if($lead->message)
**Message:**<br>
{{ $lead->message }}
@endif
</x-mail::panel>

<x-mail::button :url="route('admin.leads')">
View in Admin Panel
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
