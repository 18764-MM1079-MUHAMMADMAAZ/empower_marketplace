<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function numbers(): array
    {
        return [
            'digits only' => ['7325550148', '(732) 555-0148'],
            'already formatted' => ['(732) 555-0148', '(732) 555-0148'],
            'dots' => ['732.555.0148', '(732) 555-0148'],
            'dashes' => ['732-555-0148', '(732) 555-0148'],
            'US country code' => ['+1 (732) 555-0148', '(732) 555-0148'],
            'leading 1' => ['17325550148', '(732) 555-0148'],
            'too short' => ['555-0100', null],
            'too long' => ['73255501489', null],
            '11 digits not starting with 1' => ['27325550148', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_format(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::format($input));
    }

    public function test_tidy_keeps_what_cannot_be_formatted(): void
    {
        $this->assertSame('(732) 555-0148', PhoneNumber::tidy(' 732 555 0148 '));
        $this->assertSame('555-0100', PhoneNumber::tidy(' 555-0100 '));
        $this->assertSame('', PhoneNumber::tidy(null));
    }

    public function test_the_pattern_only_accepts_the_us_format(): void
    {
        $this->assertSame(1, preg_match(PhoneNumber::PATTERN, '(732) 555-0148'));
        $this->assertSame(0, preg_match(PhoneNumber::PATTERN, '732-555-0148'));
        $this->assertSame(0, preg_match(PhoneNumber::PATTERN, '(732) 555-0148 ext 4'));
    }
}
