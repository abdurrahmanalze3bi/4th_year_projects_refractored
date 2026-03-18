<?php

namespace App\Services\Ride;

use App\Models\User;
use App\Services\Verification\DocumentVerificationService;
use Carbon\Carbon;

/**
 * Ride Validation Service
 *
 * FIXED: Simplified - document verification delegated to DocumentVerificationService
 *
 * Responsibilities:
 * - Validate ride creation eligibility
 * - Validate departure time
 * - Validate seat availability
 */
final class RideValidationService
{
    public function __construct(
        private readonly DocumentVerificationService $documentService
    ) {}

    /**
     * Validate driver can create rides
     *
     * @throws \Exception if validation fails
     */
    public function validateDriverCanCreateRide(User $driver): void
    {
        // Check verified status
        if (!$driver->is_verified_driver) {
            throw new \Exception('You must be verified as a driver to create rides');
        }

        // Check profile exists
        if (!$driver->profile) {
            throw new \Exception('Driver profile not found. Please complete your profile.');
        }

        // Delegate document validation to document service
        $this->documentService->validateDriverDocuments($driver);
    }

    /**
     * Validate passenger can book rides
     *
     * @throws \Exception if validation fails
     */
    public function validatePassengerCanBook(User $passenger): void
    {
        if (!$passenger->is_verified_passenger) {
            throw new \Exception('You must be verified as a passenger to book rides');
        }

        // Optionally validate passenger documents
        // $this->documentService->validatePassengerDocuments($passenger);
    }

    /**
     * Validate departure time is acceptable
     *
     * @throws \Exception if departure time is too soon or in the past
     */
    public function validateDepartureTime(Carbon $departureTime): void
    {
        $now = Carbon::now('Asia/Damascus');
        $minimumTime = $now->copy()->addMinutes(5);

        if ($departureTime->lte($minimumTime)) {
            throw new \Exception(
                'Departure time must be at least 5 minutes in the future. ' .
                'Current time: ' . $now->format('Y-m-d H:i:s') . ' (Damascus time)'
            );
        }

        // Optional: Check not too far in future (e.g., max 30 days)
        $maxFutureTime = $now->copy()->addDays(30);
        if ($departureTime->gte($maxFutureTime)) {
            throw new \Exception(
                'Departure time cannot be more than 30 days in the future'
            );
        }
    }

    /**
     * Validate requested seats are available
     *
     * @throws \Exception if not enough seats available
     */
    public function validateSeatsAvailable(int $requested, int $available): void
    {
        if ($requested > $available) {
            throw new \Exception(
                "Not enough seats available. Requested: {$requested}, Available: {$available}"
            );
        }

        if ($requested < 1) {
            throw new \Exception('Must request at least 1 seat');
        }

        if ($requested > 8) {
            throw new \Exception('Cannot request more than 8 seats per booking');
        }
    }

    /**
     * Validate ride can be cancelled
     *
     * @throws \Exception if ride cannot be cancelled
     */
    public function validateCanCancelRide(Carbon $departureTime): void
    {
        $now = Carbon::now('Asia/Damascus');
        $timeUntilDeparture = $now->diffInHours($departureTime, false);

        // Can't cancel if departure is less than 1 hour away
        if ($timeUntilDeparture < 1) {
            throw new \Exception(
                'Cannot cancel ride less than 1 hour before departure time'
            );
        }
    }

    /**
     * Validate booking can be cancelled
     *
     * @throws \Exception if booking cannot be cancelled
     */
    public function validateCanCancelBooking(Carbon $rideDepartureTime): void
    {
        $now = Carbon::now('Asia/Damascus');
        $timeUntilDeparture = $now->diffInHours($rideDepartureTime, false);

        // Can't cancel if departure is less than 2 hours away
        if ($timeUntilDeparture < 2) {
            throw new \Exception(
                'Cannot cancel booking less than 2 hours before departure time'
            );
        }
    }
}
