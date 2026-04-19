<?php

namespace App\Services\Ride;

use App\DTOs\Ride\CreateRideDTO;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Events\RideCreated;
use App\Events\RideCancelled;
use App\Interfaces\RideRepositoryInterface;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Payment\WalletTransactionService;
use App\Services\Score\ScoreService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class RideService
{
    public function __construct(
        private readonly RideRepositoryInterface  $rideRepository,
        private readonly RideValidationService    $validationService,
        private readonly RideSearchService        $searchService,
        private readonly WalletTransactionService $walletService,
        private readonly NotificationService      $notificationService,
        private readonly ScoreService             $scoreService,
    ) {}

    // =========================================================================
    // CREATE RIDE
    // =========================================================================

    /**
     * Create a new ride and immediately charge the driver the creation fee.
     *
     * Fee: 5% of (price_per_seat × available_seats) — Driver wallet → SyCash wallet.
     * Applies to both CASH and E-PAY rides; all drivers need a wallet.
     *
     * Score gate: driver score must be ≥ 50 (validated in RideValidationService).
     */
    public function createRide(CreateRideDTO $dto, User $driver): Ride
    {
        // Validate driver: verified status + score ≥ 50 + required documents
        $this->validationService->validateDriverCanCreateRide($driver);
        $this->validationService->validateDepartureTime($dto->departureTime);

        return DB::transaction(function () use ($dto, $driver) {
            // Persist ride with spatial columns (pickup/destination POINT) and route geometry
            $ride = $this->rideRepository->createRideWithGeometry($dto->toArray());

            // Charge 5% creation fee: Driver wallet → SyCash wallet
            $this->walletService->chargeRideCreationFee($ride, $driver);

            $feeAmount = $dto->calculateRideCreationFee()->amount();

            $this->notificationService->createNotification(
                $driver,
                'ride_created',
                'Ride Created ✓',
                "Your ride from {$ride->pickup_address} to {$ride->destination_address} is now live. "
                . "Creation fee charged: " . number_format($feeAmount, 0) . " SYP.",
                ['ride_id' => $ride->id, 'fee_charged' => $feeAmount],
                'normal', 'ride'
            );

            broadcast(new RideCreated($ride));

            Log::info('Ride created', [
                'ride_id'   => $ride->id,
                'driver_id' => $driver->id,
                'fee'       => $feeAmount,
            ]);

            return $ride->fresh(['driver.profile']);
        });
    }

    // =========================================================================
    // CANCEL RIDE  (driver cancels entire ride)
    // =========================================================================

    /**
     * Driver cancels their ride before it departs.
     *
     * Wallet — CONFIRMED + E-PAY bookings:
     *   Admin escrow → each passenger (100% full refund, no time-based tier).
     *
     * Wallet — always (regardless of payment method):
     *   SyCash → Driver (creation fee refunded in full).
     *
     * Score — driver:
     *   Elapsed 0–30%   → 0 pts (no penalty)
     *   Elapsed 30–50%  → −7 pts
     *   Elapsed 50–100% → −12 pts
     *   cancelRate ≥ 50% overrides tier → −15 pts always
     *   Elapsed % = (now − ride.created_at) / (departure − ride.created_at) × 100
     *
     * PENDING bookings are cancelled without financial impact
     * (REQUEST bookings were never charged).
     */
    public function cancelRide(int $rideId, User $driver): Ride
    {
        return DB::transaction(function () use ($rideId, $driver) {
            $ride = Ride::lockForUpdate()->findOrFail($rideId);

            if ($ride->driver_id !== $driver->id) {
                throw new \InvalidArgumentException('Only the ride creator can cancel it');
            }

            $rideStatus = RideStatus::from($ride->status);
            if ($rideStatus->isTerminal()) {
                throw new \InvalidArgumentException(
                    "Cannot cancel a ride with status: {$rideStatus->label()}"
                );
            }

            $this->validationService->validateCanCancelRide(Carbon::parse($ride->departure_time));

            // Snapshot all active bookings before any status changes
            $activeBookings = $ride->bookings()
                ->whereNotIn('status', [
                    BookingStatus::CANCELLED->value,
                    BookingStatus::COMPLETED->value,
                    Booking::NO_SHOW,
                ])
                ->with('user')
                ->get();

            $confirmedBookings = $activeBookings->filter(
                fn($b) => $b->status === BookingStatus::CONFIRMED->value
            );
            $pendingBookings = $activeBookings->filter(
                fn($b) => $b->status === BookingStatus::PENDING->value
            );

            // Reconstruct original seat count for creation fee refund calculation
            $confirmedSeats = $confirmedBookings->sum('seats');
            $pendingSeats   = $pendingBookings->sum('seats');
            $originalSeats  = $ride->available_seats + $confirmedSeats + $pendingSeats;

            // 1. Mark ride as cancelled
            $ride->status = RideStatus::CANCELLED->value;
            $ride->save();

            // 2. Cancel every active booking
            foreach ($activeBookings as $booking) {
                $booking->status = BookingStatus::CANCELLED->value;
                $booking->save();
            }

            // 3. Refund confirmed E-PAY passengers (pending were never charged)
            if ($confirmedBookings->isNotEmpty()
                && $ride->payment_method === PaymentMethod::E_PAY->value
            ) {
                $this->walletService->refundPassengersForDriverCancellation($ride, $confirmedBookings);
            }

            // 4. Always refund the driver's creation fee
            $this->walletService->refundDriverCreationFeeOnCancellation($ride, $originalSeats);

            // 5. Score penalty for driver
            $elapsedPct = ScoreService::calculateElapsedPct(
                $ride->created_at,
                Carbon::parse($ride->departure_time)
            );
            $this->scoreService->recordDriverCancelRide($driver, $ride, $elapsedPct);

            // 6. Notify all affected passengers
            $this->notifyPassengersOnRideCancelled($ride, $confirmedBookings, $pendingBookings);

            // 7. Notify driver that fee was refunded
            $this->notificationService->createNotification(
                $driver,
                'ride_cancelled_driver',
                'Ride Cancelled',
                "Your ride from {$ride->pickup_address} to {$ride->destination_address} has been cancelled. "
                . "Your creation fee has been refunded.",
                [
                    'ride_id'              => $ride->id,
                    'passengers_notified'  => $activeBookings->count(),
                    'elapsed_pct'          => round($elapsedPct, 2),
                ],
                'normal', 'ride'
            );

            broadcast(new RideCancelled($ride, $activeBookings->toArray(), $driver));

            Log::info('Ride cancelled by driver', [
                'ride_id'    => $ride->id,
                'driver_id'  => $driver->id,
                'elapsed_pct'=> round($elapsedPct, 2),
                'confirmed'  => $confirmedBookings->count(),
                'pending'    => $pendingBookings->count(),
                'original_seats' => $originalSeats,
            ]);

            return $ride->fresh();
        });
    }

    // =========================================================================
    // FINISH RIDE  (driver signals ride is over)
    // =========================================================================

    /**
     * Driver marks the ride as finished after departure.
     *
     * Case A — No confirmed bookings (empty ride):
     *   → Status: FINISHED immediately.
     *   → Creation fee refunded (SyCash → Driver).
     *   → No score event (no passengers completed anything).
     *
     * Case B — Has confirmed bookings (CASH or E-PAY):
     *   → Status: AWAITING_CONFIRMATION.
     *   → All parties (driver + every confirmed passenger) are notified to confirm.
     *   → Payment release and score are handled in checkAndCompleteRide() once
     *     every party has confirmed.
     *
     * The two-way confirmation step exists for both payment methods because it:
     *   (a) Verifies the ride actually happened before releasing E-PAY funds.
     *   (b) Generates trustworthy score events (+10) for both CASH and E-PAY rides.
     */
    public function finishRide(int $rideId, User $driver): array
    {
        $ride = Ride::findOrFail($rideId);

        if ($ride->driver_id !== $driver->id) {
            throw new \InvalidArgumentException('Only the ride driver can finish it');
        }

        $rideStatus = RideStatus::from($ride->status);
        if (!in_array($rideStatus, [RideStatus::ACTIVE, RideStatus::FULL])) {
            throw new \InvalidArgumentException(
                "Can only finish an active or full ride (current: {$rideStatus->label()})"
            );
        }

        if (now()->lessThan(Carbon::parse($ride->departure_time))) {
            throw new \InvalidArgumentException('Cannot finish a ride before its departure time');
        }

        $confirmedBookings = $ride->bookings()
            ->where('status', BookingStatus::CONFIRMED->value)
            ->get();

        // ── Case A: empty ride ────────────────────────────────────────────────
        if ($confirmedBookings->isEmpty()) {
            return DB::transaction(function () use ($ride) {
                $this->walletService->refundCreationFeeNoBookings($ride);

                $ride->status      = RideStatus::FINISHED->value;
                $ride->finished_at = now();
                $ride->save();

                $this->notificationService->createNotification(
                    $ride->driver,
                    'ride_finished_no_passengers',
                    'Ride Finished',
                    "Ride from {$ride->pickup_address} to {$ride->destination_address} finished. "
                    . "No passengers booked — creation fee refunded.",
                    ['ride_id' => $ride->id],
                    'normal', 'ride'
                );

                Log::info('Ride finished with no passengers', ['ride_id' => $ride->id]);

                return [
                    'status'                => RideStatus::FINISHED->value,
                    'message'               => 'Ride finished. No passengers booked — creation fee refunded.',
                    'requires_confirmation' => false,
                ];
            });
        }

        // ── Case B: confirmed passengers — await mutual confirmation ──────────
        return DB::transaction(function () use ($ride) {
            $ride->status      = RideStatus::AWAITING_CONFIRMATION->value;
            $ride->finished_at = now();
            $ride->save();

            $this->notifyAllForConfirmation($ride);

            Log::info('Ride moved to awaiting confirmation', ['ride_id' => $ride->id]);

            return [
                'status'                => RideStatus::AWAITING_CONFIRMATION->value,
                'message'               => 'Ride completed. Waiting for all parties to confirm.',
                'requires_confirmation' => true,
            ];
        });
    }

    // =========================================================================
    // DRIVER CONFIRMS COMPLETION
    // =========================================================================

    /**
     * Driver confirms the ride took place.
     * Triggers checkAndCompleteRide() — finalises everything if all passengers
     * have also confirmed.
     */
    public function driverConfirmCompletion(int $rideId, User $driver): array
    {
        $ride = Ride::findOrFail($rideId);

        if ($ride->driver_id !== $driver->id) {
            throw new \InvalidArgumentException('Only the ride driver can confirm completion');
        }
        if ($ride->status !== RideStatus::AWAITING_CONFIRMATION->value) {
            throw new \InvalidArgumentException('Ride is not awaiting confirmation');
        }
        if ($ride->driver_confirmed_at) {
            throw new \InvalidArgumentException('Driver has already confirmed this ride');
        }

        DB::transaction(function () use ($ride) {
            $ride->driver_confirmed_at = now();
            $ride->save();
        });

        Log::info('Driver confirmed completion', [
            'ride_id'   => $ride->id,
            'driver_id' => $driver->id,
        ]);

        $this->checkAndCompleteRide($ride->fresh());

        return ['message' => 'Driver confirmation received. Waiting for passenger confirmations.'];
    }

    // =========================================================================
    // CHECK AND COMPLETE  (called after every confirmation)
    // =========================================================================

    /**
     * Checks whether all parties have confirmed. When they have:
     *   1. Release E-PAY escrow → driver (CASH: skip wallet, no-op).
     *   2. Mark ride FINISHED and all confirmed bookings COMPLETED.
     *   3. Record +10 score for driver and every confirmed passenger.
     *   4. Send completion notifications to all parties.
     *
     * Idempotent — returns immediately if the ride is not in AWAITING_CONFIRMATION
     * or if not all confirmations are in yet.
     */
    public function checkAndCompleteRide(Ride $ride): void
    {
        if ($ride->status !== RideStatus::AWAITING_CONFIRMATION->value) {
            return;
        }

        $confirmedBookings   = $ride->bookings()
            ->where('status', BookingStatus::CONFIRMED->value)
            ->with('user')
            ->get();

        $totalPassengers     = $confirmedBookings->count();
        $passengersConfirmed = $confirmedBookings->whereNotNull('passenger_confirmed_at')->count();

        // Guard: not everyone has confirmed yet
        if (!$ride->driver_confirmed_at || $passengersConfirmed < $totalPassengers) {
            return;
        }

        DB::transaction(function () use ($totalPassengers, $ride, $confirmedBookings) {
            // 1. Release E-PAY earnings from admin escrow → driver's wallet
            if ($ride->payment_method === PaymentMethod::E_PAY->value
                && $confirmedBookings->isNotEmpty()
            ) {
                $this->walletService->releaseEarningsToDriver($ride, $confirmedBookings);
            }

            // 2. Finalise ride and bookings
            $ride->status = RideStatus::FINISHED->value;
            $ride->save();

            $ride->bookings()
                ->where('status', BookingStatus::CONFIRMED->value)
                ->update([
                    'status'       => BookingStatus::COMPLETED->value,
                    'completed_at' => now(),
                ]);

            // 3. Record +10 score for driver + all confirmed passengers.
            //    Reload ride so bookings reflect COMPLETED status for ScoreService.
            $freshRide = $ride->fresh(['driver', 'bookings.user']);
            $this->scoreService->recordRideCompleted($freshRide);

            // 4. Notify driver
            $totalEarnings = $confirmedBookings->sum(fn($b) => $b->seats * $ride->price_per_seat);

            if ($ride->payment_method === PaymentMethod::E_PAY->value) {
                $this->notificationService->createNotification(
                    $ride->driver,
                    'ride_completed_earnings',
                    'Payment Released ✓  +10 pts',
                    "Ride complete! " . number_format($totalEarnings, 0) . " SYP released to your wallet. "
                    . "+10 trust points added.",
                    ['ride_id' => $ride->id, 'total_earnings' => $totalEarnings],
                    'high', 'ride'
                );
            } else {
                $this->notificationService->createNotification(
                    $ride->driver,
                    'ride_completed',
                    'Ride Completed ✓  +10 pts',
                    "Ride from {$ride->pickup_address} to {$ride->destination_address} confirmed complete. "
                    . "+10 trust points added.",
                    ['ride_id' => $ride->id],
                    'normal', 'ride'
                );
            }

            // 5. Notify each passenger
            foreach ($confirmedBookings as $booking) {
                $this->notificationService->createNotification(
                    $booking->user,
                    'ride_completed',
                    'Ride Completed ✓  +10 pts',
                    "Your ride from {$ride->pickup_address} to {$ride->destination_address} is complete. "
                    . "+10 trust points added to your profile!",
                    ['ride_id' => $ride->id, 'booking_id' => $booking->id],
                    'normal', 'ride'
                );
            }

            Log::info('Ride fully completed', [
                'ride_id'     => $ride->id,
                'passengers'  => $totalPassengers,
                'earnings'    => $totalEarnings,
                'payment'     => $ride->payment_method,
            ]);
        });
    }

    // =========================================================================
    // REPORT DRIVER NO-SHOW  (passenger reports)
    // =========================================================================

    /**
     * Passenger reports the driver didn't appear at departure time.
     *
     * Wallet (E-PAY only):
     *   Admin escrow → 100% refund to passenger.
     *
     * Score — driver:
     *   −15 pts ALWAYS (both CASH and E-PAY rides).
     */
    public function reportDriverNoShow(int $rideId, User $passenger): array
    {
        return DB::transaction(function () use ($rideId, $passenger) {
            $ride = Ride::lockForUpdate()->findOrFail($rideId);

            if (now()->lessThan(Carbon::parse($ride->departure_time))) {
                throw new \InvalidArgumentException('Cannot report a no-show before the departure time');
            }

            // Passenger must have a confirmed booking on this ride
            $booking = $ride->bookings()
                ->where('user_id', $passenger->id)
                ->where('status', BookingStatus::CONFIRMED->value)
                ->lockForUpdate()
                ->first();

            if (!$booking) {
                throw new \InvalidArgumentException('No confirmed booking found for you on this ride');
            }

            $booking->status = Booking::NO_SHOW;
            $booking->save();

            // E-PAY: full refund from admin escrow → passenger wallet
            if ($ride->payment_method === PaymentMethod::E_PAY->value) {
                $this->walletService->processDriverNoShowRefund($ride, $booking, $passenger);
            }

            // Score: −15 to driver — always, regardless of payment method
            $this->scoreService->recordDriverNoShow($ride->driver, $ride);

            // Notify driver
            $refundNote = $ride->payment_method === PaymentMethod::E_PAY->value
                ? ' A full refund has been issued to the passenger.'
                : '';

            $this->notificationService->createNotification(
                $ride->driver,
                'driver_no_show_reported',
                'No-Show Reported Against You',
                "{$passenger->first_name} {$passenger->last_name} reported you as a no-show for the ride "
                . "from {$ride->pickup_address} to {$ride->destination_address}. "
                . "Your trust score has been penalised (−15 pts).{$refundNote}",
                ['ride_id' => $ride->id, 'booking_id' => $booking->id],
                'high', 'ride'
            );

            // Notify passenger if there is a refund
            if ($ride->payment_method === PaymentMethod::E_PAY->value) {
                $refundAmount = $booking->seats * $ride->price_per_seat;

                $this->notificationService->createNotification(
                    $passenger,
                    'driver_no_show_refund',
                    'Refund Issued ✓',
                    "Driver no-show confirmed. A full refund of "
                    . number_format($refundAmount, 0) . " SYP has been returned to your wallet.",
                    ['ride_id' => $ride->id, 'booking_id' => $booking->id, 'refund_amount' => $refundAmount],
                    'high', 'ride'
                );
            }

            Log::info('Driver no-show reported', [
                'ride_id'        => $ride->id,
                'passenger_id'   => $passenger->id,
                'payment_method' => $ride->payment_method,
            ]);

            return ['message' => 'Driver no-show reported. Refund (if applicable) has been processed.'];
        });
    }

    // =========================================================================
    // GETTERS
    // =========================================================================

    public function getRideById(int $rideId): Ride
    {
        return $this->rideRepository->getRideById($rideId);
    }

    public function getUserRides(int $userId): Collection
    {
        return $this->rideRepository->getDriverRides($userId);
    }

    public function getDriverRides(int $driverId): Collection
    {
        return $this->rideRepository->getDriverRides($driverId);
    }

    public function searchRides(array $criteria): Collection
    {
        return $this->rideRepository->searchRides($criteria);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Notify the driver and every confirmed passenger to confirm completion.
     */
    private function notifyAllForConfirmation(Ride $ride): void
    {
        // Notify driver
        $this->notificationService->createNotification(
            $ride->driver,
            'confirm_completion_needed',
            'Confirm Ride Completion',
            "Please confirm that the ride from {$ride->pickup_address} to {$ride->destination_address} "
            . "was completed to release your earnings and earn +10 trust points.",
            ['ride_id' => $ride->id],
            'high', 'ride'
        );

        // Notify every confirmed passenger
        $ride->bookings()
            ->where('status', BookingStatus::CONFIRMED->value)
            ->with('user')
            ->get()
            ->each(function (Booking $booking) use ($ride) {
                $this->notificationService->createNotification(
                    $booking->user,
                    'confirm_completion_needed',
                    'Did the Ride Happen?',
                    "Please confirm that the ride from {$ride->pickup_address} to "
                    . "{$ride->destination_address} was completed to earn +10 trust points.",
                    ['ride_id' => $ride->id, 'booking_id' => $booking->id],
                    'normal', 'ride'
                );
            });
    }

    /**
     * Notify each affected passenger when the driver cancels the entire ride.
     *
     * Confirmed + E-PAY: full wallet refund note.
     * Confirmed + CASH:  no wallet impact note.
     * Pending:           request cancelled, no payment taken.
     */
    private function notifyPassengersOnRideCancelled(
        Ride       $ride,
        Collection $confirmedBookings,
        Collection $pendingBookings
    ): void {
        $isEpay = $ride->payment_method === PaymentMethod::E_PAY->value;

        foreach ($confirmedBookings as $booking) {
            $refundAmount = $booking->seats * $ride->price_per_seat;
            $detail = $isEpay
                ? "A full refund of " . number_format($refundAmount, 0) . " SYP has been issued to your wallet."
                : "Cash ride — no wallet transaction needed.";

            $this->notificationService->createNotification(
                $booking->user,
                'ride_cancelled_by_driver',
                'Ride Cancelled by Driver',
                "The driver cancelled the ride from {$ride->pickup_address} to {$ride->destination_address}. {$detail}",
                [
                    'ride_id'      => $ride->id,
                    'booking_id'   => $booking->id,
                    'refund_amount'=> $isEpay ? $refundAmount : 0,
                ],
                'high', 'ride'
            );
        }

        foreach ($pendingBookings as $booking) {
            $this->notificationService->createNotification(
                $booking->user,
                'ride_cancelled_by_driver',
                'Ride Cancelled by Driver',
                "The ride from {$ride->pickup_address} to {$ride->destination_address} was cancelled. "
                . "Your pending booking request has been cancelled — no payment was taken.",
                ['ride_id' => $ride->id, 'booking_id' => $booking->id],
                'normal', 'ride'
            );
        }
    }
}
