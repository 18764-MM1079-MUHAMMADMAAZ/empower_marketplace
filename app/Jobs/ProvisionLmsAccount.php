<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\MoodleClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/** Creates the client's Empower LMS (Moodle) account if needed and enrols them in the training
 *  courses for their package — safe to run more than once (enrolling twice is a no-op in Moodle). */
class ProvisionLmsAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public User $user, public ?string $addressOrState = null) {}

    public function handle(MoodleClient $moodle): void
    {
        if (blank(config('services.moodle.token'))) {
            return;
        }

        $moodleUserId = $moodle->findUserIdByEmail($this->user->email) ?? $moodle->createUser($this->user->name, $this->user->email);
        $courseIds = $moodle->courseIdsFor($this->addressOrState);

        $failed = $moodle->enrol($moodleUserId, $courseIds);

        $this->user->update(['moodle_user_id' => $moodleUserId]);

        ActivityLog::record(
            'lms.provisioned',
            "Empower LMS account for {$this->user->email} (Moodle user #{$moodleUserId}): ".(count($courseIds) - count($failed)).' of '.count($courseIds).' courses enrolled.',
            user: $this->user,
            metadata: ['moodle_user_id' => $moodleUserId, 'course_ids' => $courseIds, 'failed_course_ids' => array_keys($failed)],
        );

        if ($failed !== []) {
            // Surfaces in the failed-jobs list and is retried; courses already enrolled are unaffected.
            throw new RuntimeException('Moodle enrolment failed for course(s) '.implode(', ', array_keys($failed)).': '.reset($failed));
        }
    }
}
