<?php

namespace App\Notifications;

use App\Models\IntakeSubmission;
use Illuminate\Notifications\Notification;

/** Database-only — surfaces a client's reply to a reviewer question in the admin notification
 *  bell. No matching email: the admin is already watching this submission by definition. */
class ReviewerQuestionReplyNotification extends Notification
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
            'title' => 'Client replied to your question',
            'message' => "{$practiceName} replied to your review question.",
            'url' => route('admin.submissions.show', $submission),
            'icon' => 'submission',
        ];
    }
}
