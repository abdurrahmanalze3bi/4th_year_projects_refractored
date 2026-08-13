<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    protected function configureRateLimiting(): void
    {
        $enabled = config('rate-limiting.enabled', true);   // ← add the hyphen
        $limits  = config('rate-limiting.limits', []);       // ← add the hyphen

        foreach ($limits as $name => $perMinute) {
            RateLimiter::for($name, function (Request $request) use ($enabled, $perMinute) {
                if (! $enabled) {
                    return Limit::none();
                }
                return Limit::perMinute($perMinute)
                    ->by($request->user()?->id ?: $request->ip());
            });
        }
    }
}
