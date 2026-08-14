<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (TokenExpiredException $e) {
            return response()->json(['message' => 'Token has expired'], 401);
        });

        $this->renderable(function (TokenInvalidException $e) {
            return response()->json(['message' => 'Token is invalid'], 401);
        });

        $this->renderable(function (JWTException $e) {
            return response()->json(['message' => 'Token not provided'], 401);
        });
    }
}
