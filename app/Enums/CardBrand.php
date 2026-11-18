<?php

namespace App\Enums;

/** The card network, worked out from the card number's leading digits (its IIN). */
enum CardBrand: string
{
    case Visa = 'visa';
    case Mastercard = 'mastercard';
    case Amex = 'amex';
    case Discover = 'discover';

    /** Needs at least the first 6 digits; returns null for anything unrecognised. */
    public static function fromNumber(?string $number): ?self
    {
        $digits = preg_replace('/\D/', '', (string) $number);

        if (strlen($digits) < 6) {
            return null;
        }

        $two = (int) substr($digits, 0, 2);
        $four = (int) substr($digits, 0, 4);
        $six = (int) substr($digits, 0, 6);

        return match (true) {
            $digits[0] === '4' => self::Visa,
            ($two >= 51 && $two <= 55) || ($four >= 2221 && $four <= 2720) => self::Mastercard,
            $two === 34 || $two === 37 => self::Amex,
            $four === 6011 || $two === 65 || ($six >= 644000 && $six <= 649999) || ($six >= 622126 && $six <= 622925) => self::Discover,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Visa => 'Visa',
            self::Mastercard => 'Mastercard',
            self::Amex => 'American Express',
            self::Discover => 'Discover',
        };
    }

    /** Short text for the card badge. */
    public function badge(): string
    {
        return match ($this) {
            self::Visa => 'VISA',
            self::Mastercard => 'MC',
            self::Amex => 'AMEX',
            self::Discover => 'DISC',
        };
    }
}
