<?php

namespace Tests\Unit\Enums;

use App\Enums\PaymentMethod;
use PHPUnit\Framework\TestCase;

class PaymentMethodTest extends TestCase
{
    public function test_epay_requires_wallet(): void
    {
        $this->assertTrue(PaymentMethod::E_PAY->requiresWallet());
    }

    public function test_cash_does_not_require_wallet(): void
    {
        $this->assertFalse(PaymentMethod::CASH->requiresWallet());
    }

    public function test_epay_is_immediate(): void
    {
        $this->assertTrue(PaymentMethod::E_PAY->isImmediate());
    }

    public function test_cash_is_not_immediate(): void
    {
        $this->assertFalse(PaymentMethod::CASH->isImmediate());
    }

    public function test_available_returns_both(): void
    {
        $available = PaymentMethod::available();
        $this->assertContains('cash', $available);
        $this->assertContains('e-pay', $available);
    }

    public function test_labels(): void
    {
        $this->assertEquals('Cash', PaymentMethod::CASH->label());
        $this->assertEquals('E-Payment (Wallet)', PaymentMethod::E_PAY->label());
    }
}
