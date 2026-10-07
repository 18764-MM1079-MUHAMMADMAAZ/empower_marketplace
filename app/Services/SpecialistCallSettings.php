<?php

namespace App\Services;

use App\Models\Setting;

/** Admin-editable options for the client "Talk to a specialist" booking dialog, stored in the
 *  settings table with these defaults used until an admin saves something. */
class SpecialistCallSettings
{
    public const DEFAULT_TIME_SLOTS = ['9:00 AM', '10:30 AM', '12:00 PM', '1:30 PM', '3:00 PM', '4:30 PM'];

    public const DEFAULT_TOPICS = [
        'A question in the intake',
        'Choosing the right package',
        'Pricing or billing',
        'Something else',
    ];

    public const DEFAULT_DAYS_AHEAD = 5;

    public static function enabled(): bool
    {
        return Setting::read('calls_enabled') !== '0';
    }

    /** @return array<int, string> */
    public static function timeSlots(): array
    {
        return self::list('call_time_slots', self::DEFAULT_TIME_SLOTS);
    }

    /** @return array<int, string> */
    public static function topics(): array
    {
        return self::list('call_topics', self::DEFAULT_TOPICS);
    }

    /** How many bookable weekdays, starting tomorrow, the dialog offers. */
    public static function daysAhead(): int
    {
        return max(1, (int) (Setting::read('call_days_ahead') ?? self::DEFAULT_DAYS_AHEAD));
    }

    /**
     * @param  array<int, string>  $default
     * @return array<int, string>
     */
    private static function list(string $key, array $default): array
    {
        $decoded = json_decode(Setting::read($key) ?? '', true);

        return is_array($decoded) && $decoded !== [] ? array_values($decoded) : $default;
    }
}
