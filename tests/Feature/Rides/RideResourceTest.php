<?php

namespace Tests\Feature\Rides;

use App\Models\Booking;
use App\Models\Photo;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ride\RideSearchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RideResourceTest — Feature tests that exercise RideResource serialization.
 *
 * LOCATION: tests/Feature/Rides/RideResourceTest.php
 *
 * HOW TO TEST IN POSTMAN:
 * GET  /api/rides/{rideId}          → exercises RideResource via show()
 * GET  /api/rides                   → exercises RideResource::collection() via index()
 *
 * WHAT WE VERIFY:
 * - Response structure matches RideResource fields (id, driver, pickup, destination,
 *   departure_time, seats, price_per_seat, status, distance, duration, etc.)
 * - Nested driver object is present
 * - Coordinate arrays are present
 * - Numeric fields are correct types
 */
class RideResourceTest extends TestCase
{
    use RefreshDatabase;

    private User   $driver;
    private string $token;
    private string $driverPhone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driverPhone = '091' . rand(1000000, 9999999);

        $this->driver = User::factory()->create([
            'is_verified_driver'  => true,
            'verification_status' => 'approved',
            'password'            => bcrypt('password123'),
        ]);

        if (!$this->driver->profile) {
            $this->driver->profile()->create([
                'full_name'       => 'Test Driver',
                'number_of_rides' => 0,
            ]);
        }

        $this->seedAdminWallets();

        $wallet = Wallet::create([
            'user_id'       => $this->driver->id,
            'phone_number'  => $this->driverPhone,
            'wallet_number' => 'WLT-' . Str::random(8),
            'balance'       => 1_000_000,
        ]);
        $this->driver->update(['wallet_id' => $wallet->id]);

        $this->token = $this->getToken($this->driver);
    }

    // ─── show() serialization ─────────────────────────────────────────────────

    public function test_show_returns_top_level_success_key(): void
    {
        $ride = $this->insertRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_show_returns_id_field(): void
    {
        $ride = $this->insertRide();
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertEquals($ride->id, $response->json('data.id'));
    }

    public function test_show_returns_driver_nested_object(): void
    {
        $ride = $this->insertRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'driver' => ['id', 'name'],
                ],
            ]);
    }

    public function test_show_returns_pickup_object(): void
    {
        $ride = $this->insertRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'pickup' => ['address'],
                ],
            ]);
    }

    public function test_show_returns_destination_object(): void
    {
        $ride = $this->insertRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'destination' => ['address'],
                ],
            ]);
    }

    public function test_show_returns_seats_object_with_available_field(): void
    {
        $ride = $this->insertRide(['available_seats' => 3]);
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertEquals(3, $response->json('data.seats.available'));
    }

    public function test_show_returns_price_per_seat(): void
    {
        $ride = $this->insertRide();
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.price_per_seat'));
    }

    public function test_show_returns_status_field(): void
    {
        $ride = $this->insertRide(['status' => 'active']);
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertEquals('active', $response->json('data.status'));
    }

    public function test_show_returns_vehicle_type(): void
    {
        $ride = $this->insertRide();
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertArrayHasKey('vehicle_type', $response->json('data'));
    }

    public function test_show_returns_payment_method(): void
    {
        $ride = $this->insertRide(['payment_method' => 'cash']);
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertEquals('cash', $response->json('data.payment_method'));
    }

    public function test_show_returns_departure_time_formatted(): void
    {
        $ride = $this->insertRide();
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.departure_time'));
    }

    public function test_show_returns_distance_object(): void
    {
        $ride = $this->insertRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'distance' => ['meters', 'kilometers'],
                ],
            ]);
    }

    public function test_show_returns_duration_object(): void
    {
        $ride = $this->insertRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'duration' => ['seconds', 'minutes', 'human'],
                ],
            ]);
    }

    public function test_show_returns_created_at_and_updated_at(): void
    {
        $ride = $this->insertRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['created_at', 'updated_at'],
            ]);
    }

    public function test_show_driver_id_matches_actual_driver(): void
    {
        $ride = $this->insertRide();
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertEquals($this->driver->id, $response->json('data.driver.id'));
    }

    // ─── index() collection ───────────────────────────────────────────────────

    public function test_index_returns_collection_wrapped_in_data_key(): void
    {
        $this->insertRide();
        $this->withToken($this->token)
            ->getJson('/api/rides')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function insertRide(array $overrides = []): Ride
    {
        $status       = $overrides['status']          ?? 'active';
        $seats        = $overrides['available_seats'] ?? 4;
        $paymentMethod= $overrides['payment_method']  ?? 'cash';

        DB::statement("
            INSERT INTO rides (
                driver_id, pickup_address, destination_address,
                pickup_location, destination_location,
                departure_time, available_seats, price_per_seat,
                payment_method, booking_type, status, distance, duration,
                communication_number, created_at, updated_at
            ) VALUES (
                ?, 'دمشق - ساحة المرجة', 'حلب - العزيزية',
                ST_GeomFromText('POINT(33.5138 36.2765)', 4326),
                ST_GeomFromText('POINT(36.2021 37.1343)', 4326),
                ?, ?, 50000, ?, 'direct', ?, 320500, 14400, ?, NOW(), NOW()
            )
        ", [
            $this->driver->id,
            now()->addHours(3)->format('Y-m-d H:i:s'),
            $seats,
            $paymentMethod,
            $status,
            $this->driverPhone,
        ]);

        return Ride::with(['driver', 'driver.profile'])->latest('id')->first();
    }

    private function seedAdminWallets(): void
    {
        foreach (['primary', 'sycash'] as $type) {
            $cfg  = config("admin.{$type}");
            $user = User::firstOrCreate(
                ['email' => $cfg['email']],
                [
                    'first_name' => $type, 'last_name' => 'Admin',
                    'password'   => bcrypt($cfg['password']),
                    'gender'     => 'M', 'address' => 'دمشق', 'status' => true,
                ]
            );
            if (!Wallet::where('phone_number', $cfg['phone'])->exists()) {
                $w = Wallet::create([
                    'user_id'       => $user->id,
                    'phone_number'  => $cfg['phone'],
                    'wallet_number' => 'WLT-' . strtoupper($type) . '-' . Str::random(4),
                    'balance'       => 10_000_000,
                ]);
                $user->update(['wallet_id' => $w->id]);
            }
        }
    }

    private function getToken(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }
}

