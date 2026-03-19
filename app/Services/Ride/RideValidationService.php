<?php

namespace App\Services\Ride;

use App\Models\User;
use App\Services\Verification\DocumentVerificationService;
use Carbon\Carbon;

/**
 * Ride Validation Service
 *
 * All business rule violations throw \InvalidArgumentException so the
 * controller can map them cleanly to 422 responses.
 */
final class RideValidationService
{
    public function __construct(
        private readonly DocumentVerificationService $documentService
    ) {}

    /**
     * Validate driver can create rides
     *
     * @throws \InvalidArgumentException if validation fails
     */
    public function validateDriverCanCreateRide(User $driver): void
    {
        if (!$driver->is_verified_driver) {
            throw new \InvalidArgumentException('You must be verified as a driver to create rides');
        }

        if (!$driver->profile) {
            throw new \InvalidArgumentException('Driver profile not found. Please complete your profile.');
        }

        $this->documentService->validateDriverDocuments($driver);
    }

    /**
     * Validate passenger can book rides
     *
     * @throws \InvalidArgumentException if validation fails
     */
    public function validatePassengerCanBook(User $passenger): void
    {
        if (!$passenger->is_verified_passenger) {
            throw new \InvalidArgumentException('You must be verified as a passenger to book rides');
        }
    }

    /**
     * Validate departure time is acceptable
     *
     * @throws \InvalidArgumentException if departure time is invalid
     */
    public function validateDepartureTime(Carbon $departureTime): void
    {
        $now         = Carbon::now('Asia/Damascus');
        $minimumTime = $now->copy()->addMinutes(5);

        if ($departureTime->lte($minimumTime)) {
            throw new \InvalidArgumentException(
                'Departure time must be at least 5 minutes in the future. ' .
                'Current time: ' . $now->format('Y-m-d H:i:s') . ' (Damascus time)'
            );
        }

        $maxFutureTime = $now->copy()->addDays(30);
        if ($departureTime->gte($maxFutureTime)) {
            throw new \InvalidArgumentException(
                'Departure time cannot be more than 30 days in the future'
            );
        }
    }

    /**
     * Validate requested seats are available
     *
     * @throws \InvalidArgumentException if seat count is invalid
     */
    public function validateSeatsAvailable(int $requested, int $available): void
    {
        if ($requested < 1) {
            throw new \InvalidArgumentException('Must request at least 1 seat');
        }

        if ($requested > 8) {
            throw new \InvalidArgumentException('Cannot request more than 8 seats per booking');
        }

        if ($requested > $available) {
            throw new \InvalidArgumentException(
                "Not enough seats available. Requested: {$requested}, Available: {$available}"
            );
        }
    }

    /**
     * Validate ride can be cancelled
     *
     * @throws \InvalidArgumentException if ride cannot be cancelled
     */
    public function validateCanCancelRide(Carbon $departureTime): void
    {
        $now                = Carbon::now('Asia/Damascus');
        $timeUntilDeparture = $now->diffInHours($departureTime, false);

        if ($timeUntilDeparture < 1) {
            throw new \InvalidArgumentException(
                'Cannot cancel ride less than 1 hour before departure time'
            );
        }
    }

    /**
     * Validate booking can be cancelled
     *
     * @throws \InvalidArgumentException if booking cannot be cancelled
     */
    public function validateCanCancelBooking(Carbon $rideDepartureTime): void
    {
        $now                = Carbon::now('Asia/Damascus');
        $timeUntilDeparture = $now->diffInHours($rideDepartureTime, false);

        if ($timeUntilDeparture < 2) {
            throw new \InvalidArgumentException(
                'Cannot cancel booking less than 2 hours before departure time'
            );
        }
    }
}
