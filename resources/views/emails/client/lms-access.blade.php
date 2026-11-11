<x-mail::message :message="$message ?? null">
# Your training access is ready

Hi {{ $user->name }},

Your Empower compliance package includes access to the **Empower LMS**, and your account is set up. You've been enrolled in **{{ $courseCount }} training {{ $courseCount === 1 ? 'course' : 'courses' }}**, including the courses specific to your practice's state.

<x-mail::panel>
**No separate password needed.** You sign in to the LMS with your Empower Marketplace login. Use the **Access LMS** button at the top of your Marketplace portal, or the button below.
</x-mail::panel>

<x-mail::button :url="$lmsUrl">
Access LMS
</x-mail::button>

If you have any trouble getting in, reply to this message and we'll help.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
