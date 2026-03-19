<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\TrustHosts;
use Tests\TestCase;

class TrustHostsTest extends TestCase
{
    public function test_hosts_returns_array_with_application_subdomains(): void
    {
        $middleware = $this->app->make(TrustHosts::class);

        $hosts = $middleware->hosts();

        $this->assertIsArray($hosts);
    }
}
