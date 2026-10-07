<x-mail::message :message="$message ?? null">
# You're on the list!

Thanks for your interest in {{ $packageName ? '**'.$packageName.'**' : config('app.name') }}. Purchasing isn't open to the public just yet, but we'll email you the moment it is.

<x-mail::panel>
@if($packageName)
**Package:** {{ $packageName }}<br>
@endif
**Your email:** {{ $lead->email }}<br>
@if($launchDate)
**Expected opening:** {{ $launchDate->format('F j, Y') }}
@else
**Expected opening:** We'll let you know as soon as a date is confirmed.
@endif
</x-mail::panel>

In the meantime you can browse the packages and see what's included.

<x-mail::button :url="route('home')">
Visit {{ config('app.name') }}
</x-mail::button>

Questions? Just reply to this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
