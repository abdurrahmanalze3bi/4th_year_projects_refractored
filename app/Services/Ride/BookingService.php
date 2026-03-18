<?php

namespace App\Services\Ride;

use App\Domain\ValueObjects\PhoneNumber;
use App\DTOs\Ride\BookRideDTO;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\RideStatus;
use App\Enums\PaymentMethod;
use App\Events\RideBooked;
use App\Interfaces\RideRepositoryInterface;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Payment\WalletTransactionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection;

final class BookingService
{
    public function __construct(
        private readonly RideRepositoryInterface  $rideRepository,
        private readonly WalletTransactionService $walletService,
        private readonly NotificationService      $notificationService,
        private readonly RideValidationService    $validationService,
    ) {}

    // =========================================================================
    // BOOK RIDE
    // =========================================================================

    /**
     * Book a ride.
     *
     * Payment flow (e-pay, direct booking):
     *   Passenger wallet → Primary Admin wallet (escrow)
     *
     * Payment flow (e-pay, request booking):
     *   Payment is deferred until driver accepts.
     */
    public function bookRide(BookRideDTO $dto, User $passenger): Booking
    {
        $this->validationService->validatePassengerCanBook($passenger);

        $cacheKey = "booking:idempotency:{$dto->idempotencyKey}";

        if ($existingBookingId = Cache::get($cacheKey)) {
            Log::info('Duplicate booking attempt detected', [
                'idempotency_key'     => $dto->idempotencyKey,
                'existing_booking_id' => $existingBookingId,
            ]);
            /** @var Booking $existing */
            $existing = Booking::with(['ride', 'user'])->findOrFail($existingBookingId);
            return $existing;
        }

        return DB::transaction(function () use ($dto, $passenger, $cacheKey) {
            $ride = $this->rideRepository->getRideById($dto->rideId);

            $this->validateBooking($dto, $ride, $passenger);

            $bookingType = BookingType::from($ride->booking_type);
            $status      = $bookingType->initialBookingStatus();

            /** @var Booking $booking */
            $booking = Booking::create([
                'user_id'              => $dto->passengerId,
                'ride_id'              => $dto->rideId,
                'seats'                => $dto->seats,
                'status'               => $status->value,
                'communication_number' => $dto->communicationNumber->number(),
            ]);

            // Charge passenger immediately for direct e-pay bookings only.
            // Request-type bookings are charged when the driver accepts.
            if ($status === BookingStatus::CONFIRMED
                && $ride->payment_method === PaymentMethod::E_PAY->value
            ) {
                $this->walletService->chargePassengerForBooking($booking, $ride, $passenger);
            }

            if ($status === BookingStatus::CONFIRMED) {
                $this->updateRideSeats($ride, $dto->seats);
            }

            Cache::put($cacheKey, $booking->id, 86400);

            $this->notifyBookingCreated($booking, $ride, $passenger, $bookingType);

            broadcast(new RideBooked($ride, $booking, $passenger));

            Log::info('Ride booked successfully', [
                'ride_id'      => $ride->id,
                'booking_id'   => $booking->id,
                'passenger_id' => $passenger->id,
                'seats'        => $dto->seats,
                'status'       => $status->value,
                'payment'      => $ride->payment_method,
            ]);

            return $booking->refresh();
        });
    }

    // =========================================================================
    // ACCEPT BOOKING (driver action — request-type rides)
    // =========================================================================

