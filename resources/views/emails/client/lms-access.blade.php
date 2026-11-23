<x-mail::message :message="$message ?? null">
@if($upgrade ?? false)
# Your LMS access is now on

Hi {{ $user->name }},

Thanks for upgrading. Your new package includes the **Empower LMS**, and your training account is ready. You've been enrolled in **{{ $courseCount }} training {{ $courseCount === 1 ? 'course' : 'courses' }}**, including the courses specific to your practice's state.

<x-mail::panel>
**Same login as before.** There's no new password: sign in to the LMS with the email and password you already use for the Empower Marketplace. Use the **Go to LMS** button at the top of your Marketplace portal, or the button below.
</x-mail::panel>
@else
# Your training access is ready

Hi {{ $user->name }},

Your Empower compliance package includes access to the **Empower LMS**, and your account is set up. You've been enrolled in **{{ $courseCount }} training {{ $courseCount === 1 ? 'course' : 'courses' }}**, including the courses specific to your practice's state.

<x-mail::panel>
**No separate password needed.** You sign in to the LMS with your Empower Marketplace login. Use the **Go to LMS** button at the top of your Marketplace portal, or the button below.
</x-mail::panel>
@endif

<x-mail::button :url="$lmsUrl">
Go to LMS
</x-mail::button>

If you have any trouble getting in, reply to this message and we'll help.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
