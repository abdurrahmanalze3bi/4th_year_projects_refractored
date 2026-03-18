<?php

namespace App\Domain\Payment\Strategies;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;

/**
 * Payment Result - Returned from payment operations
 */
final class PaymentResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?array $transactionIds = null,
        public readonly ?array $metadata = null,
    ) {}

    public static function success(string $message = 'Payment processed successfully', ?array $transactionIds = null): self
    {
        return new self(true, $message, $transactionIds);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}

/**
 * Refund Result - Returned from refund operations
 */
final class RefundResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?array $transactionIds = null,
    ) {}

    public static function success(string $message = 'Refund processed successfully', ?array $transactionIds = null): self
    {
        return new self(true, $message, $transactionIds);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}

/**
 * Payment Strategy Interface
 * 
 * Defines the contract for all payment methods.
 * Each payment method (cash, e-pay, credit card, etc.) implements this interface.
 * 
 * This is the Strategy Pattern - it allows you to select payment logic at runtime
 * without if-else chains.
 */
interface PaymentStrategy
{
    /**
     * Process payment for a booking
     */
    public function processBookingPayment(Booking $booking, Ride $ride, User $passenger): PaymentResult;

    /**
     * Process refund for a cancelled booking
     */
    public function processRefund(Booking $booking, Ride $ride, User $passenger): RefundResult;

    /**
     * Check if this strategy can process the given payment method
     */
    public function canProcess(string $paymentMethod): bool;

    /**
     * Get the payment method identifier
     */
    public function getPaymentMethod(): string;
}
