<x-mail::message :message="$message ?? null">
# Welcome to {{ config('app.name') }}

Hi {{ $user->name }},

Your account has been created. Here are your login details:

<x-mail::panel>
**Email:** {{ $user->email }}<br>
**Password:**
<pre><code>{{ $password }}</code></pre>
</x-mail::panel>

@if($user->must_change_password)
This is a temporary password. The first time you log in, you'll be asked to choose your own.
@else
For security, we recommend changing this password after you log in (Account menu &rarr; My Account &rarr; Sign-in &amp; security).
@endif

<x-mail::button :url="$loginUrl">
Log In
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
