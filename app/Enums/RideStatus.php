<?php

namespace App\Enums;

/**
 * Ride Status Enum
 *
 * Eliminates magic strings throughout the codebase
 * Provides type safety and IDE autocomplete
 */
enum RideStatus: string
{
    case ACTIVE = 'active';
    case FULL = 'full';
    case CANCELLED = 'cancelled';
    case FINISHED = 'finished';
    case AWAITING_CONFIRMATION = 'awaiting_confirmation';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match($this) {
            self::ACTIVE => 'Active',
            self::FULL => 'Full',
            self::CANCELLED => 'Cancelled',
            self::FINISHED => 'Finished',
            self::AWAITING_CONFIRMATION => 'Awaiting Confirmation',
        };
    }

    /**
     * Check if ride can accept new bookings
     */
    public function canBeBooked(): bool
    {
        return in_array($this, [self::ACTIVE, self::FULL]);
    }

    /**
     * Check if ride is in a terminal state
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::CANCELLED, self::FINISHED]);
    }

    /**
     * Get all bookable statuses
     */
    public static function bookableStatuses(): array
    {
        return [
            self::ACTIVE->value,
            self::FULL->value,
        ];
    }

    /**
     * Get color for UI display
     */
    public function color(): string
    {
        return match($this) {
            self::ACTIVE => 'green',
            self::FULL => 'orange',
            self::CANCELLED => 'red',
            self::FINISHED => 'blue',
            self::AWAITING_CONFIRMATION => 'yellow',
        };
    }
}
