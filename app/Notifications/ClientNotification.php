<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Database-only — surfaces a client-facing event (reviewer question, approval, call confirmed…)
 *  in the bell on the client's own pages. The matching email, where there is one, is still sent
 *  separately at the same call site. */
class ClientNotification extends Notification
{
    public function __construct(public string $title, public string $message, public ?string $url = null) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url ?? route('portal'),
            'icon' => 'client',
        ];
    }
}