    /**
     * Driver accepts a pending booking request.
     *
     * Payment flow (e-pay):
     *   Passenger wallet → Primary Admin wallet (escrow)
     *   (deferred from booking time until acceptance)
     */
    public function acceptBooking(int $bookingId, User $driver): Booking
    {
        return DB::transaction(function () use ($bookingId, $driver) {
            /** @var Booking $booking */
            $booking = Booking::with(['ride', 'user'])->lockForUpdate()->findOrFail($bookingId);
            $ride    = $booking->ride;

            if ($ride->driver_id !== $driver->id) {
                throw new \Exception('Only the ride driver can accept bookings');
            }
            if ($ride->booking_type !== BookingType::REQUEST->value) {
                throw new \Exception('Only request-type bookings can be accepted');
            }
            if ($booking->status !== BookingStatus::PENDING->value) {
                throw new \Exception('Booking has already been processed');
            }
            if ($ride->available_seats < $booking->seats) {
                throw new \Exception('Not enough available seats');
            }

            $booking->status = BookingStatus::CONFIRMED->value;
            $booking->save();

            // Charge passenger now that driver accepted
            if ($ride->payment_method === PaymentMethod::E_PAY->value) {
                $this->walletService->chargePassengerForBooking($booking, $ride, $booking->user);
            }

            $this->updateRideSeats($ride, $booking->seats);

            $this->notificationService->createNotification(
                $booking->user,
                'booking_accepted',
                'Booking Accepted',
                "Your booking request for {$booking->seats} seat(s) has been accepted",
                ['booking_id' => $booking->id, 'ride_id' => $ride->id],
                'high',
                'ride'
            );

            Log::info('Booking accepted by driver', [
                'booking_id' => $booking->id,
                'driver_id'  => $driver->id,
                'ride_id'    => $ride->id,
            ]);

            return $booking->refresh();
        });
    }

    // =========================================================================
    // REJECT BOOKING (driver action — request-type rides)
    // =========================================================================

    /**
     * Driver rejects a pending booking request.
     * No payment was taken for pending bookings — no refund needed.
     */
    public function rejectBooking(int $bookingId, User $driver): Booking
    {
        return DB::transaction(function () use ($bookingId, $driver) {
            /** @var Booking $booking */
            $booking = Booking::with('ride')->lockForUpdate()->findOrFail($bookingId);
            $ride    = $booking->ride;

            if ($ride->driver_id !== $driver->id) {
                throw new \Exception('Only the ride driver can reject bookings');
            }
            if ($ride->booking_type !== BookingType::REQUEST->value) {
                throw new \Exception('Only request-type bookings can be rejected');
            }
            if ($booking->status !== BookingStatus::PENDING->value) {
                throw new \Exception('Booking has already been processed');
            }

            $booking->status = BookingStatus::CANCELLED->value;
            $booking->save();

            $this->notificationService->createNotification(
                $booking->user,
                'booking_rejected',
                'Booking Rejected',
                'Your booking request was rejected by the driver',
                ['booking_id' => $booking->id, 'ride_id' => $ride->id],
                'normal',
                'ride'
            );

            Log::info('Booking rejected by driver', [
                'booking_id' => $booking->id,
                'driver_id'  => $driver->id,
                'ride_id'    => $ride->id,
            ]);

            return $booking->refresh();
        });
    }

    // =========================================================================
    // CANCEL FULL BOOKING (passenger action)
    // =========================================================================

