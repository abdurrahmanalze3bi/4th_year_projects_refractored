<?php

namespace Tests\Feature\Rides;

use App\Models\Photo;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RideTest extends TestCase
{
    use RefreshDatabase;

    private User   $driver;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = User::factory()->create([
            'is_verified_driver'  => true,
            'verification_status' => 'approved',
            'password'            => bcrypt('password123'),
        ]);

        // Profile
        if (!$this->driver->profile) {
            $this->driver->profile()->create(['full_name' => 'Test Driver', 'number_of_rides' => 0]);
        }

        // Required verification documents — without these, ride creation returns 422
        foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $type) {
            Photo::create([
                'user_id' => $this->driver->id,
                'type'    => $type,
                'path'    => "verifications/{$type}/test.jpg",
            ]);
        }

        $this->seedAdminWallets();

        $wallet = Wallet::create([
            'user_id'       => $this->driver->id,
            'phone_number'  => '0912345678',
            'wallet_number' => 'WLT-DRIVER-001',
            'balance'       => 1_000_000,
        ]);
        $this->driver->update(['wallet_id' => $wallet->id]);

        $this->token = $this->getToken($this->driver);
    }

    // ── Create ride ───────────────────────────────────────────────────────────

    public function test_verified_driver_can_create_ride(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/rides/create-with-route', $this->validRidePayload())
            ->assertStatus(201);
    }

    public function test_unverified_driver_cannot_create_ride(): void
    {
        $unverified = User::factory()->create([
            'is_verified_driver' => false,
            'password'           => bcrypt('password123'),
        ]);

        $this->withToken($this->getToken($unverified))
            ->postJson('/api/rides/create-with-route', $this->validRidePayload())
            ->assertStatus(500);
    }

    public function test_ride_creation_charges_5_percent_fee(): void
    {
        $walletBefore = (float) Wallet::where('user_id', $this->driver->id)->value('balance');

        $this->withToken($this->token)
            ->postJson('/api/rides/create-with-route', array_merge($this->validRidePayload(), [
                'price_per_seat'  => 10_000,
                'available_seats' => 4,
            ]))->assertStatus(201);

        $walletAfter = (float) Wallet::where('user_id', $this->driver->id)->value('balance');
        $expectedFee = 10_000 * 4 * 0.05; // 2000

        $this->assertEquals($walletBefore - $expectedFee, $walletAfter);
    }

    public function test_ride_creation_fails_with_past_departure_time(): void
    {
        $payload = array_merge($this->validRidePayload(), [
            'departure_time' => now()->subHour()->toISOString(),
        ]);

        $this->withToken($this->token)
            ->postJson('/api/rides/create-with-route', $payload)
            ->assertStatus(422);
    }

    public function test_ride_creation_requires_authentication(): void
    {
        $this->postJson('/api/rides/create-with-route', $this->validRidePayload())
            ->assertStatus(401);
    }

    // ── Get rides ─────────────────────────────────────────────────────────────

    public function test_driver_can_get_own_rides(): void
    {
        $this->insertRide();

        $this->withToken($this->token)->getJson('/api/rides')->assertStatus(200);
    }

    public function test_can_get_ride_details(): void
    {
        $ride = $this->insertRide();

        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200);
    }

    public function test_get_nonexistent_ride_returns_404(): void
    {
        $this->withToken($this->token)->getJson('/api/rides/99999')->assertStatus(404);
    }

    // ── Cancel ride ───────────────────────────────────────────────────────────

    public function test_driver_can_cancel_own_ride(): void
    {
        $ride = $this->insertRide();

        $this->withToken($this->token)
            ->patchJson("/api/rides/{$ride->id}/cancel")
            ->assertStatus(200);

        $this->assertDatabaseHas('rides', ['id' => $ride->id, 'status' => 'cancelled']);
    }

    public function test_driver_cannot_cancel_others_ride(): void
    {
        $other = User::factory()->create();
        $ride  = $this->insertRide(['driver_id' => $other->id]);

        $this->withToken($this->token)
            ->patchJson("/api/rides/{$ride->id}/cancel")
            ->assertStatus(422);
    }

    public function test_already_cancelled_ride_cannot_be_cancelled_again(): void
    {
        $ride = $this->insertRide(['status' => 'cancelled']);

        $this->withToken($this->token)
            ->patchJson("/api/rides/{$ride->id}/cancel")
            ->assertStatus(422);
    }

    // ── Finish ride ───────────────────────────────────────────────────────────

    public function test_driver_can_finish_active_ride(): void
    {
        $ride = $this->insertRide(['departure_time' => now()->subMinutes(10)]);

        $this->withToken($this->token)
            ->postJson("/api/rides/{$ride->id}/finish")
            ->assertSuccessful();
    }

    public function test_cannot_finish_ride_before_departure_time(): void
    {
        $ride = $this->insertRide(['departure_time' => now()->addHours(2)]);

        $this->withToken($this->token)
            ->postJson("/api/rides/{$ride->id}/finish")
            ->assertStatus(400);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function insertRide(array $overrides = []): Ride
    {
        $driverId      = $overrides['driver_id']      ?? $this->driver->id;
        $status        = $overrides['status']         ?? 'active';
        $departureTime = $overrides['departure_time'] ?? now()->addHours(3);

        $departureStr = $departureTime instanceof \Carbon\Carbon
            ? $departureTime->format('Y-m-d H:i:s')
            : $departureTime;

        DB::statement("
            INSERT INTO rides
                (driver_id, pickup_address, destination_address,
                 pickup_location, destination_location,
                 departure_time, available_seats, price_per_seat,
                 payment_method, booking_type, status,
                 distance, duration, communication_number,
                 created_at, updated_at)
            VALUES (?, ?, ?,
                 ST_GeomFromText('POINT(33.5138 36.2765)'),
                 ST_GeomFromText('POINT(36.2021 37.1343)'),
                 ?, 4, 50000, 'cash', 'direct', ?, 320.5, 240, '0912345678', NOW(), NOW())
        ", [$driverId, 'دمشق', 'حلب', $departureStr, $status]);

        return Ride::latest('id')->first();
    }

    private function seedAdminWallets(): void
    {
        foreach (['primary', 'sycash'] as $type) {
            $cfg  = config("admin.{$type}");
            $user = User::firstOrCreate(
                ['email' => $cfg['email']],
                ['first_name' => $type, 'last_name' => 'Admin',
                    'password' => bcrypt($cfg['password']),
                    'gender' => 'M', 'address' => 'دمشق', 'status' => true]
            );
            if (!$user->wallet_id) {
                $w = Wallet::create([
                    'user_id'       => $user->id,
                    'phone_number'  => $cfg['phone'],
                    'wallet_number' => 'WLT-' . strtoupper($type) . '-001',
                    'balance'       => 10_000_000,
                ]);
                $user->update(['wallet_id' => $w->id]);
            }
        }
    }

    private function validRidePayload(): array
    {
        return [
            'pickup_lat'           => 33.5138,
            'pickup_lng'           => 36.2765,
            'destination_lat'      => 36.2021,
            'destination_lng'      => 37.1343,
            'pickup_address'       => 'دمشق - ساحة المرجة',
            'destination_address'  => 'حلب - العزيزية',
            'departure_time'       => now()->addHours(48)->toISOString(),
            'available_seats'      => 3,
            'price_per_seat'       => 50_000,
            'payment_method'       => 'cash',
            'booking_type'         => 'direct',
            'communication_number' => '0912345678',
            'route_index'          => 0,
        ];
    }

    private function getToken(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }
}
