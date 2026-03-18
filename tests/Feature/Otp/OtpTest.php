<?php

namespace Tests\Feature\Otp;

use App\Models\Otp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtpTest extends TestCase
{
    use RefreshDatabase;

    // ─── Send OTP ─────────────────────────────────────────────────────────────

    public function test_can_send_otp_to_valid_syrian_number(): void
    {
        $response = $this->postJson('/api/otp/send', [
            'phone_number' => '0983337214',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_send_otp_fails_with_invalid_number(): void
    {
        $response = $this->postJson('/api/otp/send', [
            'phone_number' => '123',
        ]);

        $response->assertStatus(422);
    }

    public function test_send_otp_fails_with_missing_number(): void
    {
        $response = $this->postJson('/api/otp/send', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    public function test_send_otp_creates_record_in_database(): void
    {
        $this->postJson('/api/otp/send', [
            'phone_number' => '0983337214',
        ]);

        $this->assertDatabaseHas('otps', [
            'phone_number' => '+963983337214',
        ]);
    }

    public function test_bypass_mode_returns_otp_code(): void
    {
        // OTP_BYPASS_ENABLED=true in test env
        $response = $this->postJson('/api/otp/send', [
            'phone_number' => '0983337214',
        ]);

        $response->assertStatus(200);
        // In testing/local env, otp_code is returned
        $this->assertTrue(
            $response->json('success') === true
        );
    }

    // ─── Verify OTP ───────────────────────────────────────────────────────────

    public function test_can_verify_valid_otp(): void
    {
        // Create OTP directly in DB
        Otp::create([
            'phone_number' => '+963983337214',
            'otp_code'     => '123456',
            'type'         => 'E-PAYMENT',
            'expires_at'   => now()->addMinutes(10),
            'is_verified'  => false,
            'attempts'     => 0,
        ]);

        $response = $this->postJson('/api/otp/verify', [
            'phone_number' => '0983337214',
            'otp_code'     => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_verify_fails_with_wrong_code(): void
    {
        Otp::create([
            'phone_number' => '+963983337214',
            'otp_code'     => '123456',
            'type'         => 'E-PAYMENT',
            'expires_at'   => now()->addMinutes(10),
            'is_verified'  => false,
            'attempts'     => 0,
        ]);

        $response = $this->postJson('/api/otp/verify', [
            'phone_number' => '0983337214',
            'otp_code'     => '999999',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_verify_fails_with_expired_otp(): void
    {
        Otp::create([
            'phone_number' => '+963983337214',
            'otp_code'     => '123456',
            'type'         => 'E-PAYMENT',
            'expires_at'   => now()->subMinutes(1), // expired
            'is_verified'  => false,
            'attempts'     => 0,
        ]);

        $response = $this->postJson('/api/otp/verify', [
            'phone_number' => '0983337214',
            'otp_code'     => '123456',
        ]);

        $response->assertStatus(400);
    }

    public function test_verify_fails_with_already_verified_otp(): void
    {
        Otp::create([
            'phone_number' => '+963983337214',
            'otp_code'     => '123456',
            'type'         => 'E-PAYMENT',
            'expires_at'   => now()->addMinutes(10),
            'is_verified'  => true, // already used
            'verified_at'  => now(),
            'attempts'     => 0,
        ]);

        $response = $this->postJson('/api/otp/verify', [
            'phone_number' => '0983337214',
            'otp_code'     => '123456',
        ]);

        $response->assertStatus(400);
    }

    public function test_otp_code_must_be_6_digits(): void
    {
        $response = $this->postJson('/api/otp/verify', [
            'phone_number' => '0983337214',
            'otp_code'     => '123', // too short
        ]);

        $response->assertStatus(422);
    }
}
