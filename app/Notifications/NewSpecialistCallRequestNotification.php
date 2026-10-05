<?php

namespace App\Notifications;

use App\Models\SpecialistCallRequest;
use Illuminate\Notifications\Notification;

/** Database-only — the matching email (NewSpecialistCallRequestMail) is sent separately at the
 *  same call site; this just also surfaces the event in the admin notification bell. */
class NewSpecialistCallRequestNotification extends Notification
{
    public function __construct(public SpecialistCallRequest $callRequest) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Call requested',
            'message' => "{$this->callRequest->user->name} requested a call for {$this->callRequest->requested_date->format('M j')} at {$this->callRequest->requested_time}.",
            'url' => route('admin.users.edit', $this->callRequest->user),
            'icon' => 'call',
        ];
    }
}
