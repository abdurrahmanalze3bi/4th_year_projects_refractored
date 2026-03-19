<?php

namespace App\Domain\Payment\Strategies;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;

/**
 * Payment Strategy Interface
 *
 * Defines the contract for all payment methods.
 * Each payment method (cash, e-pay, etc.) implements this interface.
 *
 * PaymentResult and RefundResult are now in their own files:
 *   - App\Domain\Payment\Strategies\PaymentResult
 *   - App\Domain\Payment\Strategies\RefundResult
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
