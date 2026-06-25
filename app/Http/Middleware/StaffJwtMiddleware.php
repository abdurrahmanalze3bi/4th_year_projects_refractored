<?php

namespace App\Http\Middleware;

use App\Enums\StaffRole;
use App\Models\Employee;
use App\Models\User;
use App\Services\JwtService;
use App\Services\Staff\StaffJwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class StaffJwtMiddleware
{
    public function __construct(
        private readonly StaffJwtService $staffJwtService,
        private readonly JwtService      $jwtService,
    ) {}

    public function handle(Request $request, Closure $next, ?string $roles = null): Response
    {
        $token = $this->extractToken($request);
        if (!$token) {
            return $this->fail('TOKEN_MISSING', 'Staff access token is required.');
        }

        // ── Try staff token first ────────────────────────────────────────
        $staffPayload = $this->staffJwtService->decodeToken($token);
        if ($staffPayload) {
            return $this->handleStaffToken($request, $next, $staffPayload, $roles);
        }

        // ── Fall back to admin token (system admin = system_admin) ───────
        $adminPayload = $this->jwtService->decodeToken($token);
        if ($adminPayload) {
            return $this->handleAdminToken($request, $next, $adminPayload, $roles);
        }

        return $this->fail('TOKEN_INVALID', 'Invalid or expired token.');
    }

    // ── Staff JWT path ────────────────────────────────────────────────────

    private function handleStaffToken(Request $request, Closure $next, array $payload, ?string $roles): Response
    {
        if (($payload['type'] ?? null) !== 'access') {
            return $this->fail('TOKEN_TYPE_INVALID', 'Provide the access token, not the refresh token.');
        }

        $employee = Employee::find($payload['sub']);
        if (!$employee) {
            return $this->fail('EMPLOYEE_NOT_FOUND', 'Employee account not found.');
        }

        if (!$employee->is_active) {
            return $this->fail('ACCOUNT_INACTIVE', 'This employee account has been deactivated.');
        }

        if (!$this->staffJwtService->validateTokenVersion($payload, $employee)) {
            return $this->fail('TOKEN_INVALIDATED', 'Session invalidated. Please log in again.');
        }

        if (!$this->checkRoles($employee->role->value, $roles)) {
            return $this->forbidden($roles);
        }

        $request->attributes->set('staffEmployee', $employee);
        return $next($request);
    }

    // ── Admin JWT path (system admin token = system_admin access) ────────

    // app/Http/Middleware/StaffJwtMiddleware.php

    private function handleAdminToken(Request $request, Closure $next, array $payload, ?string $roles): Response
    {
        if (($payload['type'] ?? null) !== 'access') {
            return $this->fail('TOKEN_TYPE_INVALID', 'Provide the access token, not the refresh token.');
        }

        $user = User::find($payload['sub']);
        if (!$user) {
            return $this->fail('USER_NOT_FOUND', 'User not found.');
        }

        $systemAdminEmail = config('admin.system_admin.email');
        if ($user->email !== $systemAdminEmail) {
            return $this->fail('FORBIDDEN', 'Admin access only for the system admin.');
        }

        $employee = Employee::firstOrCreate(
            ['username' => config('admin.system_admin.username')],
            [
                'email'         => $systemAdminEmail,
                'password'      => bcrypt(config('admin.system_admin.password')),
                'first_name'    => config('admin.system_admin.first_name', 'System'),
                'last_name'     => config('admin.system_admin.last_name', 'Admin'),
                'role'          => StaffRole::SYSTEM_ADMIN->value,
                'is_active'     => true,
                'token_version' => 0,
            ]
        );

        if (!$this->checkRoles(StaffRole::SYSTEM_ADMIN->value, $roles)) {
            return $this->forbidden($roles);
        }

        // ✅ FIX: set user resolver so $request->user() works in admin controllers
        // (AdminDashboardController::logout() calls $request->user()->id)
        $request->setUserResolver(fn () => $user);

        $request->attributes->set('staffEmployee', $employee);
        return $next($request);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function checkRoles(string $actualRole, ?string $allowedRoles): bool
    {
        if ($allowedRoles === null) return true;
        $allowed = array_map('trim', explode(',', $allowedRoles));
        return in_array($actualRole, $allowed, strict: true);
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

    private function forbidden(?string $roles): Response
    {
        return response()->json([
            'status'  => 'error',
            'code'    => 'FORBIDDEN',
            'message' => 'This action requires one of: ' . $roles,
        ], 403);
    }
}
