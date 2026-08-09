<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Services\Staff\StaffJwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * StaffJwtMiddleware
 *
 * Guards routes that require any staff role (support_agent, admin, system_admin,
 * or sycash). Role-level access is enforced by individual controllers / services.
 *
 * What changed vs. the old version:
 *   OLD → had a dual-path: tried StaffJwtService first, then fell back to
 *         JwtService (user tokens) and auto-created an Employee row on the fly
 *         for the system admin. This was a workaround for the hardcoded config.
 *   NEW → single path: all staff (including system_admin and sycash) have proper
 *         Employee rows seeded at deployment. No fallback, no on-the-fly creation.
 *         JwtService import removed — staff tokens only.
 *
 * Usage (unchanged from caller's perspective):
 *   middleware('staff')                        — any active employee
 *   middleware('staff:admin,system_admin')     — admin or system_admin
 *   middleware('staff:support_agent')          — support_agent only
 */
final class StaffJwtMiddleware
{
    public function __construct(
        private readonly StaffJwtService $staffJwtService,
    ) {}

    /**
     * Laravel passes middleware parameters as separate string arguments:
     *   middleware('staff:admin,system_admin') → handle($req, $next, 'admin', 'system_admin')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // ── 1. Extract Bearer token ───────────────────────────────────────────
        $token = $this->extractToken($request);
        if (!$token) {
            return $this->fail('TOKEN_MISSING', 'Staff access token is required.');
        }

        // ── 2. Decode with StaffJwtService (staff-specific secret + claims) ───
        $payload = $this->staffJwtService->decodeToken($token);
        if (!$payload) {
            return $this->fail('TOKEN_INVALID', 'Invalid or expired token.');
        }

        // ── 3. Must be an access token ────────────────────────────────────────
        if (($payload['type'] ?? null) !== 'access') {
            return $this->fail('TOKEN_TYPE_INVALID', 'Provide the access token, not the refresh token.');
        }

        // ── 4. Load Employee ──────────────────────────────────────────────────
        $employee = Employee::find($payload['sub']);
        if (!$employee) {
            return $this->fail('EMPLOYEE_NOT_FOUND', 'Employee account not found.');
        }

        if (!$employee->is_active) {
            return $this->fail('ACCOUNT_INACTIVE', 'This employee account has been deactivated.');
        }

        // ── 5. Token version check ────────────────────────────────────────────
        if (!$this->staffJwtService->validateTokenVersion($payload, $employee)) {
            return $this->fail('TOKEN_INVALIDATED', 'Session invalidated. Please log in again.');
        }

        // ── 6. Role gate ──────────────────────────────────────────────────────
        if (!$this->checkRoles($employee->role->value, $roles)) {
            return $this->forbidden($roles);
        }

        // ── 7. Inject context ─────────────────────────────────────────────────
        $request->attributes->set('staffEmployee', $employee);

        return $next($request);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function checkRoles(string $actualRole, array $allowedRoles): bool
    {
        if (empty($allowedRoles)) {
            return true; // No restriction — any active employee passes
        }
        return in_array($actualRole, array_map('trim', $allowedRoles), strict: true);
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

    private function forbidden(array $roles): Response
    {
        return response()->json([
            'status'  => 'error',
            'code'    => 'FORBIDDEN',
            'message' => 'This action requires one of: ' . implode(', ', $roles),
        ], 403);
    }
}
