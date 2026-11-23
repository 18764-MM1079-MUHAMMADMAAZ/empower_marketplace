<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to an existing client whose LMS account was just set up. $upgrade: they already had
 *  Essential, so this is the "LMS access is now on" email (gap 4, spec §4). New sign-ups get the
 *  LMS part inside their welcome email instead. */
class ClientLmsAccessMail extends Mailable
{
    use SerializesModels;

    public function __construct(public User $user, public bool $newAccount, public int $courseCount, public bool $upgrade = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->upgrade
            ? 'Your Empower LMS access is now on'
            : 'Your Empower LMS training access is ready');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.client.lms-access',
            with: [
                'user' => $this->user,
                'newAccount' => $this->newAccount,
                'courseCount' => $this->courseCount,
                'upgrade' => $this->upgrade,
                // Goes through Marketplace sign-in, then single sign-on into Moodle.
                'lmsUrl' => route('lms.launch'),
            ],
        );
    }
}
