<?php

namespace App\Services\Payment;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WalletTransactionService
 *
 * Single source of truth for ALL money movements in the system.
 * Every balance change and WalletTransaction record is created here.
 *
 * Money flow overview:
 *
 *  RIDE CREATION:
 *    Driver ──(5% of total ride value)──► SyCash
 *
 *  BOOKING (e-pay):
 *    Passenger ──(seats × price)──► Primary Admin (escrow)
 *
 *  RIDE COMPLETION:
 *    Primary Admin ──(total bookings)──► Driver
 *
 *  DRIVER CANCELS RIDE:
 *    Primary Admin ──(full refund per booking)──► each Passenger
 *    SyCash ──(creation fee)──► Driver
 *
 *  RIDE FINISHED WITH NO BOOKINGS:
 *    SyCash ──(creation fee)──► Driver
 *
 *  PASSENGER CANCELS (time-based):
 *    Primary Admin ──(refund %)──► Passenger
 *    Primary Admin ──(non-refundable %)──► Driver
 *
 *  Tiers:
 *    0–30% elapsed  → 100% refund to passenger
 *    30–50% elapsed →  70% refund to passenger
 *    50–70% elapsed →  50% refund to passenger
 *    70–100% elapsed→   0% refund (all to driver)
 */
class WalletTransactionService
{
    // =========================================================================
    // RIDE CREATION FEE
    // =========================================================================

    /**
     * Charge driver the ride creation fee (5% of total ride value).
     * Driver wallet → SyCash wallet.
     *
     * Called when: driver creates a ride.
     */
    public function chargeRideCreationFee(Ride $ride, User $driver): void
    {
        $totalRideValue = $ride->price_per_seat * $ride->available_seats;
        $fee            = $totalRideValue * 0.05;

        $driverWallet = $this->lockWalletByUserId($driver->id);
        $syCashWallet = $this->lockWalletByPhone(config('admin.sycash.phone'));

        $this->assertSufficientBalance($driverWallet, $fee,
            "Insufficient wallet balance. Required fee: " . number_format($fee, 0) . " SYP. " .
            "Current balance: " . number_format($driverWallet->balance, 0) . " SYP."
        );

        $driverPrev  = $driverWallet->balance;
        $syCashPrev  = $syCashWallet->balance;

        $driverWallet->balance -= $fee;
        $syCashWallet->balance += $fee;

        $driverWallet->save();
        $syCashWallet->save();

        $txId = 'RIDE_FEE_' . time() . '_' . Str::random(6);

        WalletTransaction::create([
            'wallet_id'        => $driverWallet->id,
            'user_id'          => $driver->id,
            'type'             => 'ride_creation_fee',
            'amount'           => -$fee,
            'previous_balance' => $driverPrev,
            'new_balance'      => $driverWallet->balance,
            'description'      => "Ride creation fee: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => $txId,
            'status'           => 'completed',
            'metadata'         => [
                'ride_id'          => $ride->id,
                'total_ride_value' => $totalRideValue,
                'fee_percentage'   => 5,
                'available_seats'  => $ride->available_seats,
                'price_per_seat'   => $ride->price_per_seat,
                'payment_method'   => $ride->payment_method,
            ],
        ]);

        WalletTransaction::create([
            'wallet_id'        => $syCashWallet->id,
            'user_id'          => $syCashWallet->user_id,
            'type'             => 'ride_creation_fee',
            'amount'           => $fee,
            'previous_balance' => $syCashPrev,
            'new_balance'      => $syCashWallet->balance,
            'description'      => "Ride creation fee from {$driver->first_name} {$driver->last_name}",
            'transaction_id'   => 'SYCASH_' . $txId,
            'status'           => 'completed',
            'metadata'         => [
                'ride_id'          => $ride->id,
                'driver_id'        => $driver->id,
                'driver_name'      => "{$driver->first_name} {$driver->last_name}",
                'total_ride_value' => $totalRideValue,
                'fee_percentage'   => 5,
            ],
        ]);

        Log::info('Ride creation fee charged', [
            'ride_id'        => $ride->id,
            'driver_id'      => $driver->id,
            'fee'            => $fee,
            'total_ride_val' => $totalRideValue,
        ]);
    }

