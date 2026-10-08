<x-mail::message :message="$message ?? null">
# Your training access is ready

Hi {{ $user->name }},

Your Empower compliance package includes access to the **Empower LMS**, and your account is set up. You've been enrolled in **{{ $courseCount }} training {{ $courseCount === 1 ? 'course' : 'courses' }}**, including the courses specific to your practice's state.

@if($newAccount)
<x-mail::panel>
**Next step: set your password.** The LMS is sending you a separate email titled "New account" with a secure link to choose your password. Your username is your email address: **{{ $user->email }}**.
</x-mail::panel>
@else
<x-mail::panel>
You already had an LMS login, so just sign in with your existing details as **{{ $user->email }}**. The new courses are waiting in your dashboard.
</x-mail::panel>
@endif

<x-mail::button :url="$lmsUrl">
Open the Empower LMS
</x-mail::button>

If you don't see the password email within a few minutes, check your spam folder or reply to this message and we'll help.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