    /**
     * Passenger cancels their entire booking.
     *
     * Payment flow (confirmed e-pay):
     *   Primary Admin → Passenger (refund % based on time elapsed)
     *   Primary Admin → Driver   (non-refundable % based on time elapsed)
     *
     * Refund tiers:
     *   0–30%  elapsed → 100% refund to passenger
     *   30–50% elapsed →  70% refund to passenger
     *   50–70% elapsed →  50% refund to passenger
     *   70–100% elapsed →  0% refund (all to driver)
     */
    public function cancelBooking(int $bookingId, User $passenger): Booking
    {
        return DB::transaction(function () use ($bookingId, $passenger) {
            /** @var Booking $booking */
            $booking = Booking::with('ride')->lockForUpdate()->findOrFail($bookingId);
            $ride    = $booking->ride;

            if ($booking->user_id !== $passenger->id) {
                throw new \Exception('You can only cancel your own bookings');
            }

            $status = BookingStatus::from($booking->status);
            if (!$status->canBeCancelled()) {
                throw new \Exception("Cannot cancel booking with status: {$status->label()}");
            }

            $booking->status       = BookingStatus::CANCELLED->value;
            $booking->cancelled_at = now();
            $booking->save();

            // Process time-based refund for confirmed e-pay bookings
            if ($status === BookingStatus::CONFIRMED
                && $ride->payment_method === PaymentMethod::E_PAY->value
            ) {
                $refundPolicy = $this->walletService->calculateRefundPolicy(
                    \Carbon\Carbon::parse($ride->departure_time),
                    $booking->created_at
                );

                $this->walletService->processTimeBasedCancellation(
                    $booking,
                    $ride,
                    $booking->seats,
                    $refundPolicy
                );

                $this->sendCancellationNotification($booking, $ride, $booking->seats, $refundPolicy);
            }

            // Restore seats on the ride
            if ($status === BookingStatus::CONFIRMED) {
                $ride->increment('available_seats', $booking->seats);

                if ($ride->status === RideStatus::FULL->value) {
                    $ride->status = RideStatus::ACTIVE->value;
                    $ride->save();
                }
            }

            Log::info('Booking cancelled by passenger', [
                'booking_id'   => $booking->id,
                'passenger_id' => $passenger->id,
                'seats'        => $booking->seats,
            ]);

            return $booking->refresh();
        });
    }

    // =========================================================================
    // CANCEL PARTIAL SEATS (passenger action)
    // =========================================================================

    /**
     * Passenger cancels a subset of their booked seats.
     *
     * Payment flow (confirmed e-pay):
     *   Primary Admin → Passenger (refund % × seats cancelled)
     *   Primary Admin → Driver   (non-refundable % × seats cancelled)
     *
     * If remaining seats > 0: booking stays active with reduced seat count.
     * If remaining seats = 0: booking is fully cancelled.
     */
    public function cancelPartialSeats(int $bookingId, int $seatsToCancel, User $passenger): array
    {
        return DB::transaction(function () use ($bookingId, $seatsToCancel, $passenger) {
            /** @var Booking $booking */
            $booking = Booking::with(['ride', 'user'])->lockForUpdate()->findOrFail($bookingId);
            $ride    = $booking->ride;

            if ($booking->user_id !== $passenger->id) {
                throw new \InvalidArgumentException('You can only cancel your own bookings');
            }
            if (!in_array($booking->status, [BookingStatus::PENDING->value, BookingStatus::CONFIRMED->value])) {
                throw new \InvalidArgumentException('This booking cannot be cancelled');
            }
            if ($seatsToCancel > $booking->seats) {
                throw new \InvalidArgumentException(
                    "Cannot cancel {$seatsToCancel} seats. You only have {$booking->seats} seats booked."
                );
            }

            $refundPolicy   = $this->walletService->calculateRefundPolicy(
                \Carbon\Carbon::parse($ride->departure_time),
                $booking->created_at
            );
            $totalPaid      = $seatsToCancel * $ride->price_per_seat;
            $refundAmount   = ($totalPaid * $refundPolicy['refund_percentage']) / 100;
            $remainingSeats = $booking->seats - $seatsToCancel;

            // Process money redistribution for confirmed e-pay bookings
            if ($booking->status === BookingStatus::CONFIRMED->value
                && $ride->payment_method === PaymentMethod::E_PAY->value
            ) {
                $this->walletService->processTimeBasedCancellation(
                    $booking,
                    $ride,
                    $seatsToCancel,
                    $refundPolicy
                );
            }

            // Update booking record
            if ($remainingSeats > 0) {
                $booking->seats = $remainingSeats;
                $booking->save();
                $message = "Cancelled {$seatsToCancel} seat(s). You still have {$remainingSeats} seat(s) booked.";
            } else {
                $booking->status       = BookingStatus::CANCELLED->value;
                $booking->cancelled_at = now();
                $booking->save();
                $message = 'All seats cancelled. Your booking has been cancelled.';
            }

            // Restore seats on the ride
            $ride->increment('available_seats', $seatsToCancel);

            if ($ride->status === RideStatus::FULL->value) {
                $ride->status = RideStatus::ACTIVE->value;
                $ride->save();
            }

            $this->sendCancellationNotification($booking, $ride, $seatsToCancel, $refundPolicy);

            Log::info('Partial seat cancellation completed', [
                'booking_id'      => $booking->id,
                'seats_cancelled' => $seatsToCancel,
                'remaining_seats' => $remainingSeats,
                'refund_amount'   => $refundAmount,
                'policy_tier'     => $refundPolicy['policy_tier'],
            ]);

            return [
                'message' => $message,
                'data'    => [
                    'booking_id'      => $booking->id,
                    'seats_cancelled' => $seatsToCancel,
                    'remaining_seats' => $remainingSeats,
                    'refund_policy'   => [
                        'refund_percentage'       => $refundPolicy['refund_percentage'],
                        'refund_amount'           => $refundAmount,
                        'non_refundable_amount'   => $totalPaid - $refundAmount,
                        'time_elapsed_percentage' => round($refundPolicy['time_elapsed_percentage'], 2),
                        'policy_tier'             => $refundPolicy['policy_tier'],
                    ],
                    'booking_status' => $booking->status,
                ],
            ];
        });
    }

