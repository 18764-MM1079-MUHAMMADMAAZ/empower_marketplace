<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientLmsAccessMail extends Mailable
{
    use SerializesModels;

    public function __construct(public User $user, public bool $newAccount, public int $courseCount) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Empower LMS training access is ready');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.client.lms-access',
            with: [
                'user' => $this->user,
                'newAccount' => $this->newAccount,
                'courseCount' => $this->courseCount,
                'lmsUrl' => rtrim(config('services.moodle.base_url'), '/'),
            ],
        );
    }
}
