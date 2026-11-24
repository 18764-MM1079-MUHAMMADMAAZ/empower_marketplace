<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * My Account › Activity Log (v5 design): the client's own activity, grouped into the five types of
 * the design's "Activity type" dropdown. Events that fit none of them (LMS sync, finance, admin
 * edits, test tools) are admin-only and never listed here. Entries are never edited or deleted.
 */
class ClientActivity
{
    /** Times are shown in the application's time zone (config app.timezone: US Eastern). */
    public static function timezone(): string
    {
        return config('app.timezone');
    }

    /** The zone's current abbreviation for labels, e.g. "EST" (or "EDT" during daylight saving). */
    public static function timezoneLabel(): string
    {
        return now(self::timezone())->format('T');
    }

    public const TYPES = [
        'signin' => 'Sign-in',
        'security' => 'Security',
        'documents' => 'Documents',
        'review' => 'Compliance review',
        'account' => 'Account',
    ];

    /** event_type => type. Anything not listed here stays admin-only. */
    public const EVENTS = [
        'auth.login' => 'signin',
        'sso.empower_sso_api_login' => 'signin',
        'sso.login_completed' => 'signin',

        'auth.login_failed' => 'security',
        'auth.two_factor_recovery_code_used' => 'security',
        'account.password_changed' => 'security',
        'account.two_factor_enabled' => 'security',
        'account.two_factor_disabled' => 'security',
        'account.two_factor_recovery_codes_regenerated' => 'security',
        'account.device_signed_out' => 'security',
        'account.devices_signed_out' => 'security',

        'document.downloaded' => 'documents',
        'intake_answers.downloaded' => 'documents',
        'document.approved' => 'documents',
        'documents.approved' => 'documents',
        'document.regenerate_requested' => 'documents',
        'upload.additional_document_submitted' => 'documents',

        'submission.submitted' => 'review',
        'submission.under_review' => 'review',
        'submission.reviewer_question_asked' => 'review',
        'submission.reviewer_question_replied' => 'review',
        'submission.approved' => 'review',
        'submission.rejected' => 'review',
        'submission.sent_back_for_resubmission' => 'review',
        'submission.reopened' => 'review',

        'order.paid' => 'account',
        'order.externally_billed' => 'account',
        'order.renewed' => 'account',
        'trial.started' => 'account',
        'trial.converted' => 'account',
        'trial.cancelled' => 'account',
        'subscription.cancellation_scheduled' => 'account',
        'subscription.cancellation_withdrawn' => 'account',
        'plan.change_requested' => 'account',
        'plan.billing_cycle_updated_by_admin' => 'account',
        'plan.providers_updated_by_admin' => 'account',
        'plan.providers_changed' => 'account',
        'plan.providers_scheduled' => 'account',
        'plan.billing_cycle_changed' => 'account',
        'plan.billing_cycle_scheduled' => 'account',
        'billing.address_updated' => 'account',
        'order.card_updated' => 'account',
        'practice.updated' => 'account',
        'practice.hotline_updated' => 'account',
        'account.user_type_changed' => 'account',
    ];

    /** Recorded on the client's account, but done by the Empower team in the admin panel. */
    public const STAFF_EVENTS = ['account.user_type_changed', 'plan.billing_cycle_updated_by_admin', 'plan.providers_updated_by_admin'];

    /** The design's date range choices, in days (null = all time). */
    public const RANGES = ['7' => 7, '30' => 30, '90' => 90, 'all' => null];

    public const PER_PAGE = 10;

    public function __construct(private User $user) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    /** The user's entries plus anything recorded on their orders (e.g. by the compliance team). */
    public function query(string $type = 'all', string $range = '7', string $search = ''): Builder
    {
        $events = $type === 'all'
            ? array_keys(self::EVENTS)
            : array_keys(array_filter(self::EVENTS, fn (string $t) => $t === $type));

        $orderIds = $this->user->orders()->pluck('id');

        return ActivityLog::query()
            ->with('user')
            ->whereIn('event_type', $events)
            ->where(fn (Builder $q) => $q->where('user_id', $this->user->id)->orWhereIn('order_id', $orderIds))
            ->when(self::RANGES[$range] ?? null, fn (Builder $q, int $days) => $q->where('created_at', '>=', now()->subDays($days)))
            ->when(trim($search) !== '', function (Builder $q) use ($search) {
                $term = '%'.trim($search).'%';
                $q->where(fn (Builder $w) => $w->where('description', 'like', $term)
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $term)));
            })
            ->latest('id');
    }

    /**
     * One row of the table, as the design shows it.
     *
     * @return array{when: Carbon, title: string, actor: string, where: string, detail: string, type: string, type_label: string, failed: bool}
     */
    public function present(ActivityLog $log): array
    {
        $type = self::EVENTS[$log->event_type] ?? 'account';
        // Recorded on the client but done by an admin (e.g. after a "Request a change").
        $staffOnClient = in_array($log->event_type, self::STAFF_EVENTS, true);
        $byClient = $log->user_id === $this->user->id && ! $staffOnClient;
        $byStaff = $staffOnClient || (! $byClient && $log->user?->isAdmin());

        [$where, $detail] = match (true) {
            $staffOnClient => ['Empower', 'Admin panel'],
            $byStaff => ['Empower', 'Compliance team'],
            // Queued jobs and scheduled tasks have no browser (their IP is the server's own).
            blank($log->user_agent) => ['Empower', 'Automatic'],
            default => [self::device($log->user_agent), $log->ip_address ? 'IP '.$log->ip_address : ''],
        };

        return [
            'when' => $log->created_at->copy()->timezone(self::timezone()),
            'title' => $log->description,
            'actor' => match (true) {
                $byClient => $this->user->name,
                $staffOnClient => 'Empower team',
                $byStaff => 'Empower compliance team',
                $log->user !== null => $log->user->name,
                default => 'System',
            },
            'where' => $where,
            'detail' => $detail,
            'type' => $type,
            'type_label' => self::TYPES[$type],
            'failed' => $log->event_type === 'auth.login_failed',
        ];
    }

    /** "Chrome on Windows" from a user agent. */
    public static function device(?string $userAgent): string
    {
        if (blank($userAgent)) {
            return 'Unknown device';
        }

        $d = UserSessions::describe($userAgent);

        return "{$d['browser']} on {$d['platform']}";
    }
}
