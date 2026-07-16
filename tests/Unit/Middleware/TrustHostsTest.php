<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\TrustHosts;
use Tests\TestCase;

class TrustHostsTest extends TestCase
{
    public function test_hosts_method_returns_an_array(): void
    {
        $middleware = $this->app->make(TrustHosts::class);
        $this->assertIsArray($middleware->hosts());
    }

    public function test_hosts_array_is_not_empty(): void
    {
        $middleware = $this->app->make(TrustHosts::class);
        $this->assertNotEmpty($middleware->hosts());
    }

    public function test_hosts_array_contains_at_least_one_element(): void
    {
        $middleware = $this->app->make(TrustHosts::class);
        $this->assertGreaterThanOrEqual(1, count($middleware->hosts()));
    }

    public function test_middleware_class_can_be_resolved_from_container(): void
    {
        $middleware = $this->app->make(TrustHosts::class);
        $this->assertInstanceOf(TrustHosts::class, $middleware);
    }

    public function test_middleware_extends_laravel_trust_hosts(): void
    {
        $this->assertTrue(
            is_subclass_of(
                TrustHosts::class,
                \Illuminate\Http\Middleware\TrustHosts::class
            )
        );
    }

    public function test_hosts_method_exists(): void
    {
        $this->assertTrue(method_exists(TrustHosts::class, 'hosts'));
    }
}
