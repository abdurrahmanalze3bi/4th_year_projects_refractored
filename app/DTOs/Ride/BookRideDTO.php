<?php

namespace App\DTOs\Ride;

use App\Domain\ValueObjects\PhoneNumber;
use App\Enums\BookingStatus;

/**
 * Data Transfer Object for ride booking
 *
 * FIXED: Added idempotency key to prevent duplicate bookings
 */
final class BookRideDTO
{
    public function __construct(
        public readonly int $passengerId,
        public readonly int $rideId,
        public readonly int $seats,
        public readonly PhoneNumber $communicationNumber,
        public readonly string $idempotencyKey,
        public readonly ?BookingStatus $status = null,
    ) {}

    /**
     * Create DTO from validated request data
     */
    public static function fromRequest(array $validated, int $userId, int $rideId): self
    {
        return new self(
            passengerId: $userId,
            rideId: $rideId,
            seats: $validated['seats'],
            communicationNumber: PhoneNumber::from($validated['communication_number']),
            idempotencyKey: $validated['idempotency_key'],
            status: isset($validated['status']) ? BookingStatus::from($validated['status']) : null,
        );
    }

    /**
     * Convert DTO to array for repository
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->passengerId,
            'ride_id' => $this->rideId,
            'seats' => $this->seats,
            'communication_number' => $this->communicationNumber->number(),
            'status' => $this->status?->value,
        ];
    }
}
