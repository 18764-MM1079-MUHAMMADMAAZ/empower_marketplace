<?php

namespace App\Mail;

use App\Models\PaymentLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Receipt for a prorated charge made when a client adds providers from My Account. */
class ClientPlanChangeReceiptMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public PaymentLog $payment, public int $providers) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Plan Updated — Payment Received');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.client.plan-change-receipt',
            with: ['payment' => $this->payment, 'order' => $this->payment->order, 'providers' => $this->providers],
        );
    }
}