    // =========================================================================
    // BOOKING PAYMENT
    // =========================================================================

    /**
     * Charge passenger for an e-pay booking (escrow to Primary Admin).
     * Passenger wallet → Primary Admin wallet.
     *
     * Called when: direct e-pay booking confirmed, OR request booking accepted by driver.
     */
    public function chargePassengerForBooking(Booking $booking, Ride $ride, User $passenger): void
    {
        $amount = $booking->seats * $ride->price_per_seat;

        $passengerWallet = $this->lockWalletByUserId($passenger->id);
        $adminWallet     = $this->lockWalletByPhone(config('admin.primary.phone'));

        $this->assertSufficientBalance($passengerWallet, $amount,
            "Insufficient wallet balance. Required: " . number_format($amount, 0) . " SYP. " .
            "Current: " . number_format($passengerWallet->balance, 0) . " SYP."
        );

        $passengerPrev = $passengerWallet->balance;
        $adminPrev     = $adminWallet->balance;

        $passengerWallet->balance -= $amount;
        $adminWallet->balance     += $amount;

        $passengerWallet->save();
        $adminWallet->save();

        $txId = 'RB_' . time() . '_' . Str::random(8);

        WalletTransaction::create([
            'wallet_id'        => $passengerWallet->id,
            'user_id'          => $passenger->id,
            'type'             => 'ride_booking_payment',
            'amount'           => -$amount,
            'previous_balance' => $passengerPrev,
            'new_balance'      => $passengerWallet->balance,
            'description'      => "Payment for {$booking->seats} seat(s): {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => $txId,
            'status'           => 'completed',
            'metadata'         => [
                'booking_id'           => $booking->id,
                'ride_id'              => $ride->id,
                'seats'                => $booking->seats,
                'price_per_seat'       => $ride->price_per_seat,
                'driver_id'            => $ride->driver_id,
                'pickup_address'       => $ride->pickup_address,
                'destination_address'  => $ride->destination_address,
                'departure_time'       => $ride->departure_time->toDateTimeString(),
            ],
        ]);

        WalletTransaction::create([
            'wallet_id'        => $adminWallet->id,
            'user_id'          => $adminWallet->user_id,
            'type'             => 'ride_booking_received',
            'amount'           => $amount,
            'previous_balance' => $adminPrev,
            'new_balance'      => $adminWallet->balance,
            'description'      => "Booking payment from {$passenger->first_name} {$passenger->last_name} — {$booking->seats} seat(s)",
            'transaction_id'   => 'ADMIN_' . $txId,
            'status'           => 'completed',
            'metadata'         => [
                'booking_id'          => $booking->id,
                'ride_id'             => $ride->id,
                'passenger_id'        => $passenger->id,
                'passenger_name'      => "{$passenger->first_name} {$passenger->last_name}",
                'seats'               => $booking->seats,
                'price_per_seat'      => $ride->price_per_seat,
                'driver_id'           => $ride->driver_id,
                'pickup_address'      => $ride->pickup_address,
                'destination_address' => $ride->destination_address,
            ],
        ]);

        Log::info('Passenger charged for booking', [
            'booking_id'   => $booking->id,
            'passenger_id' => $passenger->id,
            'amount'       => $amount,
        ]);
    }

    // =========================================================================
    // RIDE COMPLETION PAYOUT
    // =========================================================================

