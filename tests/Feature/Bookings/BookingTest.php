<?php

namespace Tests\Feature\Bookings;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;
    private User $passenger;
    private Ride $ride;
    private string $driverToken;
    private string $passengerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = User::factory()->create([
            'is_verified_driver'    => true,
            'is_verified_passenger' => true,
            'verification_status'   => 'approved',
            'password'              => bcrypt('password123'),
        ]);

        $this->passenger = User::factory()->create([
            'is_verified_passenger' => true,
            'verification_status'   => 'approved',
            'password'              => bcrypt('password123'),
        ]);

        $this->ride = Ride::factory()->create([
            'driver_id'       => $this->driver->id,
            'status'          => 'active',
            'available_seats' => 4,
            'price_per_seat'  => 50_000,
            'payment_method'  => 'cash',
            'booking_type'    => 'direct',
            'departure_time'  => now()->addHours(3),
        ]);

        $this->driverToken    = $this->getToken($this->driver);
        $this->passengerToken = $this->getToken($this->passenger);
    }

    // ─── Book a ride ──────────────────────────────────────────────────────────

    public function test_verified_passenger_can_book_ride(): void
    {
        $response = $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$this->ride->id}/book", [
                'seats'                => 1,
                'communication_number' => '0912345678',
                'idempotency_key'      => Str::uuid()->toString(),
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'status', 'seats']]);
    }

    /**
     * This is a critical business rule: drivers cannot book their own rides.
     * A driver acting as a passenger on their own ride would make no sense.
     */
    public function test_driver_cannot_book_own_ride(): void
    {
        $response = $this->withToken($this->driverToken)
            ->postJson("/api/rides/{$this->ride->id}/book", [
                'seats'                => 1,
                'communication_number' => '0912345678',
                'idempotency_key'      => Str::uuid()->toString(),
            ]);

        $response->assertStatus(422);
    }

    public function test_unverified_passenger_cannot_book(): void
    {
        $unverified = User::factory()->create([
            'is_verified_passenger' => false,
            'password'              => bcrypt('password123'),
        ]);
        $token = $this->getToken($unverified);

        $response = $this->withToken($token)
            ->postJson("/api/rides/{$this->ride->id}/book", [
                'seats'                => 1,
                'communication_number' => '0912345678',
                'idempotency_key'      => Str::uuid()->toString(),
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_book_more_seats_than_available(): void
    {
        $response = $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$this->ride->id}/book", [
                'seats'                => 10, // only 4 available
                'communication_number' => '0912345678',
                'idempotency_key'      => Str::uuid()->toString(),
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_book_zero_seats(): void
    {
        $response = $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$this->ride->id}/book", [
                'seats'                => 0,
                'communication_number' => '0912345678',
                'idempotency_key'      => Str::uuid()->toString(),
            ]);

        $response->assertStatus(422);
    }

    public function test_booking_reduces_available_seats(): void
    {
        $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$this->ride->id}/book", [
                'seats'                => 2,
                'communication_number' => '0912345678',
                'idempotency_key'      => Str::uuid()->toString(),
            ]);

        $this->assertDatabaseHas('rides', [
            'id'              => $this->ride->id,
            'available_seats' => 2, // was 4, booked 2
        ]);
    }

    public function test_ride_becomes_full_when_all_seats_booked(): void
    {
        $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$this->ride->id}/book", [
                'seats'                => 4, // all seats
                'communication_number' => '0912345678',
                'idempotency_key'      => Str::uuid()->toString(),
            ]);

        $this->assertDatabaseHas('rides', [
            'id'     => $this->ride->id,
            'status' => 'full',
        ]);
    }

    public function test_idempotency_prevents_duplicate_bookings(): void
    {
        $key     = Str::uuid()->toString();
        $payload = [
            'seats'                => 1,
            'communication_number' => '0912345678',
            'idempotency_key'      => $key,
        ];

        $response1 = $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$this->ride->id}/book", $payload);

        $response2 = $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$this->ride->id}/book", $payload);

        // Both return 201 but only ONE booking should exist
        $response1->assertStatus(201);
        $response2->assertStatus(201);

        $this->assertEquals(
            $response1->json('data.id'),
            $response2->json('data.id')  // same booking returned
        );
    }

    // ─── Accept / Reject booking (request-type rides) ─────────────────────────

    public function test_driver_can_accept_pending_booking(): void
    {
        $requestRide = Ride::factory()->create([
            'driver_id'       => $this->driver->id,
            'status'          => 'active',
            'available_seats' => 4,
            'booking_type'    => 'request',
            'payment_method'  => 'cash',
            'departure_time'  => now()->addHours(3),
        ]);

        $booking = new Booking([
            'user_id'              => $this->passenger->id,
            'ride_id'              => $requestRide->id,
            'seats'                => 1,
            'status'               => 'pending',
            'communication_number' => '0912345678',
        ]);
        $booking->save();

        $response = $this->withToken($this->driverToken)
            ->postJson("/api/rides/{$booking->id}/accept");

        $response->assertStatus(200);
        $this->assertDatabaseHas('bookings', [
            'id'     => $booking->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_driver_can_reject_pending_booking(): void
    {
        $requestRide = Ride::factory()->create([
            'driver_id'       => $this->driver->id,
            'status'          => 'active',
            'available_seats' => 4,
            'booking_type'    => 'request',
            'payment_method'  => 'cash',
            'departure_time'  => now()->addHours(3),
        ]);

        $booking = new Booking([
            'user_id'              => $this->passenger->id,
            'ride_id'              => $requestRide->id,
            'seats'                => 1,
            'status'               => 'pending',
            'communication_number' => '0912345678',
        ]);
        $booking->save();

        $response = $this->withToken($this->driverToken)
            ->postJson("/api/rides/{$booking->id}/reject");

        $response->assertStatus(200);
        $this->assertDatabaseHas('bookings', [
            'id'     => $booking->id,
            'status' => 'cancelled',
        ]);
    }

    // ─── View bookings ────────────────────────────────────────────────────────

    public function test_passenger_can_view_own_bookings(): void
    {
        $booking = new Booking([
            'user_id'              => $this->passenger->id,
            'ride_id'              => $this->ride->id,
            'seats'                => 1,
            'status'               => 'confirmed',
            'communication_number' => '0912345678',
        ]);
        $booking->save();

        $response = $this->withToken($this->passengerToken)
            ->getJson('/api/my-bookings');

        $response->assertStatus(200);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function getToken(User $user): string
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ]);

        return $response->json('tokens.access_token');
    }
}
