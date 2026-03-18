<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Log;

/**
 * Service for admin authentication
 * Eliminates auth logic from AdminDashboardController
 */
class AdminAuthService
{
    /**
     * Authenticate admin with email and password
     */
    public function authenticate(string $email, string $password): ?array
    {
        $adminConfig = $this->getAdminConfigByCredentials($email, $password);

        if (!$adminConfig) {
            Log::warning('Admin login attempt failed', ['email' => $email]);
            return null;
        }

        // Create session
        $this->createAdminSession($adminConfig);

        Log::info('Admin logged in successfully', [
            'email' => $email,
            'type' => $adminConfig['type']
        ]);

        return $adminConfig;
    }

    /**
     * Get admin configuration by credentials
     */
    private function getAdminConfigByCredentials(string $email, string $password): ?array
    {
        $adminConfigs = config('admin');

        foreach (['primary', 'sycash'] as $type) {
            $config = $adminConfigs[$type];

            // In production, use Hash::check for hashed passwords
            if ($config['email'] === $email && $config['password'] === $password) {
                return array_merge($config, ['type' => $type]);
            }
        }

        return null;
    }

    /**
     * Create admin session
     */
    private function createAdminSession(array $adminConfig): void
    {
        Session::put([
            'admin_logged_in' => true,
            'admin_email' => $adminConfig['email'],
            'admin_type' => $adminConfig['type'],
            'admin_permissions' => $adminConfig['permissions'] ?? []
        ]);

        Session::save();
    }

    /**
     * Check if current user is authenticated admin
     */
    public function isAuthenticated(): bool
    {
        return Session::get('admin_logged_in', false);
    }

    /**
     * Get current admin configuration
     */
    public function getCurrentAdmin(): ?array
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        $type = Session::get('admin_type');
        $config = config("admin.{$type}");

        if (!$config) {
            return null;
        }

        return array_merge($config, ['type' => $type]);
    }

    /**
     * Check if current admin is primary admin
     */
    public function isPrimaryAdmin(): bool
    {
        return Session::get('admin_type') === 'primary';
    }

    /**
     * Check if admin has permission
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = Session::get('admin_permissions', []);

        // Wildcard permission
        if (in_array('*', $permissions)) {
            return true;
        }

        return in_array($permission, $permissions);
    }

    /**
     * Logout current admin
     */
    public function logout(): void
    {
        $email = Session::get('admin_email');

        Session::forget([
            'admin_logged_in',
            'admin_email',
            'admin_type',
            'admin_permissions'
        ]);

        Log::info('Admin logged out', ['email' => $email]);
    }

    /**
     * Get admin info for response
     */
    public function getAdminInfo(): ?array
    {
        $adminConfig = $this->getCurrentAdmin();

        if (!$adminConfig) {
            return null;
        }

        return [
            'email' => $adminConfig['email'],
            'type' => $adminConfig['type'],
            'name' => $adminConfig['first_name'] . ' ' . $adminConfig['last_name'],
            'phone' => $adminConfig['phone'],
            'permissions' => $adminConfig['permissions'] ?? []
        ];
    }
}
