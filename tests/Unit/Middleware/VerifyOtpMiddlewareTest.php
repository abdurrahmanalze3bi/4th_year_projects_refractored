<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\VerifyOtpMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;

class VerifyOtpMiddlewareTest extends TestCase
{
    public function test_middleware_passes_request_through_to_next(): void
    {
        $middleware = new VerifyOtpMiddleware();
        $request    = Request::create('/test', 'GET');
        $called     = false;

        $middleware->handle($request, function ($req) use (&$called) {
            $called = true;
            return new Response('ok');          // ← no app() call
        });

        $this->assertTrue($called);
    }

    public function test_middleware_forwards_the_same_request_instance(): void
    {
        $middleware = new VerifyOtpMiddleware();
        $request    = Request::create('/test', 'POST');
        $received   = null;

        $middleware->handle($request, function ($req) use (&$received) {
            $received = $req;
            return new Response('ok');
        });

        $this->assertSame($request, $received);
    }

    public function test_middleware_returns_next_response(): void
    {
        $middleware = new VerifyOtpMiddleware();
        $request    = Request::create('/test', 'GET');

        $result = $middleware->handle($request, function () {
            return new Response('middleware_ok', 200);  // ← fixed
        });

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals('middleware_ok', $result->getContent());
    }

    public function test_middleware_can_be_instantiated(): void
    {
        $this->assertInstanceOf(VerifyOtpMiddleware::class, new VerifyOtpMiddleware());
    }

    public function test_middleware_has_handle_method(): void
    {
        $this->assertTrue(method_exists(VerifyOtpMiddleware::class, 'handle'));
    }

    public function test_middleware_works_for_different_http_methods(): void
    {
        $middleware = new VerifyOtpMiddleware();

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $called  = false;
            $request = Request::create('/test', $method);

            $middleware->handle($request, function () use (&$called) {
                $called = true;
                return new Response('ok');      // ← fixed
            });

            $this->assertTrue($called, "Middleware did not pass through for {$method}");
        }
    }
}
