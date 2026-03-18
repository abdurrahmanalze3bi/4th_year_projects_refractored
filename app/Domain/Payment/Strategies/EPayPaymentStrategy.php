<?php

namespace App\Domain\Payment\Strategies;

use App\Domain\ValueObjects\Money;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\WalletTransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * E-Pay Payment Strategy
 *
 * Handles wallet-based payments.
 * Transfers money from passenger wallet to driver wallet.
 */
final class EPayPaymentStrategy implements PaymentStrategy
{


    // Replace with
    public function __construct(
        private WalletTransactionService $walletService
    ) {}
    public function processBookingPayment(Booking $booking, Ride $ride, User $passenger): PaymentResult
    {
        try {
            $this->walletService->chargePassengerForBooking($booking, $ride, $passenger);
            return PaymentResult::success('Payment processed successfully');
        } catch (\Exception $e) {
            return PaymentResult::failure($e->getMessage());
        }
    }

    public function processRefund(Booking $booking, Ride $ride, User $passenger): RefundResult
    {
        try {
            // Full refund treated as driver cancellation of single booking
            $bookings = collect([$booking]);
            $this->walletService->refundPassengersForDriverCancellation($ride, $bookings);
            return RefundResult::success('Refund processed successfully');
        } catch (\Exception $e) {
            return RefundResult::failure($e->getMessage());
        }
    }

    public function canProcess(string $paymentMethod): bool
    {
        return $paymentMethod === 'e-pay';
    }

    public function getPaymentMethod(): string
    {
        return 'e-pay';
    }
}
