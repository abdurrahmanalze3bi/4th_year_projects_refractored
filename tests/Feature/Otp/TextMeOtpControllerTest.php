<?php

namespace Tests\Feature\Otp;

use App\Models\Otp;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TextMeOtpControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Send ──────────────────────────────────────────────────────────────────

    public function test_send_otp_fails_when_textmebot_disabled(): void
    {
        $this->postJson('/api/textme-otp/send', ['phone_number' => '0983337214'])
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_send_otp_fails_with_invalid_phone(): void
    {
        $this->postJson('/api/textme-otp/send', ['phone_number' => '123'])
            ->assertStatus(422);
    }

    public function test_send_otp_fails_with_missing_phone(): void
    {
        $this->postJson('/api/textme-otp/send', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    // ── Verify ────────────────────────────────────────────────────────────────

    public function test_can_verify_valid_otp(): void
    {
        Otp::create([
            'phone_number' => '+963983337214',
            'otp_code'     => '112233',
            'type'         => 'E-PAYMENT',
            'expires_at'   => Carbon::now()->addMinutes(5),
            'is_verified'  => false,
            'attempts'     => 0,
        ]);

        $this->postJson('/api/textme-otp/verify', [
            'phone_number' => '0983337214',
            'otp_code'     => '112233',
        ])->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_verify_fails_with_wrong_code(): void
    {
        Otp::create([
            'phone_number' => '+963983337214',
            'otp_code'     => '112233',
            'type'         => 'E-PAYMENT',
            'expires_at'   => Carbon::now()->addMinutes(5),
            'is_verified'  => false,
            'attempts'     => 0,
        ]);

        $this->postJson('/api/textme-otp/verify', [
            'phone_number' => '0983337214',
            'otp_code'     => '000000',
        ])->assertStatus(400)->assertJsonPath('success', false);
    }

    public function test_verify_fails_with_expired_otp(): void
    {
        // Use subHours(2) to ensure clearly expired regardless of server timezone
        Otp::create([
            'phone_number' => '+963983337214',
            'otp_code'     => '112233',
            'type'         => 'E-PAYMENT',
            'expires_at'   => Carbon::now()->subHours(2),
            'is_verified'  => false,
            'attempts'     => 0,
        ]);

        $this->postJson('/api/textme-otp/verify', [
            'phone_number' => '0983337214',
            'otp_code'     => '112233',
        ])->assertStatus(400);
    }

    public function test_verify_fails_with_missing_fields(): void
    {
        $this->postJson('/api/textme-otp/verify', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number', 'otp_code']);
    }

    public function test_verify_otp_code_must_be_exactly_6_digits(): void
    {
        $this->postJson('/api/textme-otp/verify', [
            'phone_number' => '0983337214',
            'otp_code'     => '123',
        ])->assertStatus(422);
    }

    public function test_otp_is_marked_verified_after_success(): void
    {
        Otp::create([
            'phone_number' => '+963983337214',
            'otp_code'     => '445566',
            'type'         => 'E-PAYMENT',
            'expires_at'   => Carbon::now()->addMinutes(5),
            'is_verified'  => false,
            'attempts'     => 0,
        ]);

        $this->postJson('/api/textme-otp/verify', [
            'phone_number' => '0983337214',
            'otp_code'     => '445566',
        ]);

        $this->assertTrue((bool) Otp::where('otp_code', '445566')->first()->is_verified);
    }
}
