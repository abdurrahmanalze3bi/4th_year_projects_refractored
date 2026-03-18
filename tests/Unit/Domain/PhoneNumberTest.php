<?php

namespace Tests\Unit\Domain;

use App\Domain\ValueObjects\PhoneNumber;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    // ─── Valid formats ────────────────────────────────────────────────────────

    public function test_accepts_09_format(): void
    {
        $phone = PhoneNumber::from('0983337214');
        $this->assertEquals('+963983337214', (string) $phone);
    }

    public function test_accepts_plus963_format(): void
    {
        $phone = PhoneNumber::from('+963983337214');
        $this->assertEquals('+963983337214', (string) $phone);
    }

    public function test_accepts_963_format_without_plus(): void
    {
        $phone = PhoneNumber::from('963983337214');
        $this->assertEquals('+963983337214', (string) $phone);
    }

    public function test_accepts_9_digit_format(): void
    {
        $phone = PhoneNumber::from('983337214');
        $this->assertEquals('+963983337214', (string) $phone);
    }

    // ─── Invalid formats ──────────────────────────────────────────────────────

    public function test_rejects_short_number(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PhoneNumber::from('123');
    }

    public function test_rejects_landline_number(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PhoneNumber::from('0112345678'); // starts with 01, not 09
    }

    public function test_rejects_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PhoneNumber::from('');
    }

    public function test_rejects_letters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PhoneNumber::from('abcdefghij');
    }

    // ─── Formatting helpers ───────────────────────────────────────────────────

    public function test_for_callmebot_strips_plus(): void
    {
        $phone = PhoneNumber::from('0983337214');
        $this->assertEquals('963983337214', $phone->forCallMeBot());
        $this->assertStringStartsNotWith('+', $phone->forCallMeBot());
    }

    public function test_for_textmebot_keeps_plus(): void
    {
        $phone = PhoneNumber::from('0983337214');
        $this->assertStringStartsWith('+', $phone->forTextMeBot());
    }

    public function test_number_method_returns_normalized(): void
    {
        $phone = PhoneNumber::from('0983337214');
        $this->assertEquals('+963983337214', $phone->number());
    }

    // ─── Equality ─────────────────────────────────────────────────────────────

    public function test_same_number_different_formats_are_equal(): void
    {
        $a = PhoneNumber::from('0983337214');
        $b = PhoneNumber::from('+963983337214');
        $this->assertTrue($a->equals($b));
    }

    public function test_different_numbers_not_equal(): void
    {
        $a = PhoneNumber::from('0983337214');
        $b = PhoneNumber::from('0912345678');
        $this->assertFalse($a->equals($b));
    }
}