    /**
     * Release escrow to driver after ride is confirmed complete.
     * Primary Admin wallet → Driver wallet.
     *
     * Called when: all parties confirm ride completion (e-pay rides only).
     */
    public function releaseEarningsToDriver(Ride $ride, Collection $confirmedBookings): void
    {
        $totalAmount = $confirmedBookings->sum(fn($b) => $b->seats * $ride->price_per_seat);

        if ($totalAmount <= 0) {
            Log::info('No e-pay bookings to release for ride', ['ride_id' => $ride->id]);
            return;
        }

        $adminWallet  = $this->lockWalletByPhone(config('admin.primary.phone'));
        $driverWallet = $this->lockWalletByUserId($ride->driver_id);

        $this->assertSufficientBalance($adminWallet, $totalAmount,
            "Insufficient admin wallet balance for driver payout. Required: {$totalAmount}"
        );

        $adminPrev  = $adminWallet->balance;
        $driverPrev = $driverWallet->balance;

        $adminWallet->balance  -= $totalAmount;
        $driverWallet->balance += $totalAmount;

        $adminWallet->save();
        $driverWallet->save();

        $txId = 'RIDE_COMP_' . time() . '_' . Str::random(6);

        WalletTransaction::create([
            'wallet_id'        => $adminWallet->id,
            'user_id'          => $adminWallet->user_id,
            'type'             => 'ride_payout',
            'amount'           => -$totalAmount,
            'previous_balance' => $adminPrev,
            'new_balance'      => $adminWallet->balance,
            'description'      => "Payout to driver for completed ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => 'ADMIN_' . $txId,
            'status'           => 'completed',
            'metadata'         => [
                'ride_id'         => $ride->id,
                'driver_id'       => $ride->driver_id,
                'passenger_count' => $confirmedBookings->count(),
                'total_seats'     => $confirmedBookings->sum('seats'),
                'total_amount'    => $totalAmount,
            ],
        ]);

        WalletTransaction::create([
            'wallet_id'        => $driverWallet->id,
            'user_id'          => $ride->driver_id,
            'type'             => 'ride_earnings',
            'amount'           => $totalAmount,
            'previous_balance' => $driverPrev,
            'new_balance'      => $driverWallet->balance,
            'description'      => "Earnings from completed ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => 'DRIVER_' . $txId,
            'status'           => 'completed',
            'metadata'         => [
                'ride_id'   => $ride->id,
                'breakdown' => $confirmedBookings->map(fn($b) => [
                    'booking_id'   => $b->id,
                    'passenger_id' => $b->user_id,
                    'seats'        => $b->seats,
                    'amount'       => $b->seats * $ride->price_per_seat,
                ])->toArray(),
            ],
        ]);

        Log::info('Driver earnings released', [
            'ride_id'      => $ride->id,
            'driver_id'    => $ride->driver_id,
            'total_amount' => $totalAmount,
        ]);
    }

    // =========================================================================
    // DRIVER CANCELS RIDE
    // =========================================================================

    /**
     * Full refund all passengers when driver cancels the ride.
     * Primary Admin wallet → each Passenger wallet.
     *
     * Called when: driver cancels a ride that has confirmed/pending bookings.
     */
    public function refundPassengersForDriverCancellation(Ride $ride, Collection $bookings): void
    {
        if ($bookings->isEmpty()) {
            return;
        }

        $totalRefund = $bookings->sum(fn($b) => $b->seats * $ride->price_per_seat);

        $adminWallet = $this->lockWalletByPhone(config('admin.primary.phone'));

        $this->assertSufficientBalance($adminWallet, $totalRefund,
            "Insufficient admin balance for passenger refunds. Required: {$totalRefund}"
        );

        $adminPrev = $adminWallet->balance;
        $adminWallet->balance -= $totalRefund;
        $adminWallet->save();

        $txId = 'DRIVER_CANCEL_' . time() . '_' . Str::random(6);

        WalletTransaction::create([
            'wallet_id'        => $adminWallet->id,
            'user_id'          => $adminWallet->user_id,
            'type'             => 'driver_cancellation_refunds',
            'amount'           => -$totalRefund,
            'previous_balance' => $adminPrev,
            'new_balance'      => $adminWallet->balance,
            'description'      => "Passenger refunds for driver-cancelled ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => 'ADMIN_' . $txId,
            'status'           => 'completed',
            'metadata'         => [
                'ride_id'           => $ride->id,
                'driver_id'         => $ride->driver_id,
                'passengers_count'  => $bookings->count(),
                'total_refunded'    => $totalRefund,
            ],
        ]);

        foreach ($bookings as $booking) {
            $refundAmount    = $booking->seats * $ride->price_per_seat;
            $passengerWallet = $this->lockWalletByUserId($booking->user_id);
            $passengerPrev   = $passengerWallet->balance;

            $passengerWallet->balance += $refundAmount;
            $passengerWallet->save();

            WalletTransaction::create([
                'wallet_id'        => $passengerWallet->id,
                'user_id'          => $booking->user_id,
                'type'             => 'driver_cancellation_refund',
                'amount'           => $refundAmount,
                'previous_balance' => $passengerPrev,
                'new_balance'      => $passengerWallet->balance,
                'description'      => "Full refund — driver cancelled ride: {$ride->pickup_address} → {$ride->destination_address}",
                'transaction_id'   => 'PASS_' . $txId . '_' . $booking->id,
                'status'           => 'completed',
                'metadata'         => [
                    'booking_id'          => $booking->id,
                    'ride_id'             => $ride->id,
                    'seats_refunded'      => $booking->seats,
                    'price_per_seat'      => $ride->price_per_seat,
                    'refund_reason'       => 'driver_cancelled_ride',
                    'pickup_address'      => $ride->pickup_address,
                    'destination_address' => $ride->destination_address,
                ],
            ]);

            Log::info('Passenger refunded for driver cancellation', [
                'booking_id'   => $booking->id,
                'passenger_id' => $booking->user_id,
                'amount'       => $refundAmount,
            ]);
        }
    }

