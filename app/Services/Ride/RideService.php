<?php

namespace App\Services\Ride;

use App\DTOs\Ride\CreateRideDTO;
use App\Domain\ValueObjects\Money;
use App\Enums\RideStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Events\RideCreated;
use App\Events\RideCancelled;
use App\Interfaces\RideRepositoryInterface;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Payment\WalletTransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection;

final class RideService
{
    public function __construct(
        private readonly RideRepositoryInterface  $rideRepository,
        private readonly RideValidationService    $validationService,
        private readonly RideSearchService        $searchService,
        private readonly WalletTransactionService $walletService,
        private readonly NotificationService      $notificationService,
    )
    {
    }

    // =========================================================================
    // CREATE RIDE
    // =========================================================================

    /**
     * Create a new ride and charge the driver creation fee.
     *
     * Payment flow:
     *   Driver wallet → SyCash wallet (5% of total ride value)
     */
    public function createRide(CreateRideDTO $dto, User $driver): Ride
    {
        $this->validationService->validateDriverCanCreateRide($driver);
        $this->validationService->validateDepartureTime($dto->departureTime);

        return DB::transaction(function () use ($dto, $driver) {
            // 1. Create ride first so walletService receives a proper Ride model
            $ride = $this->rideRepository->createRideWithGeometry($dto->toArray());

            // 2. Charge creation fee: Driver → SyCash
            //    WalletTransactionService handles balance check, transfer, and audit records
            $this->walletService->chargeRideCreationFee($ride, $driver);

            // 3. Notify driver
            $feeAmount = $dto->calculateRideCreationFee()->amount();
            $this->notificationService->createNotification(
                $driver,
                'ride_created',
                'Ride Created Successfully',
                "Your ride from {$ride->pickup_address} to {$ride->destination_address} has been created. " .
                "Creation fee charged: " . number_format($feeAmount, 0) . " SYP",
                [
                    'ride_id' => $ride->id,
                    'fee_charged' => $feeAmount,
                ],
                'normal',
                'ride'
            );

            broadcast(new RideCreated($ride));



            return $ride->fresh(['driver.profile']);
        });
    }

    // =========================================================================
    // CANCEL RIDE
    // =========================================================================

