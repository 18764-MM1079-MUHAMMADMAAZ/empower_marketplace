<?php

namespace App\Support;

/**
 * US phone numbers in the one format the intake uses everywhere: (XXX) XXX-XXXX.
 * resources/js/app.js has the same formatting for the inputs (window.formatUsPhone).
 */
final class PhoneNumber
{
    public const PATTERN = '/^\(\d{3}\) \d{3}-\d{4}$/';

    public const MESSAGE = 'Enter the phone number as (XXX) XXX-XXXX.';

    /**
     * "(732) 555-0148" from anything holding exactly 10 digits, or 11 starting with the US
     * country code 1 ("732.555.0148", "+1 732 555 0148", "7325550148"). Null when it can't be
     * read as a US number, so the caller can keep what was typed and let validation explain.
     */
    public static function format(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return null;
        }

        return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
    }

    /** The formatted number when possible, otherwise the value unchanged (trimmed). */
    public static function tidy(?string $value): string
    {
        return self::format($value) ?? trim((string) $value);
    }

    /** @return array<int, string> */
    public static function rules(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'regex:'.self::PATTERN];
    }
}
