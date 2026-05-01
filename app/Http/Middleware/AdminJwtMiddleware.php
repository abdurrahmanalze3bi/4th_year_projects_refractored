<?php

namespace App\Http\Middleware;

use App\Services\JwtService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AdminJwtMiddleware
 *
 * Validates every protected admin API route.
 *
 * Strategy (no JwtService changes required):
 *   1. Decode the Bearer token with the existing JwtService::decodeToken()
 *   2. Load the User from the 'sub' claim
 *   3. Look up the user's email in config/admin.php to confirm they are admin
 *      ↳ This server-side check is actually stronger than a claim in the token
 *        because it always reflects the current config, not a stale payload.
 *   4. Inject adminConfig + adminType into $request->attributes
 *
 * Usage:
 *   middleware('auth.admin')              — any admin
 *   middleware('auth.admin:primary')      — primary admin only
 */
final class AdminJwtMiddleware
{
    public function __construct(
        private readonly JwtService $jwtService
    ) {}

    public function handle(Request $request, Closure $next, ?string $requiredType = null): Response
    {
        // ── 1. Extract Bearer token ──────────────────────────────────────────
        $token = $this->extractToken($request);

        if (!$token) {
            return $this->unauthorized('Admin access token missing');
        }

        // ── 2. Decode & validate (expiry + signature) ────────────────────────
        $payload = $this->jwtService->decodeToken($token);

        if (!$payload) {
            return $this->unauthorized('Invalid or expired admin token');
        }

        // ── 3. Must be an access token, not a refresh token ──────────────────
        if (($payload['type'] ?? null) !== 'access') {
            return $this->unauthorized('Provide the access token, not the refresh token');
        }

        // ── 4. Load User ─────────────────────────────────────────────────────
        $user = User::find($payload['sub']);

        if (!$user) {
            return $this->unauthorized('User account not found');
        }

        // ── 5. Confirm this user is an admin via config lookup ───────────────
        //      We check email (set when AdminWalletService creates the user row)
        //      against config/admin.php — server-side, always fresh, unforgeable.
        $adminConfig = $this->resolveAdminConfig($user->email);

        if (!$adminConfig) {
            return $this->unauthorized('This account does not have admin privileges');
        }

        // ── 6. Optional per-route type gate: middleware('auth.admin:primary') ─
        if ($requiredType && $adminConfig['type'] !== $requiredType) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'FORBIDDEN',
                'message' => "This action requires '{$requiredType}' admin access",
            ], 403);
        }

        // ── 7. Inject context — controllers read this, never decode tokens ───
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('adminConfig', $adminConfig);
        $request->attributes->set('adminType',   $adminConfig['type']);

        return $next($request);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Find the admin config block whose email matches the given email.
     * Returns null if no match → not an admin.
     */
    private function resolveAdminConfig(string $email): ?array
    {
        foreach (['primary', 'sycash'] as $type) {
            $config = config("admin.{$type}");

            if (isset($config['email']) && $config['email'] === $email) {
                return array_merge($config, ['type' => $type]);
            }
        }

        return null;
    }

    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        return str_starts_with($header, 'Bearer ')
            ? substr($header, 7)
            : null;
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'status'  => 'error',
            'code'    => 'UNAUTHORIZED',
            'message' => $message,
        ], 401);
    }
}
