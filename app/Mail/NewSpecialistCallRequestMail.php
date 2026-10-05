<?php

namespace App\Mail;

use App\Models\SpecialistCallRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewSpecialistCallRequestMail extends Mailable
{
    use SerializesModels;

    public function __construct(public SpecialistCallRequest $callRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Call requested: '.$this->callRequest->user->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.specialist-calls.notification',
            with: ['callRequest' => $this->callRequest],
        );
    }
}
