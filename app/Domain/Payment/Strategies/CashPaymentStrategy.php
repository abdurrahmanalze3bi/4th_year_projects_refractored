<?php

namespace App\Domain\Payment\Strategies;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Cash Payment Strategy
 * 
 * Handles cash payments (offline).
 * No wallet transactions needed - payment happens in person.
 */
final class CashPaymentStrategy implements PaymentStrategy
{
    public function processBookingPayment(Booking $booking, Ride $ride, User $passenger): PaymentResult
    {
        // Cash payment - no immediate transaction needed
        // Payment will be collected offline
        
        Log::info('Cash payment recorded', [
            'booking_id' => $booking->id,
            'ride_id' => $ride->id,
            'passenger_id' => $passenger->id,
            'amount' => $booking->seats * $ride->price_per_seat,
        ]);

        return PaymentResult::success('Cash payment will be collected offline');
    }

    public function processRefund(Booking $booking, Ride $ride, User $passenger): RefundResult
    {
        // Cash refunds handled offline
        
        Log::info('Cash refund recorded', [
            'booking_id' => $booking->id,
            'passenger_id' => $passenger->id,
        ]);

        return RefundResult::success('Cash refund will be processed offline');
    }

    public function canProcess(string $paymentMethod): bool
    {
        return $paymentMethod === 'cash';
    }

    public function getPaymentMethod(): string
    {
        return 'cash';
    }
}
