<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\MoodleClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Keeps the client's Empower LMS (Moodle) account in step with their Marketplace access: suspends
 * it once they no longer have LMS access (cancelled, trial lapsed, term ended, renewal failed,
 * account deactivated) and reactivates it when they have access again. Suspending keeps every
 * enrolment, grade and certificate in Moodle (spec §4: "LMS access deactivated; training records
 * kept"). Only touches accounts Marketplace already provisioned; it never creates one.
 *
 * Safe to run any number of times: it reads the current access, not the event that queued it.
 */
class SyncLmsAccess implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public User $user) {}

    public function failed(Throwable $e): void
    {
        ActivityLog::record(
            'lms.sync_failed',
            "Couldn't update Empower LMS access for {$this->user->email}: ".$e->getMessage(),
            user: $this->user,
            metadata: ['error' => $e->getMessage()],
        );
    }

    public function handle(MoodleClient $moodle): void
    {
        $user = $this->user->fresh();

        if (! $user || $user->moodle_user_id === null || blank(config('services.moodle.token'))) {
            return;
        }

        $suspend = ! $user->hasLmsAccess();

        // Already in that state: nothing to tell Moodle.
        if ($suspend === ($user->lms_suspended_at !== null)) {
            return;
        }

        // Only ever the account confirmed as theirs (saved id checked against its ID number).
        $moodleUserId = $moodle->findLinkedUser($user)['id'];

        if ($moodleUserId === null) {
            return;
        }

        $moodle->setSuspended($moodleUserId, $suspend);
        $user->update(['lms_suspended_at' => $suspend ? now() : null, 'moodle_user_id' => $moodleUserId]);

        ActivityLog::record(
            $suspend ? 'lms.suspended' : 'lms.reactivated',
            $suspend
                ? "Empower LMS access turned off for {$user->email} (no active LMS package). Training records are kept."
                : "Empower LMS access turned back on for {$user->email}.",
            user: $user,
            metadata: ['moodle_user_id' => $user->moodle_user_id],
        );
    }
}
