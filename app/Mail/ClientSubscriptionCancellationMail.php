<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent when a client cancels from My Account (the plan runs to the end of the term), and again
 *  when that term ends and the subscription actually stops. */
class ClientSubscriptionCancellationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public bool $ended = false) {}

    public function envelope(): Envelope
    {
        $package = $this->order->package?->name ?? 'Compliance Package';

        return new Envelope(
            subject: ($this->ended ? 'Your Subscription Has Ended — ' : 'Cancellation Confirmed — ').$package,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.client.subscription-cancellation',
            with: ['order' => $this->order, 'ended' => $this->ended],
        );
    }
}
