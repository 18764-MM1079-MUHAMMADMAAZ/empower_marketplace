<?php

namespace App\Jobs;

use App\Mail\ClientLmsAccessMail;
use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\ClientNotification;
use App\Services\MoodleClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

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

    /** Called once every retry is used up, so the failure is visible in the admin Activity Log. */
    public function failed(Throwable $e): void
    {
        ActivityLog::record(
            'lms.failed',
            "Empower LMS provisioning failed for {$this->user->email}: ".$e->getMessage(),
            user: $this->user,
            metadata: ['error' => $e->getMessage()],
        );
    }

    public function handle(MoodleClient $moodle): void
    {
        if (blank(config('services.moodle.token'))) {
            return;
        }

        $existingMoodleId = $moodle->findUserIdByEmail($this->user->email);
        $moodleUserId = $existingMoodleId ?? $moodle->createUser($this->user->name, $this->user->email);
        $firstTime = $this->user->moodle_user_id === null;
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

        // Moodle itself only emails the password link; this tells the client what they now have.
        // Sent once, on the first successful provisioning, so admin re-runs don't repeat it.
        if ($firstTime) {
            $this->user->notify(new ClientNotification('Your training access is ready', "You're enrolled in ".count($courseIds).' training courses in the Empower LMS.', route('portal')));

            try {
                Mail::to($this->user->email)->send(new ClientLmsAccessMail($this->user, $existingMoodleId === null, count($courseIds)));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
