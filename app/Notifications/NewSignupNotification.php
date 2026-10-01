<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

/** Database-only — the matching email (AdminNewSignupMail) is still sent separately at the same
 *  call site; this just also surfaces the event in the admin notification bell. */
class NewSignupNotification extends Notification
{
    public function __construct(public User $user) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'New account created',
            'message' => "{$this->user->name} ({$this->user->email}) just created an account.",
            'url' => route('admin.users.edit', $this->user),
            'icon' => 'signup',
        ];
    }
}
