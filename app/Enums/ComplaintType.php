<?php

namespace App\Enums;

enum ComplaintType: string
{
    case TRIP_SAFETY        = 'trip_safety';
    case DRIVER_BEHAVIOR    = 'driver_behavior';
    case PASSENGER_BEHAVIOR = 'passenger_behavior';
    case RIDE_CANCELLATION  = 'ride_cancellation';
    case FINANCIAL_ISSUE    = 'financial_issue';
    case ACCOUNT_ISSUE      = 'account_issue';
    case TECHNICAL_ISSUE    = 'technical_issue';
    case OTHER              = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TRIP_SAFETY        => 'أمان الرحلة',
            self::DRIVER_BEHAVIOR    => 'سلوك السائق',
            self::PASSENGER_BEHAVIOR => 'سلوك الراكب',
            self::RIDE_CANCELLATION  => 'إلغاء الرحلة',
            self::FINANCIAL_ISSUE    => 'مشكلة مالية',
            self::ACCOUNT_ISSUE      => 'مشكلة في الحساب',
            self::TECHNICAL_ISSUE    => 'عطل تقني',
            self::OTHER              => 'أخرى',
        };
    }
}
