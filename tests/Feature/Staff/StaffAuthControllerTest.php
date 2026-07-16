<?php

namespace Tests\Feature\Staff;

use App\Enums\StaffRole;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * StaffAuthControllerTest — Feature tests for StaffAuthController.
 *
 * TWO AUTH PATHS UNDER TEST:
 *   1. Employee credentials  → POST /api/staff/login  → staff JWT
 *   2. Admin JWT (primary)   → accepted by StaffJwtMiddleware as system_admin
 *
 * COVERS:
 *   POST /api/staff/login    — success / wrong password / inactive / validation
 *   POST /api/staff/refresh  — valid & invalid refresh tokens
 *   POST /api/staff/logout   — staff token and admin token
 *   GET  /api/staff/me       — returns authenticated employee info
 */
class StaffAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('admin.system_admin', [
            'email'         => 'primary@admin.test',
            'password'      => 'primary_pass',
            'username'      => 'primary_admin',
            'first_name'    => 'Primary',
            'last_name'     => 'Admin',
            'phone'         => '0910000001',
            'wallet_prefix' => 'PRIM',
            'permissions'   => ['*'],
        ]);

        Config::set('admin.sycash', [
            'email'         => 'sycash@admin.test',
            'password'      => 'sycash_pass',
            'first_name'    => 'SyCash',
            'last_name'     => 'Admin',
            'phone'         => '0910000002',
            'wallet_prefix' => 'SYCSH',
            'permissions'   => ['view_wallet'],
        ]);
    }

    // ─── POST /api/staff/login ────────────────────────────────────────────────

    public function test_employee_can_login_with_username(): void
    {
        $this->makeEmployee('agent@test.com', 'agent_user', 'secret123');

        $this->postJson('/api/staff/login', [
            'identifier' => 'agent_user',
            'password'   => 'secret123',
        ])->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'tokens'   => ['access_token', 'refresh_token'],
                'employee',
            ]);
    }

    public function test_employee_can_login_with_email(): void
    {
        $this->makeEmployee('agent@test.com', 'agent_user', 'secret123');

        $this->postJson('/api/staff/login', [
            'identifier' => 'agent@test.com',
            'password'   => 'secret123',
        ])->assertStatus(200);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->makeEmployee('agent@test.com', 'agent_user', 'secret123');

        $this->postJson('/api/staff/login', [
            'identifier' => 'agent_user',
            'password'   => 'wrong_password',
        ])->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_login_fails_with_nonexistent_identifier(): void
    {
        $this->postJson('/api/staff/login', [
            'identifier' => 'nobody_here',
            'password'   => 'any_password',
        ])->assertStatus(401);
    }

    public function test_login_requires_identifier_field(): void
    {
        $this->postJson('/api/staff/login', ['password' => 'secret123'])
            ->assertStatus(422);
    }

    public function test_login_requires_password_field(): void
    {
        $this->postJson('/api/staff/login', ['identifier' => 'agent_user'])
            ->assertStatus(422);
    }

    public function test_login_fails_for_empty_body(): void
    {
        $this->postJson('/api/staff/login', [])
            ->assertStatus(422);
    }

    public function test_login_fails_for_inactive_employee(): void
    {
        $this->makeEmployee('agent@test.com', 'agent_user', 'secret123', is_active: false);

        $this->postJson('/api/staff/login', [
            'identifier' => 'agent_user',
            'password'   => 'secret123',
        ])->assertStatus(401);
    }

    // ─── POST /api/staff/refresh ──────────────────────────────────────────────

    public function test_can_refresh_token(): void
    {
        $this->makeEmployee('agent@test.com', 'agent_user', 'secret123');

        $refresh = $this->postJson('/api/staff/login', [
            'identifier' => 'agent_user',
            'password'   => 'secret123',
        ])->json('tokens.refresh_token');

        $this->postJson('/api/staff/refresh', ['refresh_token' => $refresh])
            ->assertStatus(200)
            ->assertJsonStructure(['tokens' => ['access_token', 'refresh_token']]);
    }

    public function test_refresh_fails_with_invalid_token(): void
    {
        $this->postJson('/api/staff/refresh', [
            'refresh_token' => 'not.a.real.token',
        ])->assertStatus(401);
    }

    public function test_refresh_requires_refresh_token_field(): void
    {
        $this->postJson('/api/staff/refresh', [])->assertStatus(422);
    }

    // ─── POST /api/staff/logout ───────────────────────────────────────────────

    public function test_authenticated_staff_can_logout(): void
    {
        $this->withToken($this->staffToken())
            ->postJson('/api/staff/logout')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_unauthenticated_logout_returns_401(): void
    {
        $this->postJson('/api/staff/logout')->assertStatus(401);
    }

    public function test_admin_token_also_works_for_logout(): void
    {
        // StaffJwtMiddleware accepts admin JWT and maps it to system_admin role
        $this->withToken($this->adminToken())
            ->postJson('/api/staff/logout')
            ->assertStatus(200);
    }

    // ─── GET /api/staff/me ────────────────────────────────────────────────────

    public function test_authenticated_staff_can_get_own_info(): void
    {
        $this->withToken($this->staffToken())
            ->getJson('/api/staff/me')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['employee']);
    }

    public function test_unauthenticated_request_to_me_returns_401(): void
    {
        $this->getJson('/api/staff/me')->assertStatus(401);
    }

    public function test_admin_token_works_for_me_endpoint(): void
    {
        $this->withToken($this->adminToken())
            ->getJson('/api/staff/me')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeEmployee(
        string    $email,
        string    $username,
        string    $password,
        StaffRole $role      = StaffRole::SUPPORT_AGENT,
        bool      $is_active = true,
    ): Employee {
        return Employee::create([
            'username'      => $username,
            'email'         => $email,
            'password'      => bcrypt($password),
            'first_name'    => 'Test',
            'last_name'     => 'Agent',
            'role'          => $role->value,
            'is_active'     => $is_active,
            'token_version' => 0,
        ]);
    }

    private function staffToken(): string
    {
        $this->makeEmployee('agent@test.com', 'agent_user', 'secret123');

        return $this->postJson('/api/staff/login', [
            'identifier' => 'agent_user',
            'password'   => 'secret123',
        ])->json('tokens.access_token');
    }

    private function adminToken(): string
    {
        return $this->postJson('/api/admin/login', [
            'email'    => 'primary@admin.test',
            'password' => 'primary_pass',
        ])->json('tokens.access_token');
    }
}
