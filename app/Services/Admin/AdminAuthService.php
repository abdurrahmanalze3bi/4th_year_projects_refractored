<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Support\Facades\Log;

/**
 * AdminAuthService
 *
 * Handles admin login / refresh / logout.
 *
 * Admin users are kept as lightweight User rows purely so JwtService
 * can issue tokens. They have no wallet attached — system wallets
 * (Primary Escrow, SyCash) are standalone rows seeded via SystemWalletSeeder.
 */
final class AdminAuthService
{
    public function __construct(
        private readonly JwtService $jwtService,
    ) {}

    // =========================================================================
    // LOGIN
    // =========================================================================

    /**
     * Validate credentials (email OR username) + password.
     * Ensure a minimal admin User row exists (for JWT), then return token pair.
     *
     * @return array{tokens: array, admin: array}|null
     */
    public function authenticate(string $emailOrUsername, string $password): ?array
    {
        $adminConfig = $this->findAdminConfig($emailOrUsername, $password);

        if (!$adminConfig) {
            Log::warning('Admin login failed', ['identifier' => $emailOrUsername]);
            return null;
        }

        // Ensure a User row exists for this admin so JwtService can sign tokens.
        // This row has NO wallet — it is only a JWT principal.
        $adminUser = User::firstOrCreate(
            ['email' => $adminConfig['email']],
            [
                'first_name' => $adminConfig['first_name'],
                'last_name'  => $adminConfig['last_name'],
                'password'   => bcrypt($adminConfig['password']),
                'gender'     => 'M',
                'address'    => $adminConfig['address'] ?? 'دمشق',
                'status'     => 1,
            ]
        );

        $tokens = $this->jwtService->generateTokenPair($adminUser);

        Log::info('Admin logged in', [
            'identifier' => $emailOrUsername,
            'admin_type' => $adminConfig['type'],
        ]);

        return [
            'tokens' => $tokens,
            'admin'  => [
                'type'  => $adminConfig['type'],
                'email' => $adminConfig['email'],
                'name'  => $adminConfig['first_name'] . ' ' . $adminConfig['last_name'],
                'phone' => $adminConfig['phone'],
            ],
        ];
    }

    // =========================================================================
    // REFRESH / LOGOUT
    // =========================================================================

    public function refresh(string $refreshToken): ?array
    {
        return $this->jwtService->refreshAccessToken($refreshToken);
    }

    public function logout(int $adminUserId): void
    {
        $this->jwtService->revokeAllTokens($adminUserId);
        Log::info('Admin logged out', ['user_id' => $adminUserId]);
    }

    // =========================================================================
    // HELPERS used by controllers / middleware
    // =========================================================================

    public function getAdminConfigFromRequest(\Illuminate\Http\Request $request): ?array
    {
        return $request->attributes->get('adminConfig');
    }

    public function isPrimary(\Illuminate\Http\Request $request): bool
    {
        return $request->attributes->get('adminType') === 'system_admin';
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    /**
     * Match $identifier against each admin config's 'email' OR 'username' field,
     * then verify the password.
     */
    private function findAdminConfig(string $identifier, string $password): ?array
    {
        foreach (['system_admin', 'sycash'] as $type) {
            $config = config("admin.{$type}");

            $emailMatch    = isset($config['email'])    && $config['email']    === $identifier;
            $usernameMatch = isset($config['username']) && $config['username'] === $identifier;

            if (($emailMatch || $usernameMatch) && $config['password'] === $password) {
                return array_merge($config, ['type' => $type]);
            }
        }

        return null;
    }
}
