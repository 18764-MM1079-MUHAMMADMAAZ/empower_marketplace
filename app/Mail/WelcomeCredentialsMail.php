<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeCredentialsMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param  int|null  $lmsCourseCount  set when the LMS account is ready: the email then also has
     *  the "Go to LMS" part, so a new LMS client gets one welcome email (gap 2, spec §4). */
    public function __construct(public User $user, public string $password, public ?int $lmsCourseCount = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to '.config('app.name').' — Your Login Details',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.auth.welcome-credentials',
            with: [
                'user' => $this->user,
                'password' => $this->password,
                'loginUrl' => route('login'),
                'lmsCourseCount' => $this->lmsCourseCount,
                // Goes through Marketplace sign-in, then single sign-on into Moodle.
                'lmsUrl' => route('lms.launch'),
            ],
        );
    }
}
