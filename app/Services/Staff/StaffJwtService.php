<?php

namespace App\Services\Staff;

use App\Models\Employee;
use App\Models\StaffRefreshToken;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * StaffJwtService
 *
 * Issues and validates JWT access tokens + opaque refresh tokens for Employee models.
 * Completely parallel to JwtService — no shared state, no coupling.
 *
 * Token payload shape:
 *   sub       → employee ID
 *   sub_type  → 'employee'   (guards against cross-use with user tokens)
 *   role      → StaffRole value
 *   type      → 'access'
 *   ver       → token_version (invalidation after logout-all / password change)
 *   iat / exp → standard JWT claims
 */
final class StaffJwtService
{
    private const ALGORITHM        = 'HS256';
    private const ACCESS_TTL       = 3600;        // 1 hour
    private const REFRESH_TTL_DAYS = 30;
    private const SUB_TYPE         = 'employee';

    // ── Token generation ──────────────────────────────────────────────────────

    public function generateTokenPair(Employee $employee): array
    {
        $accessToken  = $this->generateAccessToken($employee);
        $refreshEntry = $this->generateRefreshToken($employee);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshEntry['token'],
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TTL,
        ];
    }

    // ── Token validation ──────────────────────────────────────────────────────

    /**
     * Decode and verify an access token.
     * Returns the payload array on success, null on any failure.
     */
    public function decodeToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret(), self::ALGORITHM));
            $payload = (array) $decoded;

            // Reject tokens not issued for employees.
            if (($payload['sub_type'] ?? null) !== self::SUB_TYPE) {
                return null;
            }

            return $payload;
        } catch (ExpiredException $e) {
            Log::debug('Staff access token expired', ['error' => $e->getMessage()]);
            return null;
        } catch (SignatureInvalidException $e) {
            Log::warning('Staff JWT signature invalid', ['error' => $e->getMessage()]);
            return null;
        } catch (\Throwable $e) {
            Log::warning('Staff JWT decode error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Guard against tokens issued before the last password change / logout-all.
     */
    public function validateTokenVersion(array $payload, Employee $employee): bool
    {
        return ((int) ($payload['ver'] ?? -1)) === $employee->token_version;
    }

    // ── Refresh ───────────────────────────────────────────────────────────────

    /**
     * Consume a refresh token and issue a new access + refresh pair (rotation).
     */
    public function refreshAccessToken(string $refreshToken): ?array
    {
        $tokenRecord = StaffRefreshToken::where('token', $refreshToken)
            ->with('employee')
            ->first();

        if (!$tokenRecord || !$tokenRecord->isValid()) {
            return null;
        }

        $employee = $tokenRecord->employee;

        if (!$employee || !$employee->is_active) {
            return null;
        }

        // Rotate: revoke old, issue new pair.
        $tokenRecord->update(['revoked' => true]);

        return $this->generateTokenPair($employee);
    }

    // ── Revocation ────────────────────────────────────────────────────────────

    /**
     * Revoke all refresh tokens and bump token_version to invalidate
     * all outstanding access tokens (logout-all / password change).
     */
    public function revokeAllTokens(int $employeeId): void
    {
        StaffRefreshToken::where('employee_id', $employeeId)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        Employee::where('id', $employeeId)
            ->increment('token_version');
    }

    // ── Maintenance ───────────────────────────────────────────────────────────

    public function cleanupExpiredTokens(): int
    {
        return StaffRefreshToken::where('expires_at', '<', now())
            ->orWhere('revoked', true)
            ->delete();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function generateAccessToken(Employee $employee): string
    {
        $now = time();

        $payload = [
            'iss'      => config('app.name'),
            'sub'      => $employee->id,
            'sub_type' => self::SUB_TYPE,
            'role'     => $employee->role->value,
            'type'     => 'access',
            'ver'      => $employee->token_version,
            'iat'      => $now,
            'exp'      => $now + self::ACCESS_TTL,
        ];

        return JWT::encode($payload, $this->secret(), self::ALGORITHM);
    }

    private function generateRefreshToken(Employee $employee): array
    {
        $token = Str::random(64);

        $record = StaffRefreshToken::create([
            'employee_id' => $employee->id,
            'token'       => $token,
            'expires_at'  => now()->addDays(self::REFRESH_TTL_DAYS),
            'revoked'     => false,
            'user_agent'  => request()->userAgent(),
            'ip_address'  => request()->ip(),
        ]);

        return ['token' => $token, 'record' => $record];
    }

    private function secret(): string
    {
        $secret = config('jwt.secret');

        if (empty($secret)) {
            throw new \RuntimeException(
                'JWT secret is not configured. Run: php artisan jwt:secret'
            );
        }

        return base64_decode($secret);
    }
}
