<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\CreateRideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateRideRequestTest extends TestCase
{
    use RefreshDatabase;

    // ── authorize() ───────────────────────────────────────────────────────────

    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new CreateRideRequest())->authorize());
    }

    // ── rules() ───────────────────────────────────────────────────────────────

    public function test_rules_contains_departure_time(): void
    {
        $this->assertArrayHasKey('departure_time', (new CreateRideRequest())->rules());
    }

    public function test_rules_contains_available_seats(): void
    {
        $this->assertArrayHasKey('available_seats', (new CreateRideRequest())->rules());
    }

    public function test_rules_contains_price_per_seat(): void
    {
        $this->assertArrayHasKey('price_per_seat', (new CreateRideRequest())->rules());
    }

    public function test_rules_contains_payment_method(): void
    {
        $this->assertArrayHasKey('payment_method', (new CreateRideRequest())->rules());
    }

    public function test_rules_contains_booking_type(): void
    {
        $this->assertArrayHasKey('booking_type', (new CreateRideRequest())->rules());
    }

    public function test_rules_contains_communication_number(): void
    {
        $this->assertArrayHasKey('communication_number', (new CreateRideRequest())->rules());
    }

    public function test_rules_contains_vehicle_type(): void
    {
        $this->assertArrayHasKey('vehicle_type', (new CreateRideRequest())->rules());
    }

    public function test_rules_contains_notes(): void
    {
        $this->assertArrayHasKey('notes', (new CreateRideRequest())->rules());
    }

    // ── messages() ────────────────────────────────────────────────────────────

    public function test_messages_returns_non_empty_array(): void
    {
        $messages = (new CreateRideRequest())->messages();
        $this->assertIsArray($messages);
        $this->assertNotEmpty($messages);
    }

    public function test_messages_contains_departure_time_after_message(): void
    {
        $this->assertArrayHasKey('departure_time.after', (new CreateRideRequest())->messages());
    }

    public function test_messages_contains_communication_number_regex_message(): void
    {
        $this->assertArrayHasKey('communication_number.regex', (new CreateRideRequest())->messages());
    }

    public function test_messages_contains_price_per_seat_min_message(): void
    {
        $this->assertArrayHasKey('price_per_seat.min', (new CreateRideRequest())->messages());
    }

    // ── HTTP validation via API ───────────────────────────────────────────────

    public function test_api_create_ride_requires_authentication(): void
    {
        $this->postJson('/api/rides', [])->assertStatus(401);
    }

    public function test_api_create_ride_fails_with_invalid_payment_method(): void
    {
        $user  = User::factory()->create(['is_verified_driver' => true, 'password' => bcrypt('pass123')]);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'pass123'])
            ->json('tokens.access_token');

        $this->withToken($token)->postJson('/api/rides', [
            'payment_method'  => 'crypto',
            'available_seats' => 3,
            'departure_time'  => now()->addHours(2)->toDateTimeString(),
        ])->assertStatus(422);
    }

    public function test_api_create_ride_fails_with_past_departure_time(): void
    {
        $user  = User::factory()->create(['is_verified_driver' => true, 'password' => bcrypt('pass123')]);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'pass123'])
            ->json('tokens.access_token');

        $this->withToken($token)->postJson('/api/rides', [
            'departure_time'  => now()->subHour()->toDateTimeString(),
            'available_seats' => 3,
            'payment_method'  => 'cash',
            'booking_type'    => 'direct',
        ])->assertStatus(422);
    }

    public function test_api_create_ride_fails_with_too_many_seats(): void
    {
        $user  = User::factory()->create(['is_verified_driver' => true, 'password' => bcrypt('pass123')]);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'pass123'])
            ->json('tokens.access_token');

        $this->withToken($token)->postJson('/api/rides', [
            'departure_time'       => now()->addHours(2)->toDateTimeString(),
            'available_seats'      => 20,   // max is 8
            'price_per_seat'       => 5000,
            'payment_method'       => 'cash',
            'booking_type'         => 'direct',
            'communication_number' => '0912345678',
            'vehicle_type'         => 'Toyota',
            'pickup_address'       => 'Damascus',
            'destination_address'  => 'Aleppo',
        ])->assertStatus(422);
    }
}
