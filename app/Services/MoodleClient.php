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

    public function findUserIdByEmail(string $email): ?int
    {
        return $this->findUserIdBy('email', strtolower($email));
    }

    public function findUserIdByUsername(string $username): ?int
    {
        return $this->findUserIdBy('username', $username);
    }

    /**
     * Creates the learner's account to sign in through Marketplace (OpenID Connect), so it has no
     * password of its own: the random one only satisfies Moodle's API and is never used or sent.
     * Username and idnumber come from the Marketplace user id (spec §4), never the email.
     */
    public function createUser(User $user): int
    {
        $parts = preg_split('/\s+/', trim($user->name), 2);

        $created = $this->call('core_user_create_users', ['users' => [[
            'username' => $user->lmsUsername(),
            'auth' => config('services.moodle.auth'),
            'password' => Str::password(32),
            'idnumber' => (string) $user->id,
            'firstname' => $parts[0] !== '' ? $parts[0] : 'Learner',
            'lastname' => $parts[1] ?? '-',
            'email' => strtolower($user->email),
        ]]]);

        return $created[0]['id'];
    }

    /** Converts an account made before single sign-on (username = email, manual password login)
     *  so the OpenID Connect plugin signs the Marketplace user into it instead of creating a second one. */
    public function linkToMarketplace(int $moodleUserId, User $user): void
    {
        $this->call('core_user_update_users', ['users' => [[
            'id' => $moodleUserId,
            'username' => $user->lmsUsername(),
            'auth' => config('services.moodle.auth'),
            'idnumber' => (string) $user->id,
        ]]]);
    }

    private function findUserIdBy(string $field, string $value): ?int
    {
        $users = $this->call('core_user_get_users_by_field', ['field' => $field, 'values' => [$value]]);

        return $users[0]['id'] ?? null;
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