    // =========================================================================
    // PASSENGER CONFIRMS RIDE COMPLETION
    // =========================================================================

    /**
     * Passenger confirms the ride was completed.
     * Once ALL passengers and the driver have confirmed, RideService releases payment.
     */
    public function passengerConfirmCompletion(int $bookingId, User $passenger): array
    {
        /** @var Booking $booking */
        $booking = Booking::with('ride')->findOrFail($bookingId);
        $ride    = $booking->ride;

        if ($booking->user_id !== $passenger->id) {
            throw new \Exception('Only the booking passenger can confirm completion');
        }
        if ($ride->status !== RideStatus::AWAITING_CONFIRMATION->value) {
            throw new \Exception('Ride is not awaiting confirmation');
        }

        DB::transaction(function () use ($booking) {
            $booking->passenger_confirmed_at = now();
            $booking->save();
        });

        // Trigger completion check — releases payment if all parties confirmed
        app(RideService::class)->checkAndCompleteRide($ride->fresh());

        Log::info('Passenger confirmed ride completion', [
            'booking_id'   => $booking->id,
            'passenger_id' => $passenger->id,
            'ride_id'      => $ride->id,
        ]);

        return ['message' => 'Confirmation received. Waiting for all parties to confirm.'];
    }

    // =========================================================================
    // GETTERS
    // =========================================================================

