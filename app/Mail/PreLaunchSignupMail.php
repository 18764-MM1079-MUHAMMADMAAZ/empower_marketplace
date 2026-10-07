<?php

namespace App\Mail;

use App\Models\Lead;
use App\Models\Setting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class PreLaunchSignupMail extends Mailable
{
    use SerializesModels;

    public function __construct(public Lead $lead, public ?string $packageName = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're on the list — ".config('app.name'),
        );
    }

    public function content(): Content
    {
        $launchAt = Setting::read('public_launch_at') ?? config('app.public_launch_at');

        return new Content(
            markdown: 'emails.leads.pre-launch-signup',
            with: [
                'lead' => $this->lead,
                'packageName' => $this->packageName,
                'launchDate' => filled($launchAt) ? Carbon::parse($launchAt) : null,
            ],
        );
    }
}