    /**
     * Driver cancels their own ride.
     *
     * Payment flow (e-pay confirmed bookings):
     *   Primary Admin wallet → each Passenger wallet (full refund per booking)
     *
     * Payment flow (always, regardless of bookings):
     *   SyCash wallet → Driver wallet (creation fee refund)
     */
    public function cancelRide(int $rideId, User $driver): Ride
    {
        return DB::transaction(function () use ($rideId, $driver) {
            $ride = $this->rideRepository->getRideById($rideId);

            if ($ride->driver_id !== $driver->id) {
                throw new \Exception('Only the ride creator can cancel it');
            }

            $rideStatus = RideStatus::from($ride->status);
            if ($rideStatus->isTerminal()) {
                throw new \Exception("Cannot cancel ride with status: {$rideStatus->label()}");
            }

            $this->validationService->validateCanCancelRide($ride->departure_time);

            // ------------------------------------------------------------------
            // 1. Collect ALL non-terminal bookings with their user relationships.
            // ------------------------------------------------------------------
            $affectedBookings = $ride->bookings()
                ->whereNotIn('status', [
                    BookingStatus::CANCELLED->value,
                    BookingStatus::COMPLETED->value,
                ])
                ->with('user')
                ->get();

            // ------------------------------------------------------------------
            // 2. Snapshot each booking's status BEFORE any mutations.
            //    (After $booking->save(), getOriginal() is synced to the new value.)
            // ------------------------------------------------------------------
            $originalStatuses = $affectedBookings->keyBy('id')
                ->map(fn($b) => $b->status);

            // ------------------------------------------------------------------
            // 3. Reconstruct the original seat count to calculate the correct
            //    creation fee refund amount.
            // ------------------------------------------------------------------
            $confirmedSeats = $affectedBookings
                ->filter(fn($b) => $originalStatuses[$b->id] === BookingStatus::CONFIRMED->value)
                ->sum('seats');

            $pendingSeats = $affectedBookings
                ->filter(fn($b) => $originalStatuses[$b->id] === BookingStatus::PENDING->value)
                ->sum('seats');

            $originalSeats = $ride->available_seats + $confirmedSeats + $pendingSeats;

            // ------------------------------------------------------------------
            // 4. Cancel the ride.
            // ------------------------------------------------------------------
            $ride->status = RideStatus::CANCELLED->value;
            $ride->save();

            // ------------------------------------------------------------------
            // 5. Cancel all affected bookings.
            // ------------------------------------------------------------------
            foreach ($affectedBookings as $booking) {
                $booking->status       = BookingStatus::CANCELLED->value;
                $booking->cancelled_at = now();
                $booking->save();
            }
            // ADD AFTER step 5 (after the foreach that cancels bookings)
            Log::error('CANCEL DEBUG', [
                'ride_id'             => $ride->id,
                'payment_method'      => $ride->payment_method,
                'epay_value'          => PaymentMethod::E_PAY->value,
                'affected_count'      => $affectedBookings->count(),
                'original_statuses'   => $originalStatuses->toArray(),
                'confirmed_value'     => BookingStatus::CONFIRMED->value,
                'admin_primary_phone' => config('admin.primary.phone'),
            ]);

            // ------------------------------------------------------------------
            // 6. Refund passengers — ONLY confirmed e-pay bookings.
            //    PENDING bookings were never charged (payment happens on acceptance).
            //    Money path: Primary Admin wallet → each Passenger wallet
            // ------------------------------------------------------------------
            if ($ride->payment_method === PaymentMethod::E_PAY->value) {
                $confirmedBookings = $affectedBookings->filter(
                    fn($b) => $originalStatuses[$b->id] === BookingStatus::CONFIRMED->value
                );

                if ($confirmedBookings->isNotEmpty()) {
                    $this->walletService->refundPassengersForDriverCancellation(
                        $ride,
                        $confirmedBookings
                    );
                }
            }

            // ------------------------------------------------------------------
            // 7. Always refund the driver's creation fee.
            //    Money path: SyCash wallet → Driver wallet
            // ------------------------------------------------------------------
            $this->walletService->refundDriverCreationFeeOnCancellation($ride, $originalSeats);

            // ------------------------------------------------------------------
            // 8. Notify passengers and driver.
            // ------------------------------------------------------------------
            $this->notifyRideCancellation($ride, $affectedBookings, $originalStatuses);

            $this->notificationService->createNotification(
                $driver,
                'ride_cancelled_fee_refunded',
                'Ride Cancelled — Creation Fee Refunded',
                "Your ride from {$ride->pickup_address} to {$ride->destination_address} has been cancelled. " .
                "Your creation fee has been refunded to your wallet.",
                [
                    'ride_id'             => $ride->id,
                    'passengers_refunded' => $affectedBookings->count(),
                    'original_seats'      => $originalSeats,
                ],
                'normal',
                'ride'
            );

            broadcast(new RideCancelled($ride, $affectedBookings->toArray(), $driver));

            Log::info('Ride cancelled by driver', [
                'ride_id'                   => $ride->id,
                'driver_id'                 => $driver->id,
                'original_seats'            => $originalSeats,
                'confirmed_seats'           => $confirmedSeats,
                'pending_seats'             => $pendingSeats,
                'available_seats_at_cancel' => $ride->available_seats,
                'affected_bookings'         => $affectedBookings->count(),
            ]);

            return $ride->fresh();
        });
    }

    // =========================================================================
    // FINISH RIDE
    // =========================================================================

