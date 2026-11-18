<x-mail::message :message="$message ?? null">
@if($ended)
# Your Subscription Has Ended

Your subscription for **{{ $order->package?->name ?? 'your compliance package' }}** ended on
{{ ($order->cancelled_at ?? now())->format('F j, Y') }}, as you requested. Your card has not been charged again.

Documents already generated remain available to download from your account, but new document
generation, the compliance hotline and exclusions screening have stopped.

Changed your mind? You're welcome to start a new subscription any time.
@else
# Cancellation Confirmed

You've cancelled **{{ $order->package?->name ?? 'your compliance package' }}**. Your plan stays active
until **{{ $order->cancels_at?->format('F j, Y') }}**, and you won't be charged again.

Until then everything keeps working as normal. If you change your mind before that date, choose
**Keep my plan** in My Account.
@endif

<x-mail::button :url="route('account').'#plan'">
Go to My Account
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
