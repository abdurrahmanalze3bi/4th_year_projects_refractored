<?php

namespace Tests\Feature\Payment;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\WalletTransactionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WalletTransactionServiceTest
 *
 * Covers every public method and every branch in WalletTransactionService.
 *
 * Money flows tested:
 *   chargeRideCreationFee          → Driver  → SyCash
 *   chargePassengerForBooking      → Passenger → Primary Admin (escrow)
 *   releaseEarningsToDriver        → Primary Admin → Driver
 *   refundPassengersForDriverCancellation → Primary Admin → each Passenger
 *   refundDriverCreationFeeOnCancellation → SyCash → Driver
 *   refundCreationFeeNoBookings    → SyCash → Driver
 *   processTimeBasedCancellation   → Primary Admin → Passenger + Driver (all tiers)
 *   calculateRefundPolicy          → pure logic (all 4 tiers + edge cases)
 */
class WalletTransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletTransactionService $service;

    private User   $driver;
    private User   $passenger;
    private Wallet $driverWallet;
    private Wallet $passengerWallet;
    private Wallet $primaryAdminWallet;
    private Wallet $syCashWallet;
    private Ride   $ride;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new WalletTransactionService();

        // ── Admin users & wallets ─────────────────────────────────────────────
        $primaryCfg = config('admin.primary');
        $syCashCfg  = config('admin.sycash');

        $primaryAdmin = User::firstOrCreate(
            ['email' => $primaryCfg['email']],
            ['first_name' => 'Primary', 'last_name' => 'Admin',
                'password' => bcrypt($primaryCfg['password']),
                'gender' => 'M', 'address' => 'دمشق', 'status' => true]
        );
        $this->primaryAdminWallet = Wallet::create([
            'user_id'       => $primaryAdmin->id,
            'phone_number'  => $primaryCfg['phone'],
            'wallet_number' => 'WLT-PRIMARY-001',
            'balance'       => 10_000_000,
        ]);
        $primaryAdmin->update(['wallet_id' => $this->primaryAdminWallet->id]);

        $syCashAdmin = User::firstOrCreate(
            ['email' => $syCashCfg['email']],
            ['first_name' => 'SyCash', 'last_name' => 'Admin',
                'password' => bcrypt($syCashCfg['password']),
                'gender' => 'M', 'address' => 'دمشق', 'status' => true]
        );
        $this->syCashWallet = Wallet::create([
            'user_id'       => $syCashAdmin->id,
            'phone_number'  => $syCashCfg['phone'],
            'wallet_number' => 'WLT-SYCASH-001',
            'balance'       => 10_000_000,
        ]);
        $syCashAdmin->update(['wallet_id' => $this->syCashWallet->id]);

        // ── Driver ────────────────────────────────────────────────────────────
        $this->driver = User::factory()->create(['is_verified_driver' => true]);
        $this->driverWallet = Wallet::create([
            'user_id'       => $this->driver->id,
            'phone_number'  => '0912345678',
            'wallet_number' => 'WLT-DRV-001',
            'balance'       => 1_000_000,
        ]);
        $this->driver->update(['wallet_id' => $this->driverWallet->id]);

        // ── Passenger ─────────────────────────────────────────────────────────
        $this->passenger = User::factory()->create(['is_verified_passenger' => true]);
        $this->passengerWallet = Wallet::create([
            'user_id'       => $this->passenger->id,
            'phone_number'  => '0911111111',
            'wallet_number' => 'WLT-PAS-001',
            'balance'       => 1_000_000,
        ]);
        $this->passenger->update(['wallet_id' => $this->passengerWallet->id]);

        // ── Ride ──────────────────────────────────────────────────────────────
        $this->ride = $this->insertRide($this->driver, [
            'price_per_seat'  => 50_000,
            'available_seats' => 4,
            'payment_method'  => 'e-pay',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // chargeRideCreationFee
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_charge_ride_creation_fee_deducts_from_driver(): void
    {
        $before = (float) $this->driverWallet->fresh()->balance;
        $fee    = $this->ride->price_per_seat * $this->ride->available_seats * 0.05;

        $this->service->chargeRideCreationFee($this->ride, $this->driver);

        $this->assertEquals($before - $fee, (float) $this->driverWallet->fresh()->balance);
    }

    public function test_charge_ride_creation_fee_adds_to_sycash(): void
    {
        $before = (float) $this->syCashWallet->fresh()->balance;
        $fee    = $this->ride->price_per_seat * $this->ride->available_seats * 0.05;

        $this->service->chargeRideCreationFee($this->ride, $this->driver);

        $this->assertEquals($before + $fee, (float) $this->syCashWallet->fresh()->balance);
    }

    public function test_charge_ride_creation_fee_creates_two_transactions(): void
    {
        $before = WalletTransaction::count();

        $this->service->chargeRideCreationFee($this->ride, $this->driver);

        $this->assertEquals($before + 2, WalletTransaction::count());
    }

    public function test_charge_ride_creation_fee_throws_when_driver_has_insufficient_balance(): void
    {
        $this->driverWallet->update(['balance' => 0]);

        $this->expectException(\RuntimeException::class);
        $this->service->chargeRideCreationFee($this->ride, $this->driver);
    }

    public function test_charge_ride_creation_fee_throws_when_driver_has_no_wallet(): void
    {
        $noWalletDriver = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->service->chargeRideCreationFee($this->ride, $noWalletDriver);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // chargePassengerForBooking
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_charge_passenger_deducts_from_passenger_wallet(): void
    {
        $booking = $this->makeBooking(2);
        $amount  = $booking->seats * $this->ride->price_per_seat;
        $before  = (float) $this->passengerWallet->fresh()->balance;

        $this->service->chargePassengerForBooking($booking, $this->ride, $this->passenger);

        $this->assertEquals($before - $amount, (float) $this->passengerWallet->fresh()->balance);
    }

    public function test_charge_passenger_adds_to_admin_wallet(): void
    {
        $booking = $this->makeBooking(2);
        $amount  = $booking->seats * $this->ride->price_per_seat;
        $before  = (float) $this->primaryAdminWallet->fresh()->balance;

        $this->service->chargePassengerForBooking($booking, $this->ride, $this->passenger);

        $this->assertEquals($before + $amount, (float) $this->primaryAdminWallet->fresh()->balance);
    }

    public function test_charge_passenger_creates_two_transactions(): void
    {
        $booking = $this->makeBooking(1);
        $before  = WalletTransaction::count();

        $this->service->chargePassengerForBooking($booking, $this->ride, $this->passenger);

        $this->assertEquals($before + 2, WalletTransaction::count());
    }

    public function test_charge_passenger_throws_when_insufficient_balance(): void
    {
        $this->passengerWallet->update(['balance' => 0]);
        $booking = $this->makeBooking(1);

        $this->expectException(\RuntimeException::class);
        $this->service->chargePassengerForBooking($booking, $this->ride, $this->passenger);
    }

    public function test_charge_passenger_throws_when_passenger_has_no_wallet(): void
    {
        $noWalletPassenger = User::factory()->create();
        $booking = $this->makeBooking(1);

        $this->expectException(\RuntimeException::class);
        $this->service->chargePassengerForBooking($booking, $this->ride, $noWalletPassenger);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // releaseEarningsToDriver
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_release_earnings_adds_to_driver_wallet(): void
    {
        $booking   = $this->makeBooking(2, 'confirmed');
        $bookings  = new Collection([$booking]);
        $expected  = $booking->seats * $this->ride->price_per_seat;
        $before    = (float) $this->driverWallet->fresh()->balance;

        $this->service->releaseEarningsToDriver($this->ride, $bookings);

        $this->assertEquals($before + $expected, (float) $this->driverWallet->fresh()->balance);
    }

    public function test_release_earnings_deducts_from_admin_wallet(): void
    {
        $booking  = $this->makeBooking(2, 'confirmed');
        $bookings = new Collection([$booking]);
        $expected = $booking->seats * $this->ride->price_per_seat;
        $before   = (float) $this->primaryAdminWallet->fresh()->balance;

        $this->service->releaseEarningsToDriver($this->ride, $bookings);

        $this->assertEquals($before - $expected, (float) $this->primaryAdminWallet->fresh()->balance);
    }

    public function test_release_earnings_does_nothing_for_empty_collection(): void
    {
        $before = WalletTransaction::count();

        $this->service->releaseEarningsToDriver($this->ride, new Collection());

        // No transactions created
        $this->assertEquals($before, WalletTransaction::count());
    }

    public function test_release_earnings_creates_two_transactions(): void
    {
        $booking  = $this->makeBooking(1, 'confirmed');
        $before   = WalletTransaction::count();

        $this->service->releaseEarningsToDriver($this->ride, new Collection([$booking]));

        $this->assertEquals($before + 2, WalletTransaction::count());
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // refundPassengersForDriverCancellation
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_refund_passengers_returns_early_for_empty_bookings(): void
    {
        $before = WalletTransaction::count();

        $this->service->refundPassengersForDriverCancellation($this->ride, new Collection());

        $this->assertEquals($before, WalletTransaction::count());
    }

    public function test_refund_passengers_adds_money_back_to_passenger_wallet(): void
    {
        $booking  = $this->makeBooking(2, 'confirmed');
        $refund   = $booking->seats * $this->ride->price_per_seat;
        $before   = (float) $this->passengerWallet->fresh()->balance;

        $this->service->refundPassengersForDriverCancellation(
            $this->ride,
            new Collection([$booking])
        );

        $this->assertEquals($before + $refund, (float) $this->passengerWallet->fresh()->balance);
    }

    public function test_refund_passengers_deducts_from_admin_wallet(): void
    {
        $booking = $this->makeBooking(1, 'confirmed');
        $refund  = $booking->seats * $this->ride->price_per_seat;
        $before  = (float) $this->primaryAdminWallet->fresh()->balance;

        $this->service->refundPassengersForDriverCancellation(
            $this->ride,
            new Collection([$booking])
        );

        $this->assertEquals($before - $refund, (float) $this->primaryAdminWallet->fresh()->balance);
    }

    public function test_refund_passengers_creates_one_admin_tx_plus_one_per_passenger(): void
    {
        $b1     = $this->makeBooking(1, 'confirmed');
        $b2     = $this->makeBooking(1, 'confirmed');
        $before = WalletTransaction::count();

        $this->service->refundPassengersForDriverCancellation(
            $this->ride,
            new Collection([$b1, $b2])
        );

        // 1 admin debit + 2 passenger credits = 3 transactions
        $this->assertEquals($before + 3, WalletTransaction::count());
    }

    public function test_refund_passengers_throws_when_admin_insufficient_balance(): void
    {
        $this->primaryAdminWallet->update(['balance' => 0]);
        $booking = $this->makeBooking(1, 'confirmed');

        $this->expectException(\RuntimeException::class);
        $this->service->refundPassengersForDriverCancellation(
            $this->ride,
            new Collection([$booking])
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // refundDriverCreationFeeOnCancellation
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_refund_driver_creation_fee_adds_to_driver_wallet(): void
    {
        $originalSeats = 4;
        $refund = $this->ride->price_per_seat * $originalSeats * 0.05;
        $before = (float) $this->driverWallet->fresh()->balance;

        $this->service->refundDriverCreationFeeOnCancellation($this->ride, $originalSeats);

        $this->assertEquals($before + $refund, (float) $this->driverWallet->fresh()->balance);
    }

    public function test_refund_driver_creation_fee_deducts_from_sycash(): void
    {
        $originalSeats = 4;
        $refund = $this->ride->price_per_seat * $originalSeats * 0.05;
        $before = (float) $this->syCashWallet->fresh()->balance;

        $this->service->refundDriverCreationFeeOnCancellation($this->ride, $originalSeats);

        $this->assertEquals($before - $refund, (float) $this->syCashWallet->fresh()->balance);
    }

    public function test_refund_driver_creation_fee_creates_two_transactions(): void
    {
        $before = WalletTransaction::count();

        $this->service->refundDriverCreationFeeOnCancellation($this->ride, 4);

        $this->assertEquals($before + 2, WalletTransaction::count());
    }

    public function test_refund_driver_creation_fee_throws_when_sycash_insufficient(): void
    {
        $this->syCashWallet->update(['balance' => 0]);

        $this->expectException(\RuntimeException::class);
        $this->service->refundDriverCreationFeeOnCancellation($this->ride, 4);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // refundCreationFeeNoBookings
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_refund_no_bookings_adds_fee_to_driver(): void
    {
        $fee    = $this->ride->price_per_seat * $this->ride->available_seats * 0.05;
        $before = (float) $this->driverWallet->fresh()->balance;

        $this->service->refundCreationFeeNoBookings($this->ride);

        $this->assertEquals($before + $fee, (float) $this->driverWallet->fresh()->balance);
    }

    public function test_refund_no_bookings_deducts_from_sycash(): void
    {
        $fee    = $this->ride->price_per_seat * $this->ride->available_seats * 0.05;
        $before = (float) $this->syCashWallet->fresh()->balance;

        $this->service->refundCreationFeeNoBookings($this->ride);

        $this->assertEquals($before - $fee, (float) $this->syCashWallet->fresh()->balance);
    }

    public function test_refund_no_bookings_creates_two_transactions(): void
    {
        $before = WalletTransaction::count();

        $this->service->refundCreationFeeNoBookings($this->ride);

        $this->assertEquals($before + 2, WalletTransaction::count());
    }

    public function test_refund_no_bookings_throws_when_sycash_insufficient(): void
    {
        $this->syCashWallet->update(['balance' => 0]);

        $this->expectException(\RuntimeException::class);
        $this->service->refundCreationFeeNoBookings($this->ride);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // processTimeBasedCancellation — all 4 refund tiers
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_process_cancellation_100_percent_refund_refunds_passenger_fully(): void
    {
        // 0-30% elapsed → 100% refund
        $policy  = ['refund_percentage' => 100, 'time_elapsed_percentage' => 10, 'policy_tier' => 'Full refund'];
        $booking = $this->makeBooking(1, 'confirmed');
        $amount  = $booking->seats * $this->ride->price_per_seat;
        $before  = (float) $this->passengerWallet->fresh()->balance;

        $this->service->processTimeBasedCancellation($booking, $this->ride, 1, $policy);

        $this->assertEquals($before + $amount, (float) $this->passengerWallet->fresh()->balance);
    }

    public function test_process_cancellation_100_percent_refund_driver_gets_nothing(): void
    {
        $policy  = ['refund_percentage' => 100, 'time_elapsed_percentage' => 10, 'policy_tier' => 'Full refund'];
        $booking = $this->makeBooking(1, 'confirmed');
        $before  = (float) $this->driverWallet->fresh()->balance;

        $this->service->processTimeBasedCancellation($booking, $this->ride, 1, $policy);

        // Driver wallet unchanged
        $this->assertEquals($before, (float) $this->driverWallet->fresh()->balance);
    }

    public function test_process_cancellation_70_percent_refund_splits_correctly(): void
    {
        // 30-50% elapsed → 70% refund
        $policy       = ['refund_percentage' => 70, 'time_elapsed_percentage' => 40, 'policy_tier' => '30-50%'];
        $booking      = $this->makeBooking(1, 'confirmed');
        $totalPaid    = $booking->seats * $this->ride->price_per_seat;
        $refundAmount = $totalPaid * 0.70;
        $driverAmount = $totalPaid - $refundAmount;

        $passengerBefore = (float) $this->passengerWallet->fresh()->balance;
        $driverBefore    = (float) $this->driverWallet->fresh()->balance;

        $this->service->processTimeBasedCancellation($booking, $this->ride, 1, $policy);

        $this->assertEqualsWithDelta($passengerBefore + $refundAmount, (float) $this->passengerWallet->fresh()->balance, 1);
        $this->assertEqualsWithDelta($driverBefore + $driverAmount,    (float) $this->driverWallet->fresh()->balance,    1);
    }

    public function test_process_cancellation_0_percent_refund_all_goes_to_driver(): void
    {
        // 70-100% elapsed → 0% refund
        $policy      = ['refund_percentage' => 0, 'time_elapsed_percentage' => 80, 'policy_tier' => '70-100%'];
        $booking     = $this->makeBooking(1, 'confirmed');
        $totalPaid   = $booking->seats * $this->ride->price_per_seat;
        $driverBefore = (float) $this->driverWallet->fresh()->balance;

        $this->service->processTimeBasedCancellation($booking, $this->ride, 1, $policy);

        $this->assertEquals($driverBefore + $totalPaid, (float) $this->driverWallet->fresh()->balance);
    }

    public function test_process_cancellation_0_percent_refund_creates_no_refund_audit_record(): void
    {
        $policy  = ['refund_percentage' => 0, 'time_elapsed_percentage' => 80, 'policy_tier' => '70-100%'];
        $booking = $this->makeBooking(1, 'confirmed');

        $this->service->processTimeBasedCancellation($booking, $this->ride, 1, $policy);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $booking->user_id,
            'type'    => 'cancellation_no_refund',
        ]);
    }

    public function test_process_cancellation_throws_when_admin_insufficient(): void
    {
        $this->primaryAdminWallet->update(['balance' => 0]);
        $booking = $this->makeBooking(1, 'confirmed');
        $policy  = ['refund_percentage' => 100, 'time_elapsed_percentage' => 10, 'policy_tier' => 'Full refund'];

        $this->expectException(\RuntimeException::class);
        $this->service->processTimeBasedCancellation($booking, $this->ride, 1, $policy);
    }

    public function test_process_cancellation_for_multiple_seats(): void
    {
        $policy       = ['refund_percentage' => 50, 'time_elapsed_percentage' => 60, 'policy_tier' => '50-70%'];
        $booking      = $this->makeBooking(3, 'confirmed');
        $seatsCancelled = 2;
        $totalPaid    = $seatsCancelled * $this->ride->price_per_seat;
        $refundAmount = $totalPaid * 0.50;

        $passengerBefore = (float) $this->passengerWallet->fresh()->balance;

        $this->service->processTimeBasedCancellation($booking, $this->ride, $seatsCancelled, $policy);

        $this->assertEqualsWithDelta($passengerBefore + $refundAmount, (float) $this->passengerWallet->fresh()->balance, 1);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // calculateRefundPolicy — all 4 tiers + edge cases
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_refund_policy_tier1_full_refund(): void
    {
        $policy = $this->service->calculateRefundPolicy(
            Carbon::now()->addHours(48),
            Carbon::now()->subMinutes(5)
        );
        $this->assertEquals(100, $policy['refund_percentage']);
    }

    public function test_refund_policy_tier2_70_percent(): void
    {
        $policy = $this->service->calculateRefundPolicy(
            Carbon::now()->addMinutes(60),
            Carbon::now()->subMinutes(40)
        );
        $this->assertEquals(70, $policy['refund_percentage']);
    }

    public function test_refund_policy_tier3_50_percent(): void
    {
        $policy = $this->service->calculateRefundPolicy(
            Carbon::now()->addMinutes(40),
            Carbon::now()->subMinutes(60)
        );
        $this->assertEquals(50, $policy['refund_percentage']);
    }

    public function test_refund_policy_tier4_no_refund(): void
    {
        $policy = $this->service->calculateRefundPolicy(
            Carbon::now()->addMinutes(20),
            Carbon::now()->subMinutes(80)
        );
        $this->assertEquals(0, $policy['refund_percentage']);
    }

    public function test_refund_policy_after_departure_returns_zero(): void
    {
        $policy = $this->service->calculateRefundPolicy(
            Carbon::now()->subMinutes(5),
            Carbon::now()->subHour()
        );
        $this->assertEquals(0,   $policy['refund_percentage']);
        $this->assertEquals(100, $policy['time_elapsed_percentage']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Private helper error paths — triggered through public methods
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_throws_when_wallet_not_found_by_user_id(): void
    {
        // Driver has no wallet → lockWalletByUserId throws
        $driverNoWallet = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->service->chargeRideCreationFee($this->ride, $driverNoWallet);
    }

    public function test_throws_when_wallet_not_found_by_phone(): void
    {
        // Delete SyCash wallet so lockWalletByPhone throws
        $this->syCashWallet->delete();

        $this->expectException(\RuntimeException::class);
        $this->service->chargeRideCreationFee($this->ride, $this->driver);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════════

    private function insertRide(User $driver, array $overrides = []): Ride
    {
        $price         = $overrides['price_per_seat']  ?? 50_000;
        $seats         = $overrides['available_seats'] ?? 4;
        $payment       = $overrides['payment_method']  ?? 'e-pay';
        $departureTime = now()->addHours(3)->format('Y-m-d H:i:s');

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
                ?, ?, ?, ?, 'direct', 'active',
                320.5, 240, '0912345678', NOW(), NOW())
        ", [$driver->id, $departureTime, $seats, $price, $payment]);

        return Ride::latest('id')->first();
    }

    private function makeBooking(int $seats = 1, string $status = 'confirmed'): Booking
    {
        return Booking::create([
            'user_id'              => $this->passenger->id,
            'ride_id'              => $this->ride->id,
            'seats'                => $seats,
            'status'               => $status,
            'communication_number' => '0911111111',
        ]);
    }
}
