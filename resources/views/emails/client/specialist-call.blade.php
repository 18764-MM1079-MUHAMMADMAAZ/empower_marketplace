<x-mail::message :message="$message ?? null">
# Hi {{ $call->user?->name ?? 'there' }},

@if($event === 'scheduled')
Your call with an Empower compliance specialist is **confirmed**. We'll phone you at the time below.
@elseif($event === 'cancelled')
Your call with an Empower compliance specialist has been **cancelled**. If you'd still like to talk, you can book a new time from your portal at any point.
@else
Thanks for requesting a call with an Empower compliance specialist. We'll confirm the time with you shortly.
@endif

<x-mail::panel>
**Date:** {{ $call->requested_date?->format('l, F j, Y') }}<br>
**Time:** {{ $call->requested_time }} Eastern<br>
**Phone:** {{ $call->phone }}<br>
**Topic:** {{ $call->topic }}
</x-mail::panel>

If anything above isn't right, just reply to this email.

<x-mail::button :url="route('portal')">
Open your portal
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