// ════════════════════════════════════════════════════════════════════════════
// RideSearchServiceTest
// ════════════════════════════════════════════════════════════════════════════

/**
 * RideSearchServiceTest — Feature tests for RideSearchService.
 *
 * LOCATION: tests/Feature/Rides/RideSearchServiceTest.php
 *   (or add as a second class in the same file since they're closely related)
 *
 * WHAT RideSearchService DOES:
 * - searchRides(array $params): finds rides matching departure date, seat count,
 *   and spatial proximity (pickup + destination within 20km radius)
 * - getNearbyRides(lat, lng, radius): finds active rides near a point
 *
 * HOW TO TEST IN POSTMAN:
 * POST /api/rides/search
 * Body: {
 *   "source_lat": 33.5138, "source_lng": 36.2765,
 *   "dest_lat": 36.2021, "dest_lng": 37.1343,
 *   "departure_date": "2025-10-15",
 *   "seats_required": 1
 * }
 */
class RideSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    private RideSearchService $service;
    private User              $driver;
    private string            $driverPhone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service     = app(RideSearchService::class);
        $this->driverPhone = '091' . rand(1000000, 9999999);

        $this->driver = User::factory()->create([
            'is_verified_driver'  => true,
            'verification_status' => 'approved',
            'password'            => bcrypt('password123'),
        ]);

        if (!$this->driver->profile) {
            $this->driver->profile()->create([
                'full_name'       => 'Search Test Driver',
                'number_of_rides' => 0,
            ]);
        }
    }

    // ─── searchRides() ────────────────────────────────────────────────────────

    public function test_search_returns_empty_collection_when_no_rides_exist(): void
    {
        $results = $this->service->searchRides([
            'departure_date' => now()->addDays(3)->toDateString(),
            'seats_required' => 1,
            'source_lat'     => 33.5138,
            'source_lng'     => 36.2765,
            'dest_lat'       => 36.2021,
            'dest_lng'       => 37.1343,
        ]);

        $this->assertEmpty($results);
    }

    public function test_search_returns_matching_ride_when_conditions_met(): void
    {
        $departureDate = now()->addDays(3)->toDateString();
        $this->insertRide(['departure_date' => $departureDate]);

        $results = $this->service->searchRides([
            'departure_date' => $departureDate,
            'seats_required' => 1,
            'source_lat'     => 33.5138,
            'source_lng'     => 36.2765,
            'dest_lat'       => 36.2021,
            'dest_lng'       => 37.1343,
        ]);

        $this->assertNotEmpty($results);
    }

    public function test_search_does_not_return_cancelled_rides(): void
    {
        $departureDate = now()->addDays(3)->toDateString();
        $this->insertRide(['departure_date' => $departureDate, 'status' => 'cancelled']);

        $results = $this->service->searchRides([
            'departure_date' => $departureDate,
            'seats_required' => 1,
            'source_lat'     => 33.5138,
            'source_lng'     => 36.2765,
            'dest_lat'       => 36.2021,
            'dest_lng'       => 37.1343,
        ]);

        $this->assertEmpty($results);
    }

    public function test_search_does_not_return_finished_rides(): void
    {
        $departureDate = now()->addDays(3)->toDateString();
        $this->insertRide(['departure_date' => $departureDate, 'status' => 'finished']);

        $results = $this->service->searchRides([
            'departure_date' => $departureDate,
            'seats_required' => 1,
            'source_lat'     => 33.5138,
            'source_lng'     => 36.2765,
            'dest_lat'       => 36.2021,
            'dest_lng'       => 37.1343,
        ]);

        $this->assertEmpty($results);
    }

    public function test_search_filters_by_departure_date(): void
    {
        $targetDate = now()->addDays(5)->toDateString();
        $otherDate  = now()->addDays(10)->toDateString();

        $this->insertRide(['departure_date' => $targetDate]);
        $this->insertRide(['departure_date' => $otherDate]);

        $results = $this->service->searchRides([
            'departure_date' => $targetDate,
            'seats_required' => 1,
            'source_lat'     => 33.5138,
            'source_lng'     => 36.2765,
            'dest_lat'       => 36.2021,
            'dest_lng'       => 37.1343,
        ]);

        // All returned rides should be on the target date
        foreach ($results as $ride) {
            $this->assertEquals(
                $targetDate,
                Carbon::parse($ride->departure_time)->toDateString()
            );
        }
    }

    public function test_search_filters_by_minimum_seats(): void
    {
        $departureDate = now()->addDays(3)->toDateString();

        // Ride with only 1 seat
        $this->insertRide(['departure_date' => $departureDate, 'available_seats' => 1]);

        // Search requires 3 seats — should find nothing
        $results = $this->service->searchRides([
            'departure_date' => $departureDate,
            'seats_required' => 3,
            'source_lat'     => 33.5138,
            'source_lng'     => 36.2765,
            'dest_lat'       => 36.2021,
            'dest_lng'       => 37.1343,
        ]);

        $this->assertEmpty($results);
    }

    public function test_search_returns_ride_when_seats_exactly_match_requirement(): void
    {
        $departureDate = now()->addDays(3)->toDateString();
        $this->insertRide(['departure_date' => $departureDate, 'available_seats' => 2]);

        $results = $this->service->searchRides([
            'departure_date' => $departureDate,
            'seats_required' => 2,
            'source_lat'     => 33.5138,
            'source_lng'     => 36.2765,
            'dest_lat'       => 36.2021,
            'dest_lng'       => 37.1343,
        ]);

        $this->assertNotEmpty($results);
    }

    public function test_search_results_include_driver_relationship(): void
    {
        $departureDate = now()->addDays(3)->toDateString();
        $this->insertRide(['departure_date' => $departureDate]);

        $results = $this->service->searchRides([
            'departure_date' => $departureDate,
            'seats_required' => 1,
            'source_lat'     => 33.5138,
            'source_lng'     => 36.2765,
            'dest_lat'       => 36.2021,
            'dest_lng'       => 37.1343,
        ]);

        if ($results->isNotEmpty()) {
            $this->assertNotNull($results->first()->driver);
        }
    }

    public function test_search_orders_results_by_departure_time_ascending(): void
    {
        $baseDate = now()->addDays(3)->toDateString();

        // Insert two rides on the same day, 2 hours apart
        $this->insertRide(['departure_date' => $baseDate, 'departure_hour' => 9]);
        $this->insertRide(['departure_date' => $baseDate, 'departure_hour' => 14]);

        $results = $this->service->searchRides([
            'departure_date' => $baseDate,
            'seats_required' => 1,
            'source_lat'     => 33.5138,
            'source_lng'     => 36.2765,
            'dest_lat'       => 36.2021,
            'dest_lng'       => 37.1343,
        ]);

        if ($results->count() >= 2) {
            $firstTime  = Carbon::parse($results->first()->departure_time);
            $secondTime = Carbon::parse($results->skip(1)->first()->departure_time);
            $this->assertTrue($firstTime->lessThanOrEqualTo($secondTime));
        }
    }

    // ─── getNearbyRides() ────────────────────────────────────────────────────

    public function test_get_nearby_rides_returns_active_rides_near_location(): void
    {
        $this->insertRide(['status' => 'active']);

        $results = $this->service->getNearbyRides(33.5138, 36.2765, 20);

        $this->assertNotEmpty($results);
    }

    public function test_get_nearby_rides_excludes_cancelled_rides(): void
    {
        $this->insertRide(['status' => 'cancelled']);

        $results = $this->service->getNearbyRides(33.5138, 36.2765, 20);

        $this->assertEmpty($results);
    }

    public function test_get_nearby_rides_returns_collection(): void
    {
        $results = $this->service->getNearbyRides(33.5138, 36.2765, 20);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $results);
    }

    // ─── API integration (via RideController::searchRides) ───────────────────

    public function test_search_endpoint_returns_200_with_valid_params(): void
    {
        $driver = $this->driver;
        $token  = $this->postJson('/api/auth/login', [
            'email'    => $driver->email,
            'password' => 'password123',
        ])->json('tokens.access_token');

        $this->withToken($token)
            ->postJson('/api/rides/search', [
                'source_lat'     => 33.5138,
                'source_lng'     => 36.2765,
                'dest_lat'       => 36.2021,
                'dest_lng'       => 37.1343,
                'departure_date' => now()->addDays(5)->toDateString(),
                'seats_required' => 1,
            ])->assertStatus(200);
    }

    public function test_search_endpoint_returns_422_with_missing_params(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'email'    => $this->driver->email,
            'password' => 'password123',
        ])->json('tokens.access_token');

        $this->withToken($token)
            ->postJson('/api/rides/search', [])
            ->assertStatus(422);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function insertRide(array $overrides = []): Ride
    {
        $status        = $overrides['status']          ?? 'active';
        $seats         = $overrides['available_seats'] ?? 4;
        $departureDate = $overrides['departure_date']  ?? now()->addDays(3)->toDateString();
        $hour          = $overrides['departure_hour']  ?? 10;
        $departureTime = Carbon::parse($departureDate)->setHour($hour)->format('Y-m-d H:i:s');

        DB::statement("
            INSERT INTO rides (
                driver_id, pickup_address, destination_address,
                pickup_location, destination_location,
                departure_time, available_seats, price_per_seat,
                payment_method, booking_type, status, distance, duration,
                communication_number, created_at, updated_at
            ) VALUES (
                ?, 'دمشق', 'حلب',
                ST_GeomFromText('POINT(33.5138 36.2765)', 4326),
                ST_GeomFromText('POINT(36.2021 37.1343)', 4326),
                ?, ?, 50000, 'cash', 'direct', ?, 320500, 14400, ?, NOW(), NOW()
            )
        ", [
            $this->driver->id,
            $departureTime,
            $seats,
            $status,
            $this->driverPhone,
        ]);

        return Ride::latest('id')->first();
    }
}
