<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // The 'api' middleware group throttles by $request->user()?->id ?: $request->ip().
        // ThrottleRequests runs before the 'jwt' route middleware, so $request->user()
        // is always null at that point — every request in every test class shares one
        // IP-keyed 60/minute bucket. A full suite run exhausts it well before later test
        // files execute, causing well-formed requests to return 429 instead of their
        // expected status (this is what produced the pattern in DocumentControllerTest:
        // first request passes, everything after silently gets throttled).
        $this->withoutMiddleware(ThrottleRequests::class);
    }
}
