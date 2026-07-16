<?php

namespace Tests\Unit\Providers;

use App\Providers\EventServiceProvider;
use Tests\TestCase;

class EventServiceProviderTest extends TestCase
{
    public function test_event_service_provider_is_registered(): void
    {
        $provider = $this->app->getProvider(EventServiceProvider::class);
        $this->assertNotNull($provider);
        $this->assertInstanceOf(EventServiceProvider::class, $provider);
    }

    public function test_event_service_provider_extends_base_provider(): void
    {
        $this->assertTrue(
            is_subclass_of(
                EventServiceProvider::class,
                \Illuminate\Foundation\Support\Providers\EventServiceProvider::class
            )
        );
    }

    public function test_event_service_provider_has_boot_method(): void
    {
        $this->assertTrue(method_exists(EventServiceProvider::class, 'boot'));
    }

    public function test_ride_booked_event_has_listener_registered(): void
    {
        $provider = $this->app->getProvider(EventServiceProvider::class);
        $listen   = (new \ReflectionProperty($provider, 'listen'))->getValue($provider);

        $this->assertArrayHasKey(\App\Events\RideBooked::class, $listen);
        $this->assertContains(
            \App\Listeners\SendRideBookedNotification::class,
            $listen[\App\Events\RideBooked::class]
        );
    }

    public function test_ride_cancelled_event_has_listener_registered(): void
    {
        $provider = $this->app->getProvider(EventServiceProvider::class);
        $listen   = (new \ReflectionProperty($provider, 'listen'))->getValue($provider);

        $this->assertArrayHasKey(\App\Events\RideCancelled::class, $listen);
        $this->assertContains(
            \App\Listeners\SendRideCancelledNotification::class,
            $listen[\App\Events\RideCancelled::class]
        );
    }

    public function test_message_received_event_has_listener_registered(): void
    {
        $provider = $this->app->getProvider(EventServiceProvider::class);
        $listen   = (new \ReflectionProperty($provider, 'listen'))->getValue($provider);

        $this->assertArrayHasKey(\App\Events\MessageReceived::class, $listen);
        $this->assertContains(
            \App\Listeners\SendMessageNotification::class,
            $listen[\App\Events\MessageReceived::class]
        );
    }

    public function test_user_verified_event_has_listener_registered(): void
    {
        $provider = $this->app->getProvider(EventServiceProvider::class);
        $listen   = (new \ReflectionProperty($provider, 'listen'))->getValue($provider);

        $this->assertArrayHasKey(\App\Events\UserVerified::class, $listen);
        $this->assertContains(
            \App\Listeners\SendUserVerifiedNotification::class,
            $listen[\App\Events\UserVerified::class]
        );
    }

    public function test_four_events_are_registered_in_total(): void
    {
        $provider = $this->app->getProvider(EventServiceProvider::class);
        $listen   = (new \ReflectionProperty($provider, 'listen'))->getValue($provider);

        $this->assertCount(4, $listen);
    }

    public function test_auto_discovery_is_disabled(): void
    {
        $provider = new EventServiceProvider($this->app);
        $this->assertFalse($provider->shouldDiscoverEvents());
    }
}