    /**
     * Refund driver's creation fee when driver cancels their own ride.
     * SyCash wallet → Driver wallet.
     *
     * Called when: driver cancels a ride (always, regardless of bookings).
     */
    public function refundDriverCreationFeeOnCancellation(Ride $ride, int $originalSeats): void
    {
        $totalRideValue = $ride->price_per_seat * $originalSeats;
        $refundAmount   = $totalRideValue * 0.05;

        $syCashWallet = $this->lockWalletByPhone(config('admin.sycash.phone'));
        $driverWallet = $this->lockWalletByUserId($ride->driver_id);

        $this->assertSufficientBalance($syCashWallet, $refundAmount,
            "Insufficient SyCash balance for driver creation fee refund. Required: {$refundAmount}"
        );

        $syCashPrev = $syCashWallet->balance;
        $driverPrev = $driverWallet->balance;

        $syCashWallet->balance -= $refundAmount;
        $driverWallet->balance += $refundAmount;

        $syCashWallet->save();
        $driverWallet->save();

        $txId = 'DRIVER_SELF_CANCEL_' . time() . '_' . Str::random(6);

        WalletTransaction::create([
            'wallet_id'        => $syCashWallet->id,
            'user_id'          => $syCashWallet->user_id,
            'type'             => 'driver_self_cancellation_refund',
            'amount'           => -$refundAmount,
            'previous_balance' => $syCashPrev,
            'new_balance'      => $syCashWallet->balance,
            'description'      => "Creation fee refund for driver-cancelled ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => 'SYCASH_' . $txId,
            'status'           => 'completed',
            'metadata'         => [
                'ride_id'        => $ride->id,
                'driver_id'      => $ride->driver_id,
                'original_seats' => $originalSeats,
                'total_value'    => $totalRideValue,
                'fee_percentage' => 5,
                'refund_reason'  => 'driver_self_cancelled',
            ],
        ]);

        WalletTransaction::create([
            'wallet_id'        => $driverWallet->id,
            'user_id'          => $ride->driver_id,
            'type'             => 'ride_creation_fee_refund',
            'amount'           => $refundAmount,
            'previous_balance' => $driverPrev,
            'new_balance'      => $driverWallet->balance,
            'description'      => "Creation fee refunded — self-cancelled ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => 'DRIVER_' . $txId,
            'status'           => 'completed',
            'metadata'         => [
                'ride_id'        => $ride->id,
                'original_seats' => $originalSeats,
                'total_value'    => $totalRideValue,
                'fee_percentage' => 5,
                'refund_reason'  => 'self_cancelled_ride',
            ],
        ]);

        Log::info('Driver creation fee refunded on self-cancellation', [
            'ride_id'       => $ride->id,
            'driver_id'     => $ride->driver_id,
            'refund_amount' => $refundAmount,
        ]);
    }

    // =========================================================================
    // NO BOOKINGS RIDE FINISH
    // =========================================================================

