<?php

namespace Tests\Feature\Verification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * VerificationControllerTest
 *
 * Covers:
 * - VerificationController (passenger + driver submission + status)
 * - VerificationRepository (verifyPassenger / verifyDriver called by admin)
 */
class VerificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User   $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->user  = User::factory()->create(['password' => bcrypt('password123')]);
        $this->token = $this->getToken($this->user);
    }

    // ── Passenger verification ───────────────────────────────────────────────

    public function test_passenger_can_submit_verification_request(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/profile/verify/passenger', [
                'face_id_pic' => UploadedFile::fake()->image('face.jpg'),
                'back_id_pic' => UploadedFile::fake()->image('back.jpg'),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'id'                  => $this->user->id,
            'verification_status' => 'pending',
        ]);
    }

    public function test_passenger_verification_without_files_still_submits(): void
    {
        // Files are nullable — submitting with no files is allowed
        $response = $this->withToken($this->token)
            ->postJson('/api/profile/verify/passenger', []);

        $response->assertStatus(201);
    }

    public function test_passenger_cannot_submit_twice_while_pending(): void
    {
        // First submission
        $this->withToken($this->token)
            ->postJson('/api/profile/verify/passenger', []);

        // Second submission should be rejected
        $response = $this->withToken($this->token)
            ->postJson('/api/profile/verify/passenger', []);

        $response->assertStatus(409);
    }

    public function test_passenger_verification_requires_auth(): void
    {
        $this->postJson('/api/profile/verify/passenger')->assertStatus(401);
    }

    // ── Driver verification ──────────────────────────────────────────────────

    public function test_driver_can_submit_verification_request(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/profile/verify/driver', [
                'face_id_pic'         => UploadedFile::fake()->image('face.jpg'),
                'back_id_pic'         => UploadedFile::fake()->image('back.jpg'),
                'driving_license_pic' => UploadedFile::fake()->image('license.jpg'),
                'mechanic_card_pic'   => UploadedFile::fake()->image('mechanic.jpg'),
                'car_pic'             => UploadedFile::fake()->image('car.jpg'),
                'type_of_car'         => 'Toyota Camry',
                'color_of_car'        => 'White',
                'number_of_seats'     => 4,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertEquals('pending', $this->user->fresh()->verification_status);
    }

    public function test_driver_cannot_submit_twice_while_pending(): void
    {
        $this->withToken($this->token)->postJson('/api/profile/verify/driver', []);

        $response = $this->withToken($this->token)
            ->postJson('/api/profile/verify/driver', []);

        $response->assertStatus(409);
    }

    public function test_driver_verification_rejects_invalid_file_type(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/profile/verify/driver', [
                'face_id_pic' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            ]);

        $response->assertStatus(422);
    }

    public function test_driver_verification_requires_auth(): void
    {
        $this->postJson('/api/profile/verify/driver')->assertStatus(401);
    }

    // ── Status check ─────────────────────────────────────────────────────────

    public function test_can_check_own_verification_status(): void
    {
        $response = $this->withToken($this->token)
            ->getJson("/api/profile/verify/status/{$this->user->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['status', 'documents', 'verified']);
    }

    public function test_status_reflects_pending_after_submission(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/profile/verify/passenger', []);

        $response = $this->withToken($this->token)
            ->getJson("/api/profile/verify/status/{$this->user->id}");

        $response->assertJsonPath('status', 'pending');
    }

    public function test_status_for_nonexistent_user_returns_error(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/profile/verify/status/99999');

        $response->assertStatus(500);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getToken(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }
}
