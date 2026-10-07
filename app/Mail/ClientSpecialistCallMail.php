<?php

namespace App\Mail;

use App\Models\SpecialistCallRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientSpecialistCallMail extends Mailable
{
    use SerializesModels;

    /** @param 'requested'|'scheduled'|'cancelled' $event */
    public function __construct(public SpecialistCallRequest $call, public string $event) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->event) {
            'scheduled' => 'Your specialist call is confirmed',
            'cancelled' => 'Your specialist call was cancelled',
            default => 'We received your call request',
        }.' — '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.client.specialist-call',
            with: ['call' => $this->call, 'event' => $this->event],
        );
    }
}
