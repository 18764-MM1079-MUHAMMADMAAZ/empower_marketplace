<x-mail::message :message="$message ?? null">
# Call Requested

{{ $callRequest->user->name }} ({{ $callRequest->user->email }}) requested a 15-minute compliance call
from their Practice Intake wizard.

<x-mail::panel>
**When:** {{ $callRequest->requested_date->format('l, M j') }} at {{ $callRequest->requested_time }}
Eastern<br>
**Phone:** {{ $callRequest->phone }}<br>
**Topic:** {{ $callRequest->topic }}<br>
@if($callRequest->notes)
**Notes:**<br>
{{ $callRequest->notes }}
@endif
</x-mail::panel>

<x-mail::button :url="route('admin.users.edit', $callRequest->user)">
View Client in Admin Panel
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