    /**
     * Driver marks ride as finished.
     *
     * Two paths:
     *
     * A) No confirmed bookings:
     *    SyCash → Driver (creation fee refunded immediately)
     *    Ride status → FINISHED
     *
     * B) Has confirmed bookings:
     *    Ride status → AWAITING_CONFIRMATION
     *    Notifications sent to driver + all confirmed passengers
     *    Payment released only after ALL parties confirm (see checkAndCompleteRide)
     */
    public function finishRide(int $rideId, User $driver): array
    {
        $ride = $this->rideRepository->getRideById($rideId);

        if ($ride->driver_id !== $driver->id) {
            throw new \Exception('Only the ride driver can finish it');
        }

        if (!in_array($ride->status, [RideStatus::ACTIVE->value, RideStatus::FULL->value])) {
            throw new \Exception('Can only finish active or full rides');
        }

        if (now()->lessThan(\Carbon\Carbon::parse($ride->departure_time))) {
            throw new \Exception('Cannot finish a ride before its departure time');
        }

        $confirmedBookings = $ride->bookings()
            ->where('status', BookingStatus::CONFIRMED->value)
            ->get();

        // Path A: No bookings — refund fee and finish immediately
        if ($confirmedBookings->isEmpty()) {
            return DB::transaction(function () use ($ride) {
                $this->walletService->refundCreationFeeNoBookings($ride);

                $ride->status = RideStatus::FINISHED->value;
                $ride->finished_at = now();
                $ride->save();

                Log::info('Ride finished with no bookings — creation fee refunded', [
                    'ride_id' => $ride->id,
                    'driver_id' => $ride->driver_id,
                ]);

                return [
                    'status' => RideStatus::FINISHED->value,
                    'message' => 'Ride finished. No passengers booked — creation fee refunded.',
                    'requires_confirmation' => false,
                ];
            });
        }

        // Path B: Has bookings — enter confirmation state
        return DB::transaction(function () use ($ride) {
            $ride->status = RideStatus::AWAITING_CONFIRMATION->value;
            $ride->finished_at = now();
            $ride->save();

            $this->notifyForConfirmation($ride);

            Log::info('Ride set to awaiting_confirmation', [
                'ride_id' => $ride->id,
                'driver_id' => $ride->driver_id,
            ]);

            return [
                'status' => RideStatus::AWAITING_CONFIRMATION->value,
                'message' => 'Ride completed. Waiting for all parties to confirm to release payment.',
                'requires_confirmation' => true,
            ];
        });
    }

    // =========================================================================
    // DRIVER CONFIRMS COMPLETION
    // =========================================================================

    /**
     * Driver confirms the ride was completed.
     * Sets driver_confirmed_at timestamp and checks if all parties have confirmed.
     */
    public function driverConfirmCompletion(int $rideId, User $driver): array
    {
        $ride = $this->rideRepository->getRideById($rideId);

        if ($ride->driver_id !== $driver->id) {
            throw new \Exception('Only the ride driver can confirm completion');
        }

        if ($ride->status !== RideStatus::AWAITING_CONFIRMATION->value) {
            throw new \Exception('Ride is not awaiting confirmation');
        }

        DB::transaction(function () use ($ride) {
            $ride->driver_confirmed_at = now();
            $ride->save();
        });

        $this->checkAndCompleteRide($ride->fresh());

        Log::info('Driver confirmed ride completion', [
            'ride_id' => $rideId,
            'driver_id' => $driver->id,
        ]);

        return ['message' => 'Driver confirmation received. Waiting for passenger confirmations.'];
    }

    // =========================================================================
    // CHECK AND COMPLETE RIDE (called after every confirmation)
    // =========================================================================

