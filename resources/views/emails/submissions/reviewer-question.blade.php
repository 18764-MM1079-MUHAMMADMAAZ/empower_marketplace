<x-mail::message :message="$message ?? null">
# A Question About Your Intake Submission

An Empower compliance specialist reviewing your submission for **{{ $submission->order?->package?->name ?? 'your compliance package' }}** has a question before they can finish.

<x-mail::panel>
{{ $question->question }}
</x-mail::panel>

Please log in to your portal and reply from the Review step so we can continue.

<x-mail::button :url="route('portal')">
Go to My Portal
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
