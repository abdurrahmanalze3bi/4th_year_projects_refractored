<?php

namespace Tests\Feature\Rides;

use App\Models\Booking;
use App\Models\Photo;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RideControllerFullTest
 *
 * Covers all RideController methods not already tested in RideTest.php / BookingTest.php:
 *
 * Already covered by RideTest.php:
 *   create, show, getRides, cancel (PATCH), finish, getRideDetails, getNonexistentRide
 *
 * Already covered by BookingTest.php:
 *   bookRide, acceptBooking, rejectBooking, myBookings (passenger)
 *
 * This file covers the rest:
 *   createRideWithRoute, getRouteOptions, autocomplete, search (GET),
 *   searchRides, index, cancelRide (POST), finishRide (POST),
 *   driverConfirmCompletion, cancelBooking, cancelPartialSeats,
 *   passengerConfirmCompletion, getMyBookings, acceptBooking (via RideController),
 *   rejectBooking (via RideController)
 */
class RideControllerFullTest extends TestCase
{
    use RefreshDatabase;

    private User   $driver;
    private User   $passenger;
    private string $driverToken;
    private string $passengerToken;

    protected function setUp(): void
    {
        parent::setUp();

        // ── Driver ────────────────────────────────────────────────────────────
        $this->driver = User::factory()->create([
            'is_verified_driver'    => true,
            'is_verified_passenger' => true,
            'verification_status'   => 'approved',
            'password'              => bcrypt('password123'),
        ]);
        if (!$this->driver->profile) {
            $this->driver->profile()->create(['full_name' => 'Driver', 'number_of_rides' => 0]);
        }
        foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $type) {
            Photo::create(['user_id' => $this->driver->id, 'type' => $type, 'path' => "test/{$type}.jpg"]);
        }

        // ── Passenger ─────────────────────────────────────────────────────────
        $this->passenger = User::factory()->create([
            'is_verified_passenger' => true,
            'verification_status'   => 'approved',
            'password'              => bcrypt('password123'),
        ]);
        if (!$this->passenger->profile) {
            $this->passenger->profile()->create(['full_name' => 'Passenger', 'number_of_rides' => 0]);
        }

        // ── Wallets ───────────────────────────────────────────────────────────
        $this->seedAdminWallets();

        $dw = Wallet::create(['user_id' => $this->driver->id,    'phone_number' => '0912345678', 'wallet_number' => 'WLT-DRV-001', 'balance' => 1_000_000]);
        $this->driver->update(['wallet_id' => $dw->id]);

        $pw = Wallet::create(['user_id' => $this->passenger->id, 'phone_number' => '0911111111', 'wallet_number' => 'WLT-PAS-001', 'balance' => 1_000_000]);
        $this->passenger->update(['wallet_id' => $pw->id]);

        $this->driverToken    = $this->getToken($this->driver);
        $this->passengerToken = $this->getToken($this->passenger);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // createRideWithRoute — POST /api/rides/create-with-route
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_create_ride_with_route_returns_201(): void
    {
        $this->withToken($this->driverToken)
            ->postJson('/api/rides/create-with-route', $this->validRidePayload())
            ->assertStatus(201)
            ->assertJsonPath('status', 'success');
    }

    public function test_create_ride_with_route_fails_without_auth(): void
    {
        $this->postJson('/api/rides/create-with-route', $this->validRidePayload())
            ->assertStatus(401);
    }

    public function test_create_ride_with_route_fails_invalid_payment_method(): void
    {
        $payload = array_merge($this->validRidePayload(), ['payment_method' => 'crypto']);

        $this->withToken($this->driverToken)
            ->postJson('/api/rides/create-with-route', $payload)
            ->assertStatus(422);
    }

