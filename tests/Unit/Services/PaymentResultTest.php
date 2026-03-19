<?php

namespace Tests\Unit\Services;

use App\Domain\Payment\Strategies\CashPaymentStrategy;
use App\Domain\Payment\Strategies\EPayPaymentStrategy;
use App\Domain\Payment\Strategies\PaymentResult;
use App\Domain\Payment\Strategies\PaymentStrategy; // loading this file also defines PaymentResult & RefundResult
use App\Domain\Payment\Strategies\PaymentStrategyFactory;
use App\Domain\Payment\Strategies\RefundResult;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Services\Payment\WalletTransactionService;
use Mockery;
use PHPUnit\Framework\TestCase;

class PaymentResultTest extends TestCase
{
    public function test_success_result(): void
    {
        $result = PaymentResult::success('Done');
        $this->assertTrue($result->success);
        $this->assertEquals('Done', $result->message);
    }

    public function test_failure_result(): void
    {
        $result = PaymentResult::failure('Error');
        $this->assertFalse($result->success);
        $this->assertEquals('Error', $result->message);
    }

    public function test_default_success_message(): void
    {
        $result = PaymentResult::success();
        $this->assertEquals('Payment processed successfully', $result->message);
    }
}

class RefundResultTest extends TestCase
{
    public function test_success_refund(): void
    {
        $result = RefundResult::success('Refunded');
        $this->assertTrue($result->success);
    }

    public function test_failure_refund(): void
    {
        $result = RefundResult::failure('Could not refund');
        $this->assertFalse($result->success);
    }
}

class CashPaymentStrategyTest extends TestCase
{
    private CashPaymentStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new CashPaymentStrategy();
    }

    public function test_can_process_cash(): void
    {
        $this->assertTrue($this->strategy->canProcess('cash'));
    }

    public function test_cannot_process_epay(): void
    {
        $this->assertFalse($this->strategy->canProcess('e-pay'));
    }

    public function test_get_payment_method(): void
    {
        $this->assertEquals('cash', $this->strategy->getPaymentMethod());
    }

    public function test_process_booking_returns_success(): void
    {
        $booking   = Mockery::mock(Booking::class)->makePartial();
        $ride      = Mockery::mock(Ride::class)->makePartial();
        $passenger = Mockery::mock(User::class)->makePartial();

        $booking->id    = 1;
        $ride->id       = 1;
        $passenger->id  = 1;
        $booking->seats = 2;
        $ride->price_per_seat = 100;

        $result = $this->strategy->processBookingPayment($booking, $ride, $passenger);
        $this->assertTrue($result->success);
    }

    public function test_process_refund_returns_success(): void
    {
        $booking   = Mockery::mock(Booking::class)->makePartial();
        $ride      = Mockery::mock(Ride::class)->makePartial();
        $passenger = Mockery::mock(User::class)->makePartial();

        $booking->id   = 1;
        $passenger->id = 1;

        $result = $this->strategy->processRefund($booking, $ride, $passenger);
        $this->assertTrue($result->success);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}

class EPayPaymentStrategyTest extends TestCase
{
    public function test_can_process_epay(): void
    {
        $walletService = Mockery::mock(WalletTransactionService::class);
        $strategy      = new EPayPaymentStrategy($walletService);

        $this->assertTrue($strategy->canProcess('e-pay'));
    }

    public function test_cannot_process_cash(): void
    {
        $walletService = Mockery::mock(WalletTransactionService::class);
        $strategy      = new EPayPaymentStrategy($walletService);

        $this->assertFalse($strategy->canProcess('cash'));
    }

    public function test_get_payment_method(): void
    {
        $walletService = Mockery::mock(WalletTransactionService::class);
        $strategy      = new EPayPaymentStrategy($walletService);

        $this->assertEquals('e-pay', $strategy->getPaymentMethod());
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}

class PaymentStrategyFactoryTest extends TestCase
{
    private PaymentStrategyFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $walletService  = Mockery::mock(WalletTransactionService::class);
        $this->factory  = new PaymentStrategyFactory();
        // Re-register EPayStrategy with mocked dependency
        $this->factory->register(new EPayPaymentStrategy($walletService));
    }

    public function test_make_cash_strategy(): void
    {
        $strategy = $this->factory->make('cash');
        $this->assertInstanceOf(CashPaymentStrategy::class, $strategy);
    }

    public function test_make_epay_strategy(): void
    {
        $strategy = $this->factory->make('e-pay');
        $this->assertInstanceOf(EPayPaymentStrategy::class, $strategy);
    }

    public function test_make_with_enum(): void
    {
        $strategy = $this->factory->make(PaymentMethod::CASH);
        $this->assertInstanceOf(CashPaymentStrategy::class, $strategy);
    }

    public function test_make_invalid_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->factory->make('bitcoin');
    }

    public function test_available_payment_methods(): void
    {
        $methods = $this->factory->getAvailablePaymentMethods();
        $this->assertContains('cash',  $methods);
        $this->assertContains('e-pay', $methods);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
