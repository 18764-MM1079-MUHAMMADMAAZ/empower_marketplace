<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
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
        $users = $this->call('core_user_get_users_by_field', ['field' => 'email', 'values' => [strtolower($email)]]);

        return $users[0]['id'] ?? null;
    }

    /** Creates the account; Moodle emails the learner a link to set their own password. */
    public function createUser(string $name, string $email): int
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        $created = $this->call('core_user_create_users', ['users' => [[
            // Moodle usernames allow only lowercase letters, digits and _ - . @
            'username' => preg_replace('/[^a-z0-9_.@-]/', '', strtolower($email)),
            'firstname' => $parts[0] !== '' ? $parts[0] : 'Learner',
            'lastname' => $parts[1] ?? '-',
            'email' => strtolower($email),
            'auth' => 'manual',
            'createpassword' => 1,
        ]]]);

        return $created[0]['id'];
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