    /**
     * Refund driver creation fee when ride finishes with zero bookings.
     * SyCash wallet → Driver wallet.
     *
     * Called when: driver finishes a ride but nobody booked it.
     */
    public function refundCreationFeeNoBookings(Ride $ride): void
    {
        // For rides with no bookings, available_seats = original seats
        $originalSeats  = $ride->available_seats;
        $totalRideValue = $ride->price_per_seat * $originalSeats;
        $refundAmount   = $totalRideValue * 0.05;

        $syCashWallet = $this->lockWalletByPhone(config('admin.sycash.phone'));
        $driverWallet = $this->lockWalletByUserId($ride->driver_id);

        $this->assertSufficientBalance($syCashWallet, $refundAmount,
            "Insufficient SyCash balance for no-booking refund. Required: {$refundAmount}"
        );

        $syCashPrev = $syCashWallet->balance;
        $driverPrev = $driverWallet->balance;

        $syCashWallet->balance -= $refundAmount;
        $driverWallet->balance += $refundAmount;

        $syCashWallet->save();
        $driverWallet->save();

        $txId = 'NO_BOOKING_REFUND_' . time() . '_' . Str::random(6);

        WalletTransaction::create([
            'wallet_id'        => $syCashWallet->id,
            'user_id'          => $syCashWallet->user_id,
            'type'             => 'no_booking_refund',
            'amount'           => -$refundAmount,
            'previous_balance' => $syCashPrev,
            'new_balance'      => $syCashWallet->balance,
            'description'      => "Creation fee refund — no bookings received for ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => 'SYCASH_' . $txId,
            'status'           => 'completed',
            'metadata'         => [
                'ride_id'        => $ride->id,
                'driver_id'      => $ride->driver_id,
                'original_seats' => $originalSeats,
                'total_value'    => $totalRideValue,
                'fee_percentage' => 5,
                'refund_reason'  => 'no_bookings_received',
            ],
        ]);

        WalletTransaction::create([
            'wallet_id'        => $driverWallet->id,
            'user_id'          => $ride->driver_id,
            'type'             => 'ride_fee_refund',
            'amount'           => $refundAmount,
            'previous_balance' => $driverPrev,
            'new_balance'      => $driverWallet->balance,
            'description'      => "Creation fee refunded — no passengers booked ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => 'DRIVER_' . $txId,
            'status'           => 'completed',
            'metadata'         => [
                'ride_id'        => $ride->id,
                'original_seats' => $originalSeats,
                'total_value'    => $totalRideValue,
                'fee_percentage' => 5,
                'refund_reason'  => 'no_bookings_received',
            ],
        ]);

        Log::info('Creation fee refunded — no bookings', [
            'ride_id'       => $ride->id,
            'driver_id'     => $ride->driver_id,
            'refund_amount' => $refundAmount,
        ]);
    }

    // =========================================================================
    // TIME-BASED PASSENGER CANCELLATION
    // =========================================================================

    /**
     * Calculate and return refund info based on time elapsed since booking vs departure.
     *
     * Tiers:
     *   0–30%  elapsed → 100% refund
     *   30–50% elapsed →  70% refund
     *   50–70% elapsed →  50% refund
     *   70–100% elapsed→   0% refund
     */
    public function calculateRefundPolicy(\Carbon\Carbon $departureTime, \Carbon\Carbon $bookingCreatedAt): array
    {
        $now = now();

        if ($now->greaterThanOrEqualTo($departureTime)) {
            return [
                'refund_percentage'       => 0,
                'time_elapsed_percentage' => 100,
                'policy_tier'             => 'No refund — departure time passed',
            ];
        }

        $totalMinutes   = $bookingCreatedAt->diffInMinutes($departureTime);
        $elapsedMinutes = $bookingCreatedAt->diffInMinutes($now);
        $elapsedPct     = $totalMinutes > 0
            ? min(100, ($elapsedMinutes / $totalMinutes) * 100)
            : 100;

        if ($elapsedPct <= 30) {
            $tier = ['refund_percentage' => 100, 'policy_tier' => 'Full refund (0–30% elapsed)'];
        } elseif ($elapsedPct <= 50) {
            $tier = ['refund_percentage' => 70,  'policy_tier' => 'Partial refund (30–50% elapsed)'];
        } elseif ($elapsedPct <= 70) {
            $tier = ['refund_percentage' => 50,  'policy_tier' => 'Partial refund (50–70% elapsed)'];
        } else {
            $tier = ['refund_percentage' => 0,   'policy_tier' => 'No refund (70–100% elapsed)'];
        }

        return array_merge($tier, [
            'time_elapsed_percentage'  => $elapsedPct,
            'total_minutes_from_booking' => $totalMinutes,
            'minutes_elapsed'          => $elapsedMinutes,
        ]);
    }

