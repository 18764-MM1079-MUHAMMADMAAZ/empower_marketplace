<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\MoodleClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Copies a client's new email (and name) to their Empower LMS (Moodle) account, the same Moodle
 * user Marketplace linked by user ID, so no second account is ever created (spec §5, "Email
 * change"). Without this, Moodle only picked up the change at the client's next LMS sign-in.
 */
class UpdateLmsProfile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public User $user) {}

    /** Typically Moodle refusing an email another Moodle account already uses. */
    public function failed(Throwable $e): void
    {
        ActivityLog::record(
            'lms.sync_failed',
            "Couldn't update the Empower LMS email or name for {$this->user->email}: ".$e->getMessage(),
            user: $this->user,
            metadata: ['error' => $e->getMessage()],
        );
    }

    public function handle(MoodleClient $moodle): void
    {
        // The latest details, even if the email changed again while this job waited.
        $user = $this->user->fresh();

        if (! $user || $user->moodle_user_id === null || blank(config('services.moodle.token'))) {
            return;
        }

        $moodle->updateProfile($user->moodle_user_id, $user);

        ActivityLog::record(
            'lms.profile_updated',
            "Empower LMS email and name updated to {$user->email} ({$user->name}).",
            user: $user,
            metadata: ['moodle_user_id' => $user->moodle_user_id],
        );
    }
}
