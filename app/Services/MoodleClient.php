<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Thin client for the Empower LMS (Moodle) REST web services. */
class MoodleClient
{
    /** US state abbreviation => full name, for the states that have their own course. */
    private const STATE_NAMES = ['TX' => 'texas', 'NC' => 'north carolina', 'NY' => 'new york', 'IL' => 'illinois', 'CO' => 'colorado'];

    /**
     * @param  array<string, mixed>  $params
     * @return array<mixed>
     */
    public function call(string $function, array $params = []): array
    {
        $response = Http::asForm()->timeout(30)->post(rtrim(config('services.moodle.base_url'), '/').'/webservice/rest/server.php', [
            'wstoken' => config('services.moodle.token'),
            'wsfunction' => $function,
            'moodlewsrestformat' => 'json',
        ] + $params)->throw()->json() ?? [];

        if (isset($response['exception'])) {
            throw new RuntimeException("Moodle {$function} failed: ".($response['errorcode'] ?? 'error').' — '.($response['message'] ?? ''));
        }

        return $response;
    }

    /**
     * Finds the Moodle account that belongs to this Marketplace user, without ever guessing (spec §5,
     * "Preventing Duplicate Accounts"). The two records point to each other: Marketplace keeps the
     * Moodle user id (users.moodle_user_id) and Moodle keeps the Marketplace user id (ID number).
     *
     *  1. The saved Moodle user id (unique in Moodle), confirmed by its ID number.
     *  2. ID number = Marketplace user id (the spec's lookup). Moodle doesn't force it to be
     *     unique, so two matches stop provisioning instead of picking one.
     *  3. Username mp_{id}: accounts the OpenID Connect plugin created at sign-in (no ID number yet).
     *  4. Email: only for accounts made before single sign-on, and never one that already carries
     *     another Marketplace user's ID number.
     *
     * @return array{id: int|null, warning: string|null} id null = no account yet (create one)
     */
    public function findLinkedUser(User $user): array
    {
        $ownId = (string) $user->id;
        $warning = null;

        if ($user->moodle_user_id !== null) {
            $saved = $this->usersBy('id', (string) $user->moodle_user_id)[0] ?? null;

            // No ID number in the reply means the web-service user can't see it: rely on step 2.
            if ($saved && in_array((string) ($saved['idnumber'] ?? $ownId), ['', $ownId], true)) {
                return ['id' => (int) $saved['id'], 'warning' => null];
            }

            $warning = $saved
                ? "The saved Moodle user #{$user->moodle_user_id} has ID number {$saved['idnumber']}, not {$ownId}, so it was not used."
                : "The saved Moodle user #{$user->moodle_user_id} no longer exists in Moodle.";
        }

        $matches = $this->usersBy('idnumber', $ownId);

        if (count($matches) > 1) {
            throw new RuntimeException(count($matches)." Moodle users have ID number {$ownId} (Moodle users #".implode(', #', array_column($matches, 'id')).'). Keep one, clear the ID number on the others in Moodle, then re-run provisioning.');
        }

        if ($matches !== []) {
            return ['id' => (int) $matches[0]['id'], 'warning' => $warning];
        }

        foreach (['username' => $user->lmsUsername(), 'email' => strtolower($user->email)] as $field => $value) {
            $found = $this->usersBy($field, $value)[0] ?? null;

            if (! $found) {
                continue;
            }

            $claimedBy = (string) ($found['idnumber'] ?? '');

            if ($claimedBy !== '' && $claimedBy !== $ownId) {
                throw new RuntimeException("Moodle user #{$found['id']} ({$field} {$value}) belongs to Marketplace user {$claimedBy}, not {$ownId}. Check both accounts in Moodle before re-running provisioning.");
            }

            return ['id' => (int) $found['id'], 'warning' => $warning];
        }

        return ['id' => null, 'warning' => $warning];
    }

    /**
     * Creates the learner's account to sign in through Marketplace (OpenID Connect), so it has no
     * password of its own: the random one only satisfies Moodle's API and is never used or sent.
     * Username and idnumber come from the Marketplace user id (spec §4), never the email.
     */
    public function createUser(User $user): int
    {
        $created = $this->call('core_user_create_users', ['users' => [[
            'username' => $user->lmsUsername(),
            'auth' => config('services.moodle.auth'),
            'password' => Str::password(32),
            'idnumber' => (string) $user->id,
            ...$this->profileFields($user),
        ]]]);

        return $created[0]['id'];
    }