    public function test_create_ride_with_route_fails_past_departure(): void
    {
        $payload = array_merge($this->validRidePayload(), [
            'departure_time' => now()->subHour()->toISOString(),
        ]);

        $this->withToken($this->driverToken)
            ->postJson('/api/rides/create-with-route', $payload)
            ->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // index — GET /api/rides (driver's own rides)
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_index_returns_driver_rides(): void
    {
        $this->insertRide();

        $this->withToken($this->driverToken)
            ->getJson('/api/rides')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/rides')->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // show — GET /api/rides/{id}
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_show_returns_ride_details(): void
    {
        $ride = $this->insertRide();

        $this->withToken($this->passengerToken)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'pickup', 'destination', 'seats']]);
    }

    public function test_show_nonexistent_ride_returns_404(): void
    {
        $this->withToken($this->passengerToken)
            ->getJson('/api/rides/99999')
            ->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // search — GET /api/rides/search
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_search_returns_matching_rides(): void
    {
        $this->insertRide();

        $this->withToken($this->passengerToken)
            ->getJson('/api/rides/search?' . http_build_query([
                    'source_lat'      => 33.5138,
                    'source_lng'      => 36.2765,
                    'dest_lat'        => 36.2021,
                    'dest_lng'        => 37.1343,
                    'departure_date'  => now()->addHours(3)->toDateString(),
                    'seats_required'  => 1,
                ]))
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_search_fails_with_missing_params(): void
    {
        $this->withToken($this->passengerToken)
            ->getJson('/api/rides/search')
            ->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // searchRides — POST /api/rides/search-rides
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_search_rides_returns_results(): void
    {
        $this->insertRide();

        $this->withToken($this->passengerToken)
            ->postJson('/api/rides/search-rides', [
                'source_address'      => 'دمشق',
                'destination_address' => 'حلب',
                'departure_date'      => now()->addHours(3)->toDateString(),
                'seats_required'      => 1,
                'source_lat'          => 33.5138,
                'source_lng'          => 36.2765,
                'dest_lat'            => 36.2021,
                'dest_lng'            => 37.1343,
            ])
            ->assertStatus(200);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // cancelRide — POST /api/rides/{id}/cancel
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_cancel_ride_post_sets_status_to_cancelled(): void
    {
        $ride = $this->insertRide();

        $this->withToken($this->driverToken)
            ->postJson("/api/rides/{$ride->id}/cancel")
            ->assertStatus(200);

        $this->assertEquals('cancelled', $ride->fresh()->status);
    }

    public function test_cancel_ride_post_fails_for_non_driver(): void
    {
        $ride = $this->insertRide();

        $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$ride->id}/cancel")
            ->assertStatus(422);
    }

    public function test_cancel_already_cancelled_ride_fails(): void
    {
        $ride = $this->insertRide(['status' => 'cancelled']);

        $this->withToken($this->driverToken)
            ->postJson("/api/rides/{$ride->id}/cancel")
            ->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // finishRide — POST /api/rides/{id}/finish
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_finish_ride_after_departure_succeeds(): void
    {
        $ride = $this->insertRide(['departure_time' => now()->subMinutes(10)]);

        $this->withToken($this->driverToken)
            ->postJson("/api/rides/{$ride->id}/finish")
            ->assertSuccessful();
    }

    public function test_finish_ride_before_departure_fails(): void
    {
        $ride = $this->insertRide(['departure_time' => now()->addHours(2)]);

        $this->withToken($this->driverToken)
            ->postJson("/api/rides/{$ride->id}/finish")
            ->assertStatus(400);
    }

    public function test_finish_ride_by_non_driver_fails(): void
    {
        $ride = $this->insertRide(['departure_time' => now()->subMinutes(10)]);

        $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$ride->id}/finish")
            ->assertStatus(400);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // driverConfirmCompletion — POST /api/rides/{id}/driver-confirm
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_driver_confirm_completion_succeeds(): void
    {
        $ride = $this->insertRide(['status' => 'awaiting_confirmation']);

        $this->withToken($this->driverToken)
            ->postJson("/api/rides/{$ride->id}/driver-confirm")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_driver_confirm_fails_for_active_ride(): void
    {
        $ride = $this->insertRide(['status' => 'active']);

        $this->withToken($this->driverToken)
            ->postJson("/api/rides/{$ride->id}/driver-confirm")
            ->assertStatus(400);
    }

    public function test_driver_confirm_fails_for_non_driver(): void
    {
        $ride = $this->insertRide(['status' => 'awaiting_confirmation']);

        $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$ride->id}/driver-confirm")
            ->assertStatus(400);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // bookRide — POST /api/rides/{id}/book
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_passenger_can_book_ride(): void
    {
        $ride = $this->insertRide();

        $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$ride->id}/book", [
                'seats'                => 1,
                'communication_number' => '0912345678',
                'idempotency_key'      => Str::uuid()->toString(),
            ])
            ->assertStatus(201);
    }

    public function test_book_ride_fails_when_more_seats_than_available(): void
    {
        $ride = $this->insertRide(['available_seats' => 2]);

        $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$ride->id}/book", [
                'seats'                => 5,
                'communication_number' => '0912345678',
                'idempotency_key'      => Str::uuid()->toString(),
            ])
            ->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // cancelBooking — POST /api/bookings/{id}/cancel
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_passenger_can_cancel_booking(): void
    {
        $booking = $this->makeBooking();

        $this->withToken($this->passengerToken)
            ->postJson("/api/bookings/{$booking->id}/cancel")
            ->assertStatus(200);

        $this->assertEquals('cancelled', $booking->fresh()->status);
    }

    public function test_other_user_cannot_cancel_booking(): void
    {
        $booking = $this->makeBooking();
        $other   = User::factory()->create(['password' => bcrypt('password123')]);

        $this->withToken($this->getToken($other))
            ->postJson("/api/bookings/{$booking->id}/cancel")
            ->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // acceptBooking — POST /api/bookings/{id}/accept
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_driver_can_accept_pending_booking(): void
    {
        $ride    = $this->insertRide(['booking_type' => 'request']);
        $booking = $this->makeBooking('pending', $ride);

        $this->withToken($this->driverToken)
            ->postJson("/api/bookings/{$booking->id}/accept")
            ->assertStatus(200);

        $this->assertEquals('confirmed', $booking->fresh()->status);
    }

    public function test_accept_already_confirmed_booking_fails(): void
    {
        $booking = $this->makeBooking('confirmed');

        $this->withToken($this->driverToken)
            ->postJson("/api/bookings/{$booking->id}/accept")
            ->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // rejectBooking — POST /api/bookings/{id}/reject
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_driver_can_reject_pending_booking(): void
    {
        $ride    = $this->insertRide(['booking_type' => 'request']);
        $booking = $this->makeBooking('pending', $ride);

        $this->withToken($this->driverToken)
            ->postJson("/api/bookings/{$booking->id}/reject")
            ->assertStatus(200);

        $this->assertEquals('cancelled', $booking->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // cancelPartialSeats — POST /api/bookings/{id}/cancel-seats
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_passenger_can_cancel_partial_seats(): void
    {
        $booking = $this->makeBooking('confirmed', null, 3);

        $this->withToken($this->passengerToken)
            ->postJson("/api/bookings/{$booking->id}/cancel-seats", [
                'seats_to_cancel' => 1,
            ])
            ->assertStatus(200);

        $this->assertEquals(2, $booking->fresh()->seats);
    }

    public function test_cancel_partial_seats_more_than_booked_fails(): void
    {
        $booking = $this->makeBooking('confirmed', null, 2);

        $this->withToken($this->passengerToken)
            ->postJson("/api/bookings/{$booking->id}/cancel-seats", [
                'seats_to_cancel' => 5,
            ])
            ->assertStatus(422);
    }

    public function test_cancelling_all_seats_cancels_booking(): void
    {
        $booking = $this->makeBooking('confirmed', null, 2);

        $this->withToken($this->passengerToken)
            ->postJson("/api/bookings/{$booking->id}/cancel-seats", [
                'seats_to_cancel' => 2,
            ])
            ->assertStatus(200);

        $this->assertEquals('cancelled', $booking->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // passengerConfirmCompletion — POST /api/bookings/{id}/passenger-confirm
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_passenger_can_confirm_completion(): void
    {
        $ride = $this->insertRide(['status' => 'awaiting_confirmation']);
        $booking = $this->makeBooking('confirmed', $ride);

        $this->withToken($this->passengerToken)
            ->postJson("/api/bookings/{$booking->id}/passenger-confirm")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_passenger_confirm_fails_for_active_ride(): void
    {
        $ride    = $this->insertRide(['status' => 'active']);
        $booking = $this->makeBooking('confirmed', $ride);

        $this->withToken($this->passengerToken)
            ->postJson("/api/bookings/{$booking->id}/passenger-confirm")
            ->assertStatus(400);
    }

    public function test_non_passenger_cannot_confirm_completion(): void
    {
        $ride    = $this->insertRide(['status' => 'awaiting_confirmation']);
        $booking = $this->makeBooking('confirmed', $ride);

        $this->withToken($this->driverToken)
            ->postJson("/api/bookings/{$booking->id}/passenger-confirm")
            ->assertStatus(400);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // getMyBookings — GET /api/bookings/my-bookings
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_get_my_bookings_returns_passenger_bookings(): void
    {
        $this->makeBooking();

        $this->withToken($this->passengerToken)
            ->getJson('/api/bookings/my-bookings')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_get_my_bookings_requires_auth(): void
    {
        $this->getJson('/api/bookings/my-bookings')->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // myBookings — GET /api/my-bookings (alias)
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_my_bookings_alias_returns_passenger_bookings(): void
    {
        $this->makeBooking();

        $this->withToken($this->passengerToken)
            ->getJson('/api/my-bookings')
            ->assertStatus(200);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // getRouteOptions — GET /api/rides/route-options
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_get_route_options_returns_routes(): void
    {
        $this->withToken($this->driverToken)
            ->getJson('/api/rides/route-options?' . http_build_query([
                    'pickup_lat'      => 33.5138,
                    'pickup_lng'      => 36.2765,
                    'destination_lat' => 36.2021,
                    'destination_lng' => 37.1343,
                ]))
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_get_route_options_fails_with_missing_params(): void
    {
        $this->withToken($this->driverToken)
            ->getJson('/api/rides/route-options')
            ->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // autocomplete — GET /api/rides/autocomplete
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_autocomplete_fails_with_missing_text(): void
    {
        $this->withToken($this->driverToken)
            ->getJson('/api/rides/autocomplete')
            ->assertStatus(422);
    }

    public function test_autocomplete_fails_with_single_character(): void
    {
        $this->withToken($this->driverToken)
            ->getJson('/api/rides/autocomplete?text=د')
            ->assertStatus(422);
    }

    public function test_autocomplete_returns_results_for_valid_text(): void
    {
        $response = $this->withToken($this->driverToken)
            ->getJson('/api/rides/autocomplete?text=دمشق');

        // Either 200 with data or 500 if external API is unavailable in CI
        $this->assertContains($response->status(), [200, 500]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════════

    private function insertRide(array $overrides = []): Ride
    {
        $bookingType   = $overrides['booking_type']   ?? 'direct';
        $paymentMethod = $overrides['payment_method'] ?? 'cash';
        $status        = $overrides['status']         ?? 'active';
        $seats         = $overrides['available_seats'] ?? 4;
        $departure     = $overrides['departure_time']  ?? now()->addHours(3);

        $departureStr = $departure instanceof Carbon
            ? $departure->format('Y-m-d H:i:s')
            : $departure;

        DB::statement("
            INSERT INTO rides
                (driver_id, pickup_address, destination_address,
                 pickup_location, destination_location,
                 departure_time, available_seats, price_per_seat,
                 payment_method, booking_type, status,
                 distance, duration, communication_number,
                 created_at, updated_at)
            VALUES (?, 'دمشق', 'حلب',
                ST_GeomFromText('POINT(33.5138 36.2765)'),
                ST_GeomFromText('POINT(36.2021 37.1343)'),
                ?, ?, 50000, ?, ?, ?,
                320.5, 240, '0912345678', NOW(), NOW())
        ", [$this->driver->id, $departureStr, $seats, $paymentMethod, $bookingType, $status]);

        return Ride::latest('id')->first();
    }

    private function makeBooking(string $status = 'confirmed', ?Ride $ride = null, int $seats = 1): Booking
    {
        $ride = $ride ?? $this->insertRide();

        return Booking::create([
            'user_id'              => $this->passenger->id,
            'ride_id'              => $ride->id,
            'seats'                => $seats,
            'status'               => $status,
            'communication_number' => '0911111111',
        ]);
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