    public function getUserBookings(int $userId): Collection
    {
        return Booking::with(['ride', 'ride.driver', 'ride.driver.profile'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function validateBooking(BookRideDTO $dto, Ride $ride, User $passenger): void
    {
        if ($ride->driver_id === $passenger->id) {
            throw new \Exception('Drivers cannot book their own rides');
        }

        $this->validationService->validateSeatsAvailable($dto->seats, $ride->available_seats);

        $alreadyBooked = Booking::where('user_id', $passenger->id)
            ->where('ride_id', $ride->id)
            ->whereIn('status', BookingStatus::activeStatuses())
            ->exists();

        if ($alreadyBooked) {
            throw new \Exception('You already have an active booking for this ride');
        }

        $rideStatus = RideStatus::from($ride->status);
        if (!$rideStatus->canBeBooked()) {
            throw new \Exception("This ride is not available for booking (status: {$rideStatus->label()})");
        }
    }

    private function updateRideSeats(Ride $ride, int $seats): void
    {
        $ride->decrement('available_seats', $seats);
        $ride->refresh();

        if ($ride->available_seats <= 0) {
            $ride->status = RideStatus::FULL->value;
            $ride->save();

            Log::info('Ride marked as full', ['ride_id' => $ride->id]);
        }
    }

    private function notifyBookingCreated(
        Booking     $booking,
        Ride        $ride,
        User        $passenger,
        BookingType $bookingType
    ): void {
        $isDirect = $bookingType === BookingType::DIRECT;

        // Notify driver
        $this->notificationService->createNotification(
            $ride->driver,
            $isDirect ? 'ride_booked' : 'booking_requested',
            $isDirect ? 'New Ride Booking' : 'New Booking Request',
            "{$passenger->first_name} {$passenger->last_name} has " .
            ($isDirect ? 'booked' : 'requested') .
            " {$booking->seats} seat(s) for your ride",
            [
                'ride_id'      => $ride->id,
                'booking_id'   => $booking->id,
                'passenger_id' => $passenger->id,
                'seats'        => $booking->seats,
            ],
            'high',
            'ride'
        );

        // Notify passenger
        $this->notificationService->createNotification(
            $passenger,
            $isDirect ? 'booking_confirmed' : 'booking_requested',
            $isDirect ? 'Booking Confirmed' : 'Request Sent',
            $isDirect
                ? "Your booking for {$booking->seats} seat(s) has been confirmed"
                : "Your booking request has been sent. Waiting for driver approval",
            [
                'ride_id'    => $ride->id,
                'booking_id' => $booking->id,
                'seats'      => $booking->seats,
            ],
            'normal',
            'ride'
        );
    }

    private function sendCancellationNotification(
        Booking $booking,
        Ride    $ride,
        int     $seatsCancelled,
        array   $refundPolicy
    ): void {
        $totalPaid      = $seatsCancelled * $ride->price_per_seat;
        $refundAmount   = ($totalPaid * $refundPolicy['refund_percentage']) / 100;
        $driverAmount   = $totalPaid - $refundAmount;
        $remainingSeats = $booking->seats; // already updated at this point

        // Passenger notification
        $passengerMsg = $remainingSeats > 0
            ? "Cancelled {$seatsCancelled} seat(s). You still have {$remainingSeats} seat(s) booked. "
            : "Your booking has been fully cancelled. ";

        $passengerMsg .= $refundAmount > 0
            ? "Refund of " . number_format($refundAmount, 0) . " SYP ({$refundPolicy['refund_percentage']}%) has been processed."
            : "No refund issued ({$refundPolicy['policy_tier']}).";

        $this->notificationService->createNotification(
            $booking->user,
            'booking_partial_cancellation',
            'Seats Cancelled',
            $passengerMsg,
            [
                'booking_id'              => $booking->id,
                'seats_cancelled'         => $seatsCancelled,
                'refund_amount'           => $refundAmount,
                'time_elapsed_percentage' => round($refundPolicy['time_elapsed_percentage'], 2),
                'policy_tier'             => $refundPolicy['policy_tier'],
            ],
            'normal',
            'ride'
        );

        // Driver notification
        $driverMsg = $driverAmount > 0
            ? "A passenger cancelled {$seatsCancelled} seat(s) on your ride. " .
            "You received " . number_format($driverAmount, 0) . " SYP cancellation fee ({$refundPolicy['policy_tier']})."
            : "A passenger cancelled {$seatsCancelled} seat(s). Full refund was issued to the passenger.";

        $this->notificationService->createNotification(
            $ride->driver,
            'passenger_cancellation_earnings',
            'Passenger Cancelled Seats',
            $driverMsg,
            [
                'booking_id'            => $booking->id,
                'seats_cancelled'       => $seatsCancelled,
                'cancellation_earnings' => $driverAmount,
                'policy_tier'           => $refundPolicy['policy_tier'],
            ],
            'normal',
            'ride'
        );
    }
}
