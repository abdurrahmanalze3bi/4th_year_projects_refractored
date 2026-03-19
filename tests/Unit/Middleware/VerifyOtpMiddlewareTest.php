<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\VerifyOtpMiddleware;
use Illuminate\Http\Request;
use Tests\TestCase;

class VerifyOtpMiddlewareTest extends TestCase
{
    public function test_middleware_passes_request_through(): void
    {
        $middleware = new VerifyOtpMiddleware();
        $request    = Request::create('/test', 'GET');
        $called     = false;

        // FIX: Closure must return a Response — returning null causes a TypeError
        // because handle() has a Response return type and calls `return $next($request)`
        $middleware->handle($request, function ($req) use (&$called) {
            $called = true;
            return response('ok');
        });

        $this->assertTrue($called);
    }
}
