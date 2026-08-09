<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        'App\Events\RideBooked' => [
            'App\Listeners\SendRideBookedNotification',
        ],
        'App\Events\RideCancelled' => [
            'App\Listeners\SendRideCancelledNotification',
        ],
        'App\Events\MessageReceived' => [
            'App\Listeners\SendMessageNotification',
        ],
        'App\Events\UserVerified' => [
            'App\Listeners\SendUserVerifiedNotification',
        ],
    ];

    public function boot(): void
    {
        User::observe(UserObserver::class);
    }

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
