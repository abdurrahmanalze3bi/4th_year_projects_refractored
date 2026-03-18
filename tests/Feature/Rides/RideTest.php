<?php

namespace Tests\Feature\Rides;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RideTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = User::factory()->create([
            'is_verified_driver'  => true,
            'verification_status' => 'approved',
            'password'            => bcrypt('password123'),
        ]);

        // Create wallet with enough balance for creation fee
        Wallet::create([
            'user_id'      => $this->driver->id,
            'phone_number' => '0912345678',
            'balance'      => 1_000_000,
        ]);

        $this->token = $this->getToken($this->driver);
    }

    // ─── Create ride ──────────────────────────────────────────────────────────

    public function test_verified_driver_can_create_ride(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/rides', $this->validRidePayload());

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'status']]);
    }

    public function test_unverified_driver_cannot_create_ride(): void
    {
        $unverified = User::factory()->create([
            'is_verified_driver' => false,
            'password'           => bcrypt('password123'),
        ]);

        $token = $this->getToken($unverified);

        $response = $this->withToken($token)
            ->postJson('/api/rides', $this->validRidePayload());

        $response->assertStatus(422);
    }

    public function test_ride_creation_charges_5_percent_fee(): void
    {
        $walletBefore = Wallet::where('user_id', $this->driver->id)->first()->balance;

        $this->withToken($this->token)
            ->postJson('/api/rides', array_merge($this->validRidePayload(), [
                'price_per_seat'  => 10_000,
                'available_seats' => 4,
            ]));

        $walletAfter = Wallet::where('user_id', $this->driver->id)->fresh()->first()->balance;
        $expectedFee = 10_000 * 4 * 0.05; // = 2000 SYP

        $this->assertEquals($walletBefore - $expectedFee, $walletAfter);
    }

    public function test_ride_creation_fails_with_past_departure_time(): void
    {
        $payload = $this->validRidePayload();
        $payload['departure_time'] = now()->subHour()->toDateTimeString();

        $response = $this->withToken($this->token)
            ->postJson('/api/rides', $payload);

        $response->assertStatus(422);
    }

    public function test_ride_creation_requires_authentication(): void
    {
        $response = $this->postJson('/api/rides', $this->validRidePayload());
        $response->assertStatus(401);
    }

    // ─── Get rides ────────────────────────────────────────────────────────────

    public function test_driver_can_get_own_rides(): void
    {
        Ride::factory()->count(3)->create(['driver_id' => $this->driver->id]);

        $response = $this->withToken($this->token)->getJson('/api/rides');

        $response->assertStatus(200);
    }

    public function test_can_get_ride_details(): void
    {
        $ride = Ride::factory()->create(['driver_id' => $this->driver->id]);

        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
    }

    public function test_get_nonexistent_ride_returns_404(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/rides/99999');

        $response->assertStatus(404);
    }

    // ─── Cancel ride ──────────────────────────────────────────────────────────

    public function test_driver_can_cancel_own_ride(): void
    {
        $ride = Ride::factory()->create([
            'driver_id'      => $this->driver->id,
            'status'         => 'active',
            'departure_time' => now()->addHours(3),
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/api/rides/{$ride->id}/cancel");

        $response->assertStatus(200);
        $this->assertDatabaseHas('rides', ['id' => $ride->id, 'status' => 'cancelled']);
    }

    public function test_driver_cannot_cancel_others_ride(): void
    {
        $otherDriver = User::factory()->create(['is_verified_driver' => true]);
        $ride = Ride::factory()->create([
            'driver_id'      => $otherDriver->id,
            'status'         => 'active',
            'departure_time' => now()->addHours(3),
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/api/rides/{$ride->id}/cancel");

        $response->assertStatus(500);
    }

    public function test_already_cancelled_ride_cannot_be_cancelled_again(): void
    {
        $ride = Ride::factory()->create([
            'driver_id'      => $this->driver->id,
            'status'         => 'cancelled',
            'departure_time' => now()->addHours(3),
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/api/rides/{$ride->id}/cancel");

        $response->assertStatus(500);
    }

    // ─── Finish ride ──────────────────────────────────────────────────────────

    public function test_driver_can_finish_active_ride(): void
    {
        $ride = Ride::factory()->create([
            'driver_id'      => $this->driver->id,
            'status'         => 'active',
            'departure_time' => now()->subMinutes(10),
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/api/rides/{$ride->id}/finish");

        $response->assertStatus(200);
    }

    public function test_cannot_finish_ride_before_departure_time(): void
    {
        $ride = Ride::factory()->create([
            'driver_id'      => $this->driver->id,
            'status'         => 'active',
            'departure_time' => now()->addHours(2),
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/api/rides/{$ride->id}/finish");

        $response->assertStatus(400);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function validRidePayload(): array
    {
        return [
            'pickup_address'       => 'دمشق - ساحة المرجة',
            'destination_address'  => 'حلب - العزيزية',
            'departure_time'       => now()->addHours(2)->toDateTimeString(),
            'available_seats'      => 3,
            'price_per_seat'       => 50_000,
            'payment_method'       => 'cash',
            'booking_type'         => 'direct',
            'vehicle_type'         => 'sedan',
            'communication_number' => '0912345678',
        ];
    }

    private function getToken(User $user): string
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ]);
        return $response->json('tokens.access_token');
    }
}
