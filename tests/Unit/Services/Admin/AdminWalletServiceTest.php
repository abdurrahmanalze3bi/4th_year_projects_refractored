<?php

namespace Tests\Unit\Services\Admin;

use App\Domain\ValueObjects\Money;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Admin\AdminWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * AdminWalletServiceTest – Unit tests for AdminWalletService.
 *
 * WHY EXTENDS Laravel TestCase (not PHPUnit):
 * AdminWalletService uses Eloquent models (Wallet, User, WalletTransaction),
 * DB transactions, and config(). We need the real database.
 *
 * METHODS COVERED:
 * - getOrCreateWallet()
 * - chargeWallet()
 * - getAdminWallets()
 * - getWalletTransactions()
 * - getAllWallets()
 */
class AdminWalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdminWalletService $service;
    private array $primaryConfig;
    private array $sycashConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AdminWalletService();

        // Hermetic admin config – does NOT depend on .env
        $this->primaryConfig = [
            'email'         => 'primary@admin.test',
            'password'      => 'primary_pass',
            'first_name'    => 'Primary',
            'last_name'     => 'Admin',
            'phone'         => '0910000001',
            'type'          => 'primary',
            'wallet_prefix' => 'PRIM',
            'permissions'   => ['*'],
        ];

        $this->sycashConfig = [
            'email'         => 'sycash@admin.test',
            'password'      => 'sycash_pass',
            'first_name'    => 'SyCash',
            'last_name'     => 'Admin',
            'phone'         => '0910000002',
            'type'          => 'sycash',
            'wallet_prefix' => 'SYCSH',
            'permissions'   => ['view_wallet'],
        ];

        Config::set('admin.primary', $this->primaryConfig);
        Config::set('admin.sycash', $this->sycashConfig);
    }

    // ─── getOrCreateWallet ────────────────────────────────────────────────────

    public function test_get_or_create_wallet_creates_wallet_for_new_admin(): void
    {
        $wallet = $this->service->getOrCreateWallet($this->primaryConfig);

        $this->assertInstanceOf(Wallet::class, $wallet);
        $this->assertEquals($this->primaryConfig['phone'], $wallet->phone_number);
    }

    public function test_get_or_create_wallet_returns_existing_wallet(): void
    {
        // First call – creates it
        $first  = $this->service->getOrCreateWallet($this->primaryConfig);
        // Second call – should return the same wallet (no duplicate created)
        $second = $this->service->getOrCreateWallet($this->primaryConfig);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, Wallet::where('phone_number', $this->primaryConfig['phone'])->count());
    }

    public function test_get_or_create_wallet_also_creates_admin_user(): void
    {
        $this->service->getOrCreateWallet($this->primaryConfig);

        $this->assertDatabaseHas('users', ['email' => $this->primaryConfig['email']]);
    }

    public function test_get_or_create_wallet_creates_initial_transaction(): void
    {
        $wallet = $this->service->getOrCreateWallet($this->primaryConfig);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type'      => 'admin_credit',
        ]);
    }

    public function test_get_or_create_wallet_generates_unique_wallet_number(): void
    {
        $wallet = $this->service->getOrCreateWallet($this->primaryConfig);

        $this->assertStringStartsWith($this->primaryConfig['wallet_prefix'], $wallet->wallet_number);
    }

    public function test_get_or_create_wallet_different_configs_create_separate_wallets(): void
    {
        $w1 = $this->service->getOrCreateWallet($this->primaryConfig);
        $w2 = $this->service->getOrCreateWallet($this->sycashConfig);

        $this->assertNotEquals($w1->id, $w2->id);
        $this->assertEquals(2, Wallet::count());
    }

    // ─── chargeWallet ─────────────────────────────────────────────────────────

    public function test_charge_wallet_increases_balance(): void
    {
        $wallet = $this->createUserWallet('0911111111', 1000);
        $amount = Money::from(500);

        $result = $this->service->chargeWallet('0911111111', $amount, $this->primaryConfig);

        $this->assertEquals(1500, (float) $wallet->fresh()->balance);
        $this->assertEquals(1000, (float) $result['previous_balance']->amount());
        $this->assertEquals(1500, (float) $result['new_balance']->amount());
    }

    public function test_charge_wallet_returns_wallet_transaction_and_balances(): void
    {
        $this->createUserWallet('0911111111', 0);

        $result = $this->service->chargeWallet(
            '0911111111',
            Money::from(2000),
            $this->primaryConfig
        );

        $this->assertArrayHasKey('wallet',           $result);
        $this->assertArrayHasKey('transaction',      $result);
        $this->assertArrayHasKey('previous_balance', $result);
        $this->assertArrayHasKey('new_balance',      $result);
    }

    public function test_charge_wallet_creates_transaction_record(): void
    {
        $wallet = $this->createUserWallet('0911111111', 0);

        $this->service->chargeWallet('0911111111', Money::from(300), $this->primaryConfig);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type'      => 'admin_credit',
            'amount'    => 300,
        ]);
    }

    public function test_charge_wallet_transaction_id_includes_admin_type(): void
    {
        $this->createUserWallet('0911111111', 0);

        $result = $this->service->chargeWallet(
            '0911111111',
            Money::from(100),
            $this->primaryConfig
        );

        $this->assertStringStartsWith('PRIMARY_', $result['transaction']->transaction_id);
    }

    public function test_charge_wallet_throws_when_phone_not_found(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->service->chargeWallet('0000000000', Money::from(100), $this->primaryConfig);
    }

    // ─── getAdminWallets ──────────────────────────────────────────────────────

    public function test_get_admin_wallets_returns_array(): void
    {
        $this->service->getOrCreateWallet($this->primaryConfig);
        $this->service->getOrCreateWallet($this->sycashConfig);

        $wallets = $this->service->getAdminWallets();

        $this->assertIsArray($wallets);
        $this->assertCount(2, $wallets);
    }

    public function test_get_admin_wallets_returns_required_keys(): void
    {
        $this->service->getOrCreateWallet($this->primaryConfig);
        $this->service->getOrCreateWallet($this->sycashConfig);

        $wallets = $this->service->getAdminWallets();

        foreach ($wallets as $wallet) {
            $this->assertArrayHasKey('id',            $wallet);
            $this->assertArrayHasKey('wallet_number', $wallet);
            $this->assertArrayHasKey('phone_number',  $wallet);
            $this->assertArrayHasKey('balance',       $wallet);
            $this->assertArrayHasKey('owner',         $wallet);
            $this->assertArrayHasKey('admin_type',    $wallet);
        }
    }

    public function test_get_admin_wallets_returns_empty_when_no_wallets_exist(): void
    {
        // No wallets seeded → should return empty array, not throw
        $wallets = $this->service->getAdminWallets();
        $this->assertIsArray($wallets);
        $this->assertEmpty($wallets);
    }

    // ─── getWalletTransactions ────────────────────────────────────────────────

    public function test_get_wallet_transactions_returns_wallet_and_transactions(): void
    {
        $wallet = $this->createUserWallet('0911111111', 500);

        $result = $this->service->getWalletTransactions($wallet->id);

        $this->assertArrayHasKey('wallet',       $result);
        $this->assertArrayHasKey('transactions', $result);
        $this->assertEquals($wallet->id, $result['wallet']->id);
    }

    public function test_get_wallet_transactions_throws_for_nonexistent_wallet(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->service->getWalletTransactions(999999);
    }

    public function test_get_wallet_transactions_respects_per_page_parameter(): void
    {
        $wallet = $this->createUserWallet('0911111111', 0);

        // Create 15 transactions
        for ($i = 0; $i < 15; $i++) {
            WalletTransaction::create([
                'wallet_id'      => $wallet->id,
                'user_id'        => $wallet->user_id,
                'type'           => 'admin_credit',
                'amount'         => 10,
                'previous_balance' => 0,
                'new_balance'    => 10,
                'description'    => "Transaction {$i}",
                'transaction_id' => "TEST_{$i}_" . uniqid(),
                'status'         => 'completed',
            ]);
        }

        $result5  = $this->service->getWalletTransactions($wallet->id, 5);
        $result10 = $this->service->getWalletTransactions($wallet->id, 10);

        $this->assertEquals(5,  $result5['transactions']->perPage());
        $this->assertEquals(10, $result10['transactions']->perPage());
    }

    // ─── getAllWallets ────────────────────────────────────────────────────────

    public function test_get_all_wallets_returns_array(): void
    {
        $this->createUserWallet('0911111111', 0);
        $this->createUserWallet('0911111112', 0);

        $wallets = $this->service->getAllWallets();

        $this->assertIsArray($wallets);
        $this->assertGreaterThanOrEqual(2, count($wallets));
    }

    public function test_get_all_wallets_returns_required_keys(): void
    {
        $this->createUserWallet('0911111111', 500);

        $wallets = $this->service->getAllWallets();

        $this->assertNotEmpty($wallets);

        foreach ($wallets as $wallet) {
            $this->assertArrayHasKey('id',            $wallet);
            $this->assertArrayHasKey('wallet_number', $wallet);
            $this->assertArrayHasKey('phone_number',  $wallet);
            $this->assertArrayHasKey('balance',       $wallet);
            $this->assertArrayHasKey('owner',         $wallet);
            $this->assertArrayHasKey('created_at',    $wallet);
        }
    }

    public function test_get_all_wallets_returns_empty_when_no_wallets(): void
    {
        $wallets = $this->service->getAllWallets();
        $this->assertIsArray($wallets);
        $this->assertEmpty($wallets);
    }

    public function test_get_all_wallets_ordered_by_created_at_desc(): void
    {
        $w1 = $this->createUserWallet('0911111111', 0);
        $w2 = $this->createUserWallet('0911111112', 0);

        // Use DB facade to bypass Eloquent timestamp handling
        \Illuminate\Support\Facades\DB::table('wallets')
            ->where('id', $w1->id)
            ->update(['created_at' => now()->subDay()]);

        $wallets = $this->service->getAllWallets();

        $ids   = array_column($wallets, 'id');
        $posW1 = array_search($w1->id, $ids);
        $posW2 = array_search($w2->id, $ids);

        $this->assertNotFalse($posW1, 'w1 not found in results');
        $this->assertNotFalse($posW2, 'w2 not found in results');
        // w2 is newer, should have a smaller index (appears first in DESC order)
        $this->assertLessThan($posW1, $posW2, 'w2 (newer) should appear before w1 (older)');
    }
    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createUserWallet(string $phone, float $balance): Wallet
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $wallet = Wallet::create([
            'user_id'       => $user->id,
            'phone_number'  => $phone,
            'wallet_number' => 'WLT-' . strtoupper(uniqid()),
            'balance'       => $balance,
        ]);

        $user->update(['wallet_id' => $wallet->id]);

        return $wallet;
    }
}
