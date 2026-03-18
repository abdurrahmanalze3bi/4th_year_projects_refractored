<?php

namespace Tests\Unit\Domain;

use App\Domain\ValueObjects\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_can_create_from_float(): void
    {
        $money = Money::from(100.50);
        $this->assertEquals(100.50, $money->amount());
    }

    public function test_can_create_zero(): void
    {
        $money = Money::zero();
        $this->assertTrue($money->isZero());
        $this->assertEquals(0.0, $money->amount());
    }

    public function test_cannot_create_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::from(-1);
    }

    public function test_stores_currency(): void
    {
        $money = Money::from(100, 'SYP');
        $this->assertEquals('SYP', $money->currency());
    }

    public function test_add_two_amounts(): void
    {
        $a = Money::from(100);
        $b = Money::from(50);
        $this->assertEquals(150.0, $a->add($b)->amount());
    }

    public function test_subtract_smaller_from_larger(): void
    {
        $a = Money::from(200);
        $b = Money::from(50);
        $this->assertEquals(150.0, $a->subtract($b)->amount());
    }

    public function test_subtract_cannot_go_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::from(50)->subtract(Money::from(100));
    }

    public function test_multiply(): void
    {
        $this->assertEquals(300.0, Money::from(100)->multiply(3)->amount());
    }

    public function test_multiply_by_zero_gives_zero(): void
    {
        $this->assertTrue(Money::from(500)->multiply(0)->isZero());
    }

    public function test_divide(): void
    {
        $this->assertEquals(100.0, Money::from(300)->divide(3)->amount());
    }

    public function test_divide_by_zero_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::from(100)->divide(0);
    }

    public function test_five_percent_of_one_million_syp(): void
    {
        $fee = Money::from(1_000_000)->percentage(5);
        $this->assertEquals(50_000.0, $fee->amount());
    }

    public function test_percentage_of_zero_is_zero(): void
    {
        $this->assertTrue(Money::from(0)->percentage(5)->isZero());
    }

    public function test_100_percent_returns_full_amount(): void
    {
        $this->assertEquals(500.0, Money::from(500)->percentage(100)->amount());
    }

    public function test_70_percent_refund_calculation(): void
    {
        $paid = Money::from(200_000);
        $refund = $paid->percentage(70);
        $driverKeeps = $paid->subtract($refund);
        $this->assertEquals(140_000.0, $refund->amount());
        $this->assertEquals(60_000.0, $driverKeeps->amount());
    }

    public function test_50_percent_refund_calculation(): void
    {
        $this->assertEquals(50_000.0, Money::from(100_000)->percentage(50)->amount());
    }

    public function test_zero_percent_refund(): void
    {
        $this->assertTrue(Money::from(100_000)->percentage(0)->isZero());
    }

    public function test_greater_than(): void
    {
        $this->assertTrue(Money::from(200)->isGreaterThan(Money::from(100)));
        $this->assertFalse(Money::from(100)->isGreaterThan(Money::from(200)));
    }

    public function test_less_than(): void
    {
        $this->assertTrue(Money::from(50)->isLessThan(Money::from(100)));
    }

    public function test_equals(): void
    {
        $this->assertTrue(Money::from(100)->equals(Money::from(100)));
        $this->assertFalse(Money::from(100)->equals(Money::from(101)));
    }

    public function test_cannot_compare_different_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::from(100, 'SYP')->isGreaterThan(Money::from(100, 'USD'));
    }

    public function test_formatted_output(): void
    {
        $money = Money::from(50000);
        $this->assertStringContainsString('SYP', $money->formatted());
    }

    public function test_no_floating_point_precision_errors(): void
    {
        $result = Money::from(0.1)->add(Money::from(0.2));
        $this->assertEquals(0.30, $result->amount());
    }

    public function test_is_positive(): void
    {
        $this->assertTrue(Money::from(1)->isPositive());
        $this->assertFalse(Money::zero()->isPositive());
    }

    public function test_to_array(): void
    {
        $arr = Money::from(100)->toArray();
        $this->assertArrayHasKey('amount', $arr);
        $this->assertArrayHasKey('currency', $arr);
        $this->assertArrayHasKey('formatted', $arr);
    }
}