    /** Copies a Marketplace email or name change onto the same Moodle user (spec §5: never a new one). */
    public function updateProfile(int $moodleUserId, User $user): void
    {
        $this->call('core_user_update_users', ['users' => [['id' => $moodleUserId, ...$this->profileFields($user)]]]);
    }

    /** @return array{firstname: string, lastname: string, email: string} */
    private function profileFields(User $user): array
    {
        $parts = preg_split('/\s+/', trim($user->name), 2);

        return [
            'firstname' => $parts[0] !== '' ? $parts[0] : 'Learner',
            'lastname' => $parts[1] ?? '-',
            'email' => strtolower($user->email),
        ];
    }

    /** Ties a found account to the Marketplace user (username mp_{id}, OpenID Connect login, ID
     *  number), including accounts made before single sign-on, so the plugin signs the user into
     *  it instead of creating a second one. */
    public function linkToMarketplace(int $moodleUserId, User $user): void
    {
        // Spec §5: "If found, it updates that user": the link fields plus the latest email and name.
        $this->call('core_user_update_users', ['users' => [[
            'id' => $moodleUserId,
            'username' => $user->lmsUsername(),
            'auth' => config('services.moodle.auth'),
            'idnumber' => (string) $user->id,
            ...$this->profileFields($user),
        ]]]);
    }

    /** Suspended users can't sign in, but Moodle keeps their enrolments, grades and certificates. */
    public function setSuspended(int $moodleUserId, bool $suspended): void
    {
        $this->call('core_user_update_users', ['users' => [[
            'id' => $moodleUserId,
            'suspended' => $suspended ? 1 : 0,
        ]]]);
    }

    /** @return array<int, array<string, mixed>> */
    private function usersBy(string $field, string $value): array
    {
        return array_values(array_filter(
            $this->call('core_user_get_users_by_field', ['field' => $field, 'values' => [$value]]),
            fn ($user) => is_array($user) && isset($user['id']),
        ));
    }

    /**
     * Enrols one course at a time so a single misconfigured course (e.g. manual enrolment switched
     * off) doesn't block the rest; enrolling twice is a harmless no-op in Moodle.
     *
     * @param  array<int, int>  $courseIds
     * @return array<int, string> course id => error message, for the courses that failed
     */
    public function enrol(int $userId, array $courseIds): array
    {
        $failed = [];

        foreach ($courseIds as $courseId) {
            try {
                $this->call('enrol_manual_enrol_users', ['enrolments' => [[
                    'roleid' => (int) config('services.moodle.student_role_id'),
                    'userid' => $userId,
                    'courseid' => $courseId,
                ]]]);
            } catch (RuntimeException $e) {
                $failed[$courseId] = $e->getMessage();
            }
        }

        return $failed;
    }

    /**
     * The shared training courses plus the state-specific course for the practice's state.
     *
     * @return array<int, int>
     */
    public function courseIdsFor(?string $addressOrState): array
    {
        $courseIds = config('services.moodle.general_course_ids');
        $stateCourseId = config('services.moodle.state_course_ids')[$this->stateOf($addressOrState)] ?? null;

        return $stateCourseId ? [...$courseIds, $stateCourseId] : $courseIds;
    }

    /** Accepts a bare state ("TX", "Texas") or a full address line ("1 Main St, Austin, TX 78701"). */
    public function stateOf(?string $addressOrState): ?string
    {
        $text = strtolower(trim((string) $addressOrState));

        if ($text === '') {
            return null;
        }

        if (preg_match('/\b([a-z]{2})\b[\s,]*\d{5}/', $text, $match) || preg_match('/^([a-z]{2})$/', $text, $match)) {
            $code = strtoupper($match[1]);

            if (array_key_exists($code, self::STATE_NAMES)) {
                return $code;
            }
        }

        foreach (self::STATE_NAMES as $code => $name) {
            if (str_contains($text, $name)) {
                return $code;
            }
        }

        return null;
    }
}