    /**
     * Check if all parties have confirmed.
     * If yes: release escrowed payment and mark ride FINISHED.
     *
     * Payment flow (e-pay):
     *   Primary Admin wallet → Driver wallet (total of all confirmed booking amounts)
     *
     * Called by:
     *   - driverConfirmCompletion()
     *   - BookingService::passengerConfirmCompletion()
     */
    public function checkAndCompleteRide(Ride $ride): void
    {
        $confirmedBookings = $ride->bookings()
            ->where('status', BookingStatus::CONFIRMED->value)
            ->get();

        $totalPassengers = $confirmedBookings->count();
        $passengersConfirmed = $confirmedBookings->whereNotNull('passenger_confirmed_at')->count();

        // Not all parties confirmed yet — do nothing
        if (!$ride->driver_confirmed_at || $passengersConfirmed < $totalPassengers) {
            return;
        }

        DB::transaction(function () use ($ride, $confirmedBookings) {
            // Release escrow to driver for e-pay rides
            if ($ride->payment_method === PaymentMethod::E_PAY->value
                && $confirmedBookings->isNotEmpty()
            ) {
                $this->walletService->releaseEarningsToDriver($ride, $confirmedBookings);
            }

            // Mark ride FINISHED
            $ride->status = RideStatus::FINISHED->value;
            $ride->passengers_confirmed = true;
            $ride->save();

            // Mark all confirmed bookings as completed
            $ride->bookings()
                ->where('status', BookingStatus::CONFIRMED->value)
                ->update([
                    'status' => BookingStatus::COMPLETED->value,
                    'completed_at' => now(),
                ]);
        });

        // Notify driver about payment release
        $totalEarnings = $confirmedBookings->sum(fn($b) => $b->seats * $ride->price_per_seat);
        $this->notificationService->createNotification(
            $ride->driver,
            'ride_completed_earnings',
            'Ride Completed — Payment Released',
            "Payment of " . number_format($totalEarnings, 0) . " SYP has been released to your wallet " .
            "for the ride from {$ride->pickup_address} to {$ride->destination_address}.",
            [
                'ride_id' => $ride->id,
                'total_earnings' => $totalEarnings,
            ],
            'high',
            'ride'
        );

        // Notify each passenger that ride is complete
        foreach ($confirmedBookings as $booking) {
            $this->notificationService->createNotification(
                $booking->user,
                'ride_completed',
                'Ride Completed',
                "The ride from {$ride->pickup_address} to {$ride->destination_address} " .
                "has been completed and payment released to the driver.",
                ['ride_id' => $ride->id, 'booking_id' => $booking->id],
                'normal',
                'ride'
            );
        }

        Log::info('Ride fully completed — payments released', [
            'ride_id' => $ride->id,
            'driver_id' => $ride->driver_id,
            'total_earnings' => $totalEarnings,
            'bookings_count' => $confirmedBookings->count(),
        ]);
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
     * Send confirmation-needed notifications to driver and all confirmed passengers.
     */
    private function notifyForConfirmation(Ride $ride): void
    {
        $this->notificationService->createNotification(
            $ride->driver,
            'driver_confirmation_needed',
            'Confirm Ride Completion',
            "Please confirm you have completed the ride from {$ride->pickup_address} " .
            "to {$ride->destination_address} to release payment to your wallet.",
            ['ride_id' => $ride->id],
            'high',
            'ride'
        );

        $confirmedBookings = $ride->bookings()
            ->where('status', BookingStatus::CONFIRMED->value)
            ->with('user')
            ->get();

        foreach ($confirmedBookings as $booking) {
            $this->notificationService->createNotification(
                $booking->user,
                'passenger_confirmation_needed',
                'Confirm Ride Completion',
                "Please confirm that the ride from {$ride->pickup_address} to " .
                "{$ride->destination_address} was completed to release payment to the driver.",
                ['ride_id' => $ride->id, 'booking_id' => $booking->id],
                'normal',
                'ride'
            );
        }
    }

    /**
     * Notify all affected passengers that the driver cancelled the ride.
     * Includes refund information for e-pay bookings.
     */
    private function notifyRideCancellation(
        Ride       $ride,
        Collection $bookings,
        \Illuminate\Support\Collection $originalStatuses
    ): void {
        foreach ($bookings as $booking) {
            $wasConfirmed = $originalStatuses[$booking->id] === BookingStatus::CONFIRMED->value;
            $isEpay       = $ride->payment_method === PaymentMethod::E_PAY->value;
            $hasRefund    = $wasConfirmed && $isEpay;
            $refundAmount = $hasRefund ? ($booking->seats * $ride->price_per_seat) : 0;

            if ($hasRefund) {
                $title   = 'Ride Cancelled — Full Refund';
                $message = "The ride from {$ride->pickup_address} to {$ride->destination_address} "
                    . "has been cancelled by the driver. "
                    . "A full refund of " . number_format($refundAmount, 0) . " SYP has been issued to your wallet.";
            } elseif ($wasConfirmed) {
                // Confirmed cash booking — nothing to refund via wallet
                $title   = 'Ride Cancelled';
                $message = "The ride from {$ride->pickup_address} to {$ride->destination_address} "
                    . "has been cancelled by the driver. "
                    . "As this was a cash booking, no wallet transaction is needed.";
            } else {
                // Pending (request-type) booking — passenger was never charged
                $title   = 'Ride Cancelled';
                $message = "The ride from {$ride->pickup_address} to {$ride->destination_address} "
                    . "has been cancelled by the driver. "
                    . "Your booking request has been cancelled. No payment was taken.";
            }

            $this->notificationService->createNotification(
                $booking->user,
                'ride_cancelled',
                $title,
                $message,
                [
                    'ride_id'        => $ride->id,
                    'booking_id'     => $booking->id,
                    'refund_amount'  => $refundAmount,
                    'booking_status' => $originalStatuses[$booking->id],
                ],
                'high',
                'ride'
            );
        }
    }


}
