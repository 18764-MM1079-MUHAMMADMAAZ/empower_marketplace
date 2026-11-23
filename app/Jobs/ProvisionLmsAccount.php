<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Mail\ClientLmsAccessMail;
use App\Mail\WelcomeCredentialsMail;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\User;
use App\Notifications\ClientNotification;
use App\Services\MoodleClient;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/** Creates the client's Empower LMS (Moodle) account if needed and enrols them in the training
 *  courses for their package — safe to run more than once (enrolling twice is a no-op in Moodle).
 *  For a new sign-up it also sends their one welcome email (gap 2), so its payload is encrypted. */
class ProvisionLmsAccount implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * @param  array<int, int>  $orderIds  the orders that triggered this run (spec §5: provisioning
     *                                     runs once per order ID). Empty for the intake re-run and the admin button, which always run.
     * @param  string|null  $welcomePassword  a new sign-up's generated password: their welcome email
     *                                        waits for the LMS account and is sent from here, with the "Go to LMS" part (spec §4).
     */
    public function __construct(
        public User $user,
        public ?string $addressOrState = null,
        public array $orderIds = [],
        public ?string $welcomePassword = null,
    ) {}

    /** Called once every retry is used up: the failure goes to the Activity Log and the admins'
     *  bell, and a waiting welcome email still goes out, without the LMS part. */
    public function failed(Throwable $e): void
    {
        ActivityLog::record(
            'lms.failed',
            "Empower LMS provisioning failed for {$this->user->email}: ".$e->getMessage(),
            user: $this->user,
            metadata: ['error' => $e->getMessage()],
        );

        try {
            Notification::send(
                User::where('role', UserRole::Admin)->get(),
                new ClientNotification(
                    'LMS provisioning failed',
                    "Empower LMS access for {$this->user->email} could not be set up. Use \"Provision LMS access\" on their submission to try again.",
                    route('admin.activity-log'),
                ),
            );
        } catch (Throwable $notifyError) {
            report($notifyError);
        }

        $this->sendWelcome(null);
    }

    public function handle(MoodleClient $moodle): void
    {
        if (blank(config('services.moodle.token'))) {
            $this->sendWelcome(null);

            return;
        }

        // Once per order: a second dispatch for orders that are all already provisioned does
        // nothing. A retry after a failure still runs, repeating the lookup (spec §5).
        if ($this->orderIds !== [] && Order::whereKey($this->orderIds)->whereNull('lms_provisioned_at')->doesntExist()) {
            $this->sendWelcome(null);

            return;
        }

        // Saved Moodle id (cross-checked), then ID number, username, and email for accounts from
        // before single sign-on (spec §5). Whatever is found is linked and updated, never duplicated.
        $found = $moodle->findLinkedUser($this->user);

        if ($found['warning'] !== null) {
            ActivityLog::record('lms.link_warning', $found['warning'], user: $this->user, metadata: ['moodle_user_id' => $this->user->moodle_user_id]);
        }

        $created = $found['id'] === null;

        if ($created) {
            $moodleUserId = $moodle->createUser($this->user);
        } else {
            $moodleUserId = $found['id'];
            $moodle->linkToMarketplace($moodleUserId, $this->user);
        }

        $firstTime = $this->user->moodle_user_id === null;
        // The courses for the client's LMS package(s) plus their state's course (spec §9).
        $courseIds = $moodle->courseIdsFor($this->addressOrState, $this->user->lmsPackageTiers());

        $failed = $moodle->enrol($moodleUserId, $courseIds);

        // A client who lost access and bought again: turn their suspended account back on.
        if ($this->user->lms_suspended_at !== null) {
            $moodle->setSuspended($moodleUserId, false);
            ActivityLog::record('lms.reactivated', "Empower LMS access turned back on for {$this->user->email}.", user: $this->user, metadata: ['moodle_user_id' => $moodleUserId]);
        }

        $this->user->update(['moodle_user_id' => $moodleUserId, 'lms_suspended_at' => null]);

        ActivityLog::record(
            'lms.provisioned',
            "Empower LMS account for {$this->user->email} (Moodle user #{$moodleUserId}): ".(count($courseIds) - count($failed)).' of '.count($courseIds).' courses enrolled.',
            user: $this->user,
            metadata: ['moodle_user_id' => $moodleUserId, 'course_ids' => $courseIds, 'failed_course_ids' => array_keys($failed), 'order_ids' => $this->orderIds],
        );

        if ($failed !== []) {
            // Surfaces in the failed-jobs list and is retried; courses already enrolled are unaffected.
            throw new RuntimeException('Moodle enrolment failed for course(s) '.implode(', ', array_keys($failed)).': '.reset($failed));
        }

        // Record it on every live LMS order this account now covers, including the triggering ones.
        $this->user->orders()
            ->whereIn('payment_status', Order::PAID_STATUSES)
            ->where('status', '!=', OrderStatus::Cancelled)
            ->whereNull('cancelled_at')
            ->whereNull('lms_provisioned_at')
            ->with('package')
            ->get()
            ->filter(fn (Order $order) => $order->package?->includesLmsAccess())
            ->each(fn (Order $order) => $order->forceFill(['lms_provisioned_at' => now()])->saveQuietly());

        // Moodle itself only emails the password link; this tells the client what they now have.
        // Sent once, on the first successful provisioning, so admin re-runs don't repeat it. A new
        // sign-up gets it inside their one welcome email instead of a second email (gap 2).
        if ($firstTime) {
            $this->user->notify(new ClientNotification('Your training access is ready', "You're enrolled in ".count($courseIds).' training courses in the Empower LMS.', route('portal')));

            if ($this->welcomePassword === null) {
                try {
                    // An Essential client who bought an LMS package gets "LMS access is now on" (gap 4).
                    Mail::to($this->user->email)->send(new ClientLmsAccessMail($this->user, $created, count($courseIds), $this->user->hasLiveNonLmsPackage()));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        $this->sendWelcome(count($courseIds));
    }

    /** The new sign-up's welcome email: login details, plus the LMS part when $lmsCourseCount is
     *  set. Does nothing for existing clients, who have no password waiting. */
    private function sendWelcome(?int $lmsCourseCount): void
    {
        if ($this->welcomePassword === null) {
            return;
        }

        try {
            // Sent now, inside this (encrypted) job, rather than queued again with the password.
            Mail::to($this->user->email)->sendNow(new WelcomeCredentialsMail($this->user, $this->welcomePassword, $lmsCourseCount));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
