<?php

namespace App\Notifications;

use App\Models\IntakeSubmission;
use Illuminate\Notifications\Notification;

/** Database-only — the matching email (AdminIntakeSubmittedMail) is still sent separately at the
 *  same call site; this just also surfaces the event in the admin notification bell. */
class IntakeSubmittedNotification extends Notification
{
    public function __construct(public IntakeSubmission $submission) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $submission = $this->submission;
        $practiceName = $submission->order?->user?->practice?->name ?: ($submission->order?->user?->name ?? 'A practice');

        return [
            'title' => 'Intake submitted for review',
            'message' => "{$practiceName} submitted their intake for review.",
            'url' => route('admin.submissions.show', $submission),
            'icon' => 'submission',
        ];
    }
}
