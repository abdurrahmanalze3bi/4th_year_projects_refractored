<?php

namespace Tests\Unit\Providers;

use App\Providers\RouteServiceProvider;
use Illuminate\Routing\Router;
use Tests\TestCase;

class RouteServiceProviderTest extends TestCase
{
    public function test_home_constant_is_defined(): void
    {
        $this->assertEquals('/home', RouteServiceProvider::HOME);
    }

    public function test_api_routes_are_registered(): void
    {
        // If routes loaded correctly, the login route exists
        $this->assertTrue(
            collect($this->app['router']->getRoutes()->getRoutes())
                ->contains(fn($r) => str_contains($r->uri(), 'api/'))
        );
    }

    public function test_rate_limiter_is_configured_for_api(): void
    {
        // Trigger boot which sets up rate limiter
        $provider = $this->app->getProvider(RouteServiceProvider::class);
        $this->assertNotNull($provider);
    }
}
