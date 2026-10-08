<?php

namespace App\Mail;

use App\Models\ReviewerQuestion;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientReviewerQuestionMail extends Mailable
{
    use SerializesModels;

    public function __construct(public ReviewerQuestion $question) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'A Question About Your Intake Submission',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.submissions.reviewer-question',
            with: ['question' => $this->question, 'submission' => $this->question->intakeSubmission],
        );
    }
}
