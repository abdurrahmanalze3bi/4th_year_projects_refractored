<?php

namespace App\Http\Middleware;

use App\Services\JwtService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthMiddleware
{
    public function __construct(protected JwtService $jwtService) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Extract Bearer token
        $token = $this->extractToken($request);
        if (!$token) {
            return $this->fail('TOKEN_MISSING', 'Unauthenticated');
        }

        // 2. Decode: verify signature + expiry
        $payload = $this->jwtService->decodeToken($token);
        if (!$payload) {
            return $this->fail('TOKEN_INVALID', 'Invalid or expired token');
        }

        // 3. Must be an access token
        if (($payload['type'] ?? null) !== 'access') {
            return $this->fail('TOKEN_TYPE_INVALID', 'Invalid token type');
        }

        // 4. Load user
        $user = User::find($payload['sub']);
        if (!$user) {
            return $this->fail('USER_NOT_FOUND', 'User not found');
        }

        // 5. Account must be active
        if ($user->status == 0) {
            return $this->fail('USER_INACTIVE', 'User account is inactive');
        }

        // 6. Layer 3 — token_version check
        //    Rejects tokens issued before the last password change or logout-all.
        //    No extra DB query — user is already loaded above.
        if (!$this->jwtService->validateTokenVersion($payload, $user)) {
            return $this->fail(
                'TOKEN_INVALIDATED',
                'Your session has been invalidated. Please log in again.'
            );
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');
        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }

    private function fail(string $code, string $message): Response
    {
        return response()->json(['status' => 'error', 'code' => $code, 'message' => $message], 401);
    }
}
