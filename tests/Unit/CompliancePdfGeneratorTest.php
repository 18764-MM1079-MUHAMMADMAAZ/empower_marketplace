<?php

namespace Tests\Unit;

use App\Services\CompliancePdfGenerator;
use PHPUnit\Framework\TestCase;

class CompliancePdfGeneratorTest extends TestCase
{
    private function sanitize(string $text): string
    {
        $generator = new CompliancePdfGenerator;
        $method = new \ReflectionMethod($generator, 'sanitizeForCoreFont');
        $method->setAccessible(true);

        return $method->invoke($generator, $text);
    }

    /**
     * TCPDF's core "helvetica" font can't render an en dash — Word's own bullet-style reference
     * text in the manual templates uses "– " as a plain-text marker, so without this the entire
     * line renders with an invisible glyph in place of its bullet.
     */
    public function test_en_dash_is_replaced_with_a_plain_hyphen(): void
    {
        $this->assertSame(
            '- Name the board members.',
            $this->sanitize("\u{2013} Name the board members.")
        );
    }

    public function test_smart_quotes_are_replaced_with_straight_quotes(): void
    {
        $this->assertSame(
            '"quoted" and it\'s fine',
            $this->sanitize("\u{201C}quoted\u{201D} and it\u{2019}s fine")
        );
    }

    public function test_ellipsis_and_non_breaking_space_are_replaced(): void
    {
        $this->assertSame(
            'wait for it... then go',
            $this->sanitize("wait for it\u{2026} then\u{00A0}go")
        );
    }

    public function test_plain_ascii_text_is_left_unchanged(): void
    {
        $text = 'Compliance & Ethics Program - Section 1.';

        $this->assertSame($text, $this->sanitize($text));
    }
}