    /**
     * Process time-based cancellation fund redistribution.
     * Primary Admin → Passenger (refund portion) + Primary Admin → Driver (non-refundable portion).
     *
     * Called when: passenger cancels seats (partial or full booking).
     *
     * @param array $refundPolicy  Result of calculateRefundPolicy()
     */
    public function processTimeBasedCancellation(
        Booking $booking,
        Ride    $ride,
        int     $seatsCancelled,
        array   $refundPolicy
    ): void {
        $totalPaid        = $seatsCancelled * $ride->price_per_seat;
        $refundAmount     = ($totalPaid * $refundPolicy['refund_percentage']) / 100;
        $driverAmount     = $totalPaid - $refundAmount; // non-refundable goes to driver

        $adminWallet     = $this->lockWalletByPhone(config('admin.primary.phone'));
        $passengerWallet = $this->lockWalletByUserId($booking->user_id);
        $driverWallet    = $this->lockWalletByUserId($ride->driver_id);

        $this->assertSufficientBalance($adminWallet, $totalPaid,
            "Insufficient admin balance for cancellation processing. Required: {$totalPaid}"
        );

        $adminPrev     = $adminWallet->balance;
        $passengerPrev = $passengerWallet->balance;
        $driverPrev    = $driverWallet->balance;

        // Admin releases full amount it holds for these seats
        $adminWallet->balance -= $totalPaid;

        if ($refundAmount > 0) {
            $passengerWallet->balance += $refundAmount;
        }

        if ($driverAmount > 0) {
            $driverWallet->balance += $driverAmount;
        }

        $adminWallet->save();
        $passengerWallet->save();
        $driverWallet->save();

        $txId = 'TIME_CANCEL_' . time() . '_' . Str::random(6);

        // Admin debit
        WalletTransaction::create([
            'wallet_id'        => $adminWallet->id,
            'user_id'          => $adminWallet->user_id,
            'type'             => 'cancellation_processing',
            'amount'           => -$totalPaid,
            'previous_balance' => $adminPrev,
            'new_balance'      => $adminWallet->balance,
            'description'      => "Cancellation processing: {$seatsCancelled} seat(s) — refund " .
                number_format($refundAmount, 0) . " SYP, driver " .
                number_format($driverAmount, 0) . " SYP",
            'transaction_id'   => 'ADMIN_' . $txId,
            'status'           => 'completed',
            'metadata'         => [
                'booking_id'              => $booking->id,
                'ride_id'                 => $ride->id,
                'passenger_id'            => $booking->user_id,
                'driver_id'               => $ride->driver_id,
                'seats_cancelled'         => $seatsCancelled,
                'total_paid'              => $totalPaid,
                'refund_amount'           => $refundAmount,
                'driver_payout'           => $driverAmount,
                'refund_percentage'       => $refundPolicy['refund_percentage'],
                'time_elapsed_percentage' => $refundPolicy['time_elapsed_percentage'],
                'policy_tier'             => $refundPolicy['policy_tier'],
            ],
        ]);

        // Passenger refund (only if amount > 0)
        if ($refundAmount > 0) {
            WalletTransaction::create([
                'wallet_id'        => $passengerWallet->id,
                'user_id'          => $booking->user_id,
                'type'             => 'time_based_refund',
                'amount'           => $refundAmount,
                'previous_balance' => $passengerPrev,
                'new_balance'      => $passengerWallet->balance,
                'description'      => "Refund ({$refundPolicy['refund_percentage']}%) for {$seatsCancelled} cancelled seat(s) — {$refundPolicy['policy_tier']}",
                'transaction_id'   => 'REFUND_' . $txId,
                'status'           => 'completed',
                'metadata'         => [
                    'booking_id'              => $booking->id,
                    'ride_id'                 => $ride->id,
                    'seats_cancelled'         => $seatsCancelled,
                    'refund_percentage'       => $refundPolicy['refund_percentage'],
                    'time_elapsed_percentage' => $refundPolicy['time_elapsed_percentage'],
                    'policy_tier'             => $refundPolicy['policy_tier'],
                    'pickup_address'          => $ride->pickup_address,
                    'destination_address'     => $ride->destination_address,
                ],
            ]);
        } else {
            // Record 0-amount entry so passenger has an audit trail
            WalletTransaction::create([
                'wallet_id'        => $passengerWallet->id,
                'user_id'          => $booking->user_id,
                'type'             => 'cancellation_no_refund',
                'amount'           => 0,
                'previous_balance' => $passengerPrev,
                'new_balance'      => $passengerWallet->balance,
                'description'      => "No refund — late cancellation: {$seatsCancelled} seat(s) forfeited ({$refundPolicy['policy_tier']})",
                'transaction_id'   => 'NO_REFUND_' . $txId,
                'status'           => 'completed',
                'metadata'         => [
                    'booking_id'              => $booking->id,
                    'ride_id'                 => $ride->id,
                    'seats_cancelled'         => $seatsCancelled,
                    'forfeited_amount'        => $totalPaid,
                    'refund_percentage'       => 0,
                    'time_elapsed_percentage' => $refundPolicy['time_elapsed_percentage'],
                    'policy_tier'             => $refundPolicy['policy_tier'],
                ],
            ]);
        }

        // Driver payout (only if amount > 0)
        if ($driverAmount > 0) {
            WalletTransaction::create([
                'wallet_id'        => $driverWallet->id,
                'user_id'          => $ride->driver_id,
                'type'             => 'cancellation_fee_earnings',
                'amount'           => $driverAmount,
                'previous_balance' => $driverPrev,
                'new_balance'      => $driverWallet->balance,
                'description'      => "Cancellation earnings — {$seatsCancelled} seat(s) ({$refundPolicy['policy_tier']})",
                'transaction_id'   => 'DRIVER_' . $txId,
                'status'           => 'completed',
                'metadata'         => [
                    'booking_id'              => $booking->id,
                    'ride_id'                 => $ride->id,
                    'passenger_id'            => $booking->user_id,
                    'seats_cancelled'         => $seatsCancelled,
                    'total_paid_by_passenger' => $totalPaid,
                    'refund_percentage'       => $refundPolicy['refund_percentage'],
                    'time_elapsed_percentage' => $refundPolicy['time_elapsed_percentage'],
                    'policy_tier'             => $refundPolicy['policy_tier'],
                    'pickup_address'          => $ride->pickup_address,
                    'destination_address'     => $ride->destination_address,
                ],
            ]);
        }

        Log::info('Time-based cancellation processed', [
            'booking_id'      => $booking->id,
            'seats_cancelled' => $seatsCancelled,
            'total_paid'      => $totalPaid,
            'refund_amount'   => $refundAmount,
            'driver_amount'   => $driverAmount,
            'policy_tier'     => $refundPolicy['policy_tier'],
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Lock a wallet row by user ID for the current transaction.
     */
    private function lockWalletByUserId(int $userId): Wallet
    {
        $wallet = Wallet::where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new \RuntimeException("Wallet not found for user ID: {$userId}");
        }

        return $wallet;
    }

    /**
     * Lock a wallet row by phone number for the current transaction.
     */
    private function lockWalletByPhone(string $phone): Wallet
    {
        $wallet = Wallet::where('phone_number', $phone)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new \RuntimeException("Wallet not found for phone: {$phone}");
        }

        return $wallet;
    }

    /**
     * Assert a wallet has sufficient balance; throw with human-readable message if not.
     */
    private function assertSufficientBalance(Wallet $wallet, float $required, string $message): void
    {
        if ($wallet->balance < $required) {
            throw new \RuntimeException($message);
        }
    }
}
