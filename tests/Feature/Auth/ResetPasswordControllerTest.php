<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * ResetPasswordControllerTest — Feature tests for POST /api/auth/reset-password.
 *
 * LOCATION: tests/Feature/Auth/ResetPasswordControllerTest.php
 *
 * HOW PASSWORD RESET WORKS IN THIS APP:
 * 1. POST /api/auth/forgot-password   → generates a token in password_reset_tokens table
 * 2. POST /api/auth/reset-password    → validates token + email + password, resets it
 *
 * The controller uses PasswordResetRepository → Password::reset()
 *
 * POSTMAN EQUIVALENT:
 * POST http://localhost/api/auth/reset-password
 * Body: { "token": "<token>", "email": "<email>", "password": "newpass123", "password_confirmation": "newpass123" }
 * Expected: { "success": true, "message": "..." }  HTTP 200
 */
class ResetPasswordControllerTest extends TestCase
{
    use RefreshDatabase;

    private User   $user;
    private string $rawToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email'    => 'reset@test.com',
            'password' => bcrypt('old_password'),
        ]);

        // Generate a real reset token the same way the forgot-password flow does
        $this->rawToken = Password::createToken($this->user);
    }

    // ─── Successful reset ──────────────────────────────────────────────────────

    public function test_user_can_reset_password_with_valid_token(): void
    {
        $response = $this->postJson('/api/auth/reset-password', [
            'token'                 => $this->rawToken,
            'email'                 => 'reset@test.com',
            'password'              => 'new_password123',
            'password_confirmation' => 'new_password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_password_is_actually_changed_after_successful_reset(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'token'                 => $this->rawToken,
            'email'                 => 'reset@test.com',
            'password'              => 'brand_new_pass123',
            'password_confirmation' => 'brand_new_pass123',
        ]);

        $this->user->refresh();
        $this->assertTrue(Hash::check('brand_new_pass123', $this->user->password));
    }

    public function test_old_password_no_longer_works_after_reset(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'token'                 => $this->rawToken,
            'email'                 => 'reset@test.com',
            'password'              => 'new_password123',
            'password_confirmation' => 'new_password123',
        ]);

        $this->user->refresh();
        $this->assertFalse(Hash::check('old_password', $this->user->password));
    }

    // ─── Validation failures ───────────────────────────────────────────────────

    public function test_reset_fails_with_missing_token(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'email'                 => 'reset@test.com',
            'password'              => 'new_password123',
            'password_confirmation' => 'new_password123',
        ])->assertStatus(422);
    }

    public function test_reset_fails_with_missing_email(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'token'                 => $this->rawToken,
            'password'              => 'new_password123',
            'password_confirmation' => 'new_password123',
        ])->assertStatus(422);
    }

    public function test_reset_fails_with_missing_password(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'token'                 => $this->rawToken,
            'email'                 => 'reset@test.com',
            'password_confirmation' => 'new_password123',
        ])->assertStatus(422);
    }

    public function test_reset_fails_with_password_mismatch(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'token'                 => $this->rawToken,
            'email'                 => 'reset@test.com',
            'password'              => 'new_password123',
            'password_confirmation' => 'different_password',
        ])->assertStatus(422);
    }

    public function test_reset_fails_with_password_too_short(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'token'                 => $this->rawToken,
            'email'                 => 'reset@test.com',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);
    }

    public function test_reset_fails_with_non_existent_email(): void
    {
        $response = $this->postJson('/api/auth/reset-password', [
            'token'                 => $this->rawToken,
            'email'                 => 'nobody@test.com',
            'password'              => 'new_password123',
            'password_confirmation' => 'new_password123',
        ]);

        // Validation rule `exists:users,email` rejects this
        $response->assertStatus(422);
    }

    public function test_reset_fails_with_invalid_email_format(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'token'                 => $this->rawToken,
            'email'                 => 'not-an-email',
            'password'              => 'new_password123',
            'password_confirmation' => 'new_password123',
        ])->assertStatus(422);
    }

    // ─── Invalid / expired token ───────────────────────────────────────────────

    public function test_reset_fails_with_wrong_token(): void
    {
        $response = $this->postJson('/api/auth/reset-password', [
            'token'                 => 'invalid_token_string_here',
            'email'                 => 'reset@test.com',
            'password'              => 'new_password123',
            'password_confirmation' => 'new_password123',
        ]);

        // Returns 400 (invalid token) or 422 (validation fail) — not 200
        $this->assertNotEquals(200, $response->status());
    }

    public function test_reset_token_can_only_be_used_once(): void
    {
        // First use — should succeed
        $this->postJson('/api/auth/reset-password', [
            'token'                 => $this->rawToken,
            'email'                 => 'reset@test.com',
            'password'              => 'new_password123',
            'password_confirmation' => 'new_password123',
        ])->assertStatus(200);

        // Second use of the same token — should fail
        $response = $this->postJson('/api/auth/reset-password', [
            'token'                 => $this->rawToken,
            'email'                 => 'reset@test.com',
            'password'              => 'another_password123',
            'password_confirmation' => 'another_password123',
        ]);

        $this->assertNotEquals(200, $response->status());
    }

    // ─── Forgot-password integration ──────────────────────────────────────────

    public function test_forgot_password_endpoint_exists_and_accepts_email(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'reset@test.com',
        ]);

        // Laravel password broker returns 200 or 400 depending on config
        // The endpoint must exist (not 404/405)
        $this->assertNotEquals(404, $response->status());
        $this->assertNotEquals(405, $response->status());
    }

    public function test_forgot_password_fails_with_non_existent_email(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'nobody@test.com',
        ]);

        // Laravel Password broker sends 400 for unknown email
        $this->assertContains($response->status(), [400, 422]);
    }
}
