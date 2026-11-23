<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To admins: a client asked to change their plan from My Account › Plan › Request a change. */
class PlanChangeRequestMail extends Mailable
{
    use SerializesModels;

    /** @param  array{current_providers: int, providers: int, current_cycle: string, cycle: string, note: ?string}  $change */
    public function __construct(public User $client, public Order $order, public array $change) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Plan change requested: '.$this->client->name);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.admin.plan-change-request', with: [
            'client' => $this->client,
            'order' => $this->order,
            'change' => $this->change,
        ]);
    }
}
