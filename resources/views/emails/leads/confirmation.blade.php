<x-mail::message :message="$message ?? null">
# Thanks, {{ $lead->name }}!

We've received your message and a member of our team will be in touch shortly{{ $lead->package_interest ? ' about the **'.ucfirst($lead->package_interest).'** package' : '' }}.

Here's a copy of what you sent us:

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

If anything above isn't quite right, just reply to this email and let us know.

<x-mail::button :url="route('home')">
Visit {{ config('app.name') }}
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
