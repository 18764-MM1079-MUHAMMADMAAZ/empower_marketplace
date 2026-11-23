<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

/** Database-only — the admin bell for a My Account "Request a change"; the email
 *  (PlanChangeRequestMail) is sent separately at the same call site. */
class PlanChangeRequestedNotification extends Notification
{
    public function __construct(public User $client, public string $summary) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Plan change requested',
            'message' => "{$this->client->name}: {$this->summary}",
            'url' => route('admin.users.edit', $this->client),
            'icon' => 'payment',
        ];
    }
}
