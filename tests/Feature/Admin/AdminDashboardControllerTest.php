<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * AdminDashboardControllerTest – Feature tests for AdminDashboardController.
 *
 * WHY SESSION-BASED AUTH:
 * - AdminAuthService stores admin identity in PHP session (not JWT)
 * - We use withSession() to simulate an authenticated admin
 * - We use withoutMiddleware(['web']) if CSRF blocks API-style JSON calls
 *
 * ROUTES COVERED:
 * POST   /admin/login
 * POST   /admin/logout
 * GET    /admin/info
 * GET    /admin/wallet
 * GET    /admin/wallets/admins
 * POST   /admin/wallet/charge
 * GET    /admin/wallet/{id}/transactions
 * GET    /admin/report
 * GET    /admin/dashboard
 * GET    /admin/wallets
 * GET    /admin/verifications/pending
 * POST   /admin/verifications/{id}/approve
 * POST   /admin/verifications/{id}/reject
 */
class AdminDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    // ─── Shared state ────────────────────────────────────────────────────────

    private Wallet $primaryAdminWallet;
    private Wallet $sycashAdminWallet;
    private array  $primarySession;
    private array  $sycashSession;

    // ─── setUp ───────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        // Override admin config so tests are hermetic (not tied to .env values).
        Config::set('admin.primary', [
            'email'         => 'primary@admin.test',
            'password'      => 'primary_pass',
            'first_name'    => 'Primary',
            'last_name'     => 'Admin',
            'phone'         => '0910000001',
            'type'          => 'primary',
            'wallet_prefix' => 'PRIM',
            'permissions'   => ['*'],
        ]);

        Config::set('admin.sycash', [
            'email'         => 'sycash@admin.test',
            'password'      => 'sycash_pass',
            'first_name'    => 'SyCash',
            'last_name'     => 'Admin',
            'phone'         => '0910000002',
            'type'          => 'sycash',
            'wallet_prefix' => 'SYCSH',
            'permissions'   => ['view_wallet'],
        ]);

        $this->seedAdminWallets();

        // Session payloads that mirror what AdminAuthService::createAdminSession() writes.
        $this->primarySession = [
            'admin_logged_in'    => true,
            'admin_email'        => 'primary@admin.test',
            'admin_type'         => 'primary',
            'admin_permissions'  => ['*'],
        ];

        $this->sycashSession = [
            'admin_logged_in'    => true,
            'admin_email'        => 'sycash@admin.test',
            'admin_type'         => 'sycash',
            'admin_permissions'  => ['view_wallet'],
        ];
    }

    // ─── LOGIN ───────────────────────────────────────────────────────────────

    public function test_admin_can_login_with_correct_credentials(): void
    {
        $response = $this->postJson('/admin/login', [
            'email'    => 'primary@admin.test',
            'password' => 'primary_pass',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('admin_type', 'primary');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $response = $this->postJson('/admin/login', [
            'email'    => 'primary@admin.test',
            'password' => 'wrong_password',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_login_fails_with_non_admin_email(): void
    {
        $response = $this->postJson('/admin/login', [
            'email'    => 'nobody@example.com',
            'password' => 'anything',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_requires_email_and_password(): void
    {
        $response = $this->postJson('/admin/login', []);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_login_requires_valid_email_format(): void
    {
        $response = $this->postJson('/admin/login', [
            'email'    => 'not-an-email',
            'password' => 'pass',
        ]);

        $response->assertStatus(422);
    }

    public function test_sycash_admin_can_login(): void
    {
        $response = $this->postJson('/admin/login', [
            'email'    => 'sycash@admin.test',
            'password' => 'sycash_pass',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('admin_type', 'sycash');
    }

    // ─── LOGOUT ──────────────────────────────────────────────────────────────

    public function test_authenticated_admin_can_logout(): void
    {
        $response = $this->withSession($this->primarySession)
            ->postJson('/admin/logout');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    // ─── GET ADMIN INFO ───────────────────────────────────────────────────────

    public function test_authenticated_admin_can_get_own_info(): void
    {
        $response = $this->withSession($this->primarySession)
            ->getJson('/admin/info');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['admin' => ['email', 'type', 'name', 'phone']]);
    }

    public function test_unauthenticated_request_to_info_returns_401(): void
    {
        $response = $this->getJson('/admin/info');

        $response->assertStatus(401);
    }

    // ─── WALLET ───────────────────────────────────────────────────────────────

    public function test_authenticated_admin_can_get_own_wallet(): void
    {
        $response = $this->withSession($this->primarySession)
            ->getJson('/admin/wallet');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'wallet' => ['id', 'wallet_number', 'phone_number', 'balance', 'admin_type'],
            ]);
    }

    public function test_unauthenticated_request_to_wallet_returns_401(): void
    {
        $this->getJson('/admin/wallet')->assertStatus(401);
    }

    // ─── ADMIN WALLETS LIST ────────────────────────────────────────────────────

    public function test_authenticated_admin_can_list_admin_wallets(): void
    {
        $response = $this->withSession($this->primarySession)
            ->getJson('/admin/wallets/admins');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['admin_wallets']);
    }

    // ─── CHARGE WALLET ────────────────────────────────────────────────────────

    public function test_primary_admin_can_charge_a_wallet(): void
    {
        // Create a regular user wallet to charge
        $user   = User::factory()->create(['password' => bcrypt('password123')]);
        $wallet = Wallet::create([
            'user_id'       => $user->id,
            'phone_number'  => '0911111111',
            'wallet_number' => 'WLT-USR-TEST',
            'balance'       => 0,
        ]);
        $user->update(['wallet_id' => $wallet->id]);

        $response = $this->withSession($this->primarySession)
            ->postJson('/admin/wallet/charge', [
                'phone_number' => '0911111111',
                'amount'       => 5000,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'transaction_id',
                'wallet' => ['phone_number', 'previous_balance', 'new_balance'],
            ]);

        $this->assertEquals(5000, (float) $wallet->fresh()->balance);
    }

    public function test_charge_wallet_fails_validation_with_missing_fields(): void
    {
        $response = $this->withSession($this->primarySession)
            ->postJson('/admin/wallet/charge', []);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_charge_wallet_fails_with_amount_zero(): void
    {
        $response = $this->withSession($this->primarySession)
            ->postJson('/admin/wallet/charge', [
                'phone_number' => '0911111111',
                'amount'       => 0,
            ]);

        $response->assertStatus(422);
    }

    public function test_sycash_admin_cannot_charge_wallet(): void
    {
        $response = $this->withSession($this->sycashSession)
            ->postJson('/admin/wallet/charge', [
                'phone_number' => '0911111111',
                'amount'       => 5000,
            ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_admin_cannot_charge_wallet(): void
    {
        $this->postJson('/admin/wallet/charge', [
            'phone_number' => '0911111111',
            'amount'       => 5000,
        ])->assertStatus(403); // no session → isPrimaryAdmin() returns false
    }

    public function test_charge_wallet_returns_404_for_nonexistent_phone(): void
    {
        $response = $this->withSession($this->primarySession)
            ->postJson('/admin/wallet/charge', [
                'phone_number' => '0999999999', // does not exist
                'amount'       => 1000,
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('code', 'WALLET_NOT_FOUND');
    }

    // ─── WALLET TRANSACTIONS ──────────────────────────────────────────────────

    public function test_authenticated_admin_can_view_wallet_transactions(): void
    {
        $response = $this->withSession($this->primarySession)
            ->getJson("/admin/wallet/{$this->primaryAdminWallet->id}/transactions");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['wallet', 'transactions']);
    }

    public function test_wallet_transactions_returns_404_for_nonexistent_wallet(): void
    {
        $response = $this->withSession($this->primarySession)
            ->getJson('/admin/wallet/999999/transactions');

        $response->assertStatus(404);
    }

    public function test_unauthenticated_request_to_transactions_returns_401(): void
    {
        $this->getJson("/admin/wallet/{$this->primaryAdminWallet->id}/transactions")
            ->assertStatus(401);
    }

    // ─── DASHBOARD ────────────────────────────────────────────────────────────

    public function test_authenticated_admin_can_view_dashboard(): void
    {
        $response = $this->withSession($this->primarySession)
            ->getJson('/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['stats']);
    }

    public function test_unauthenticated_request_to_dashboard_returns_401(): void
    {
        $this->getJson('/admin/dashboard')->assertStatus(401);
    }

    // ─── REPORT ───────────────────────────────────────────────────────────────

    public function test_primary_admin_can_generate_report(): void
    {
        $response = $this->withSession($this->primarySession)
            ->getJson('/admin/report');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['report_data' => ['ride_stats', 'financial_stats']]);
    }

    public function test_report_accepts_date_range(): void
    {
        $response = $this->withSession($this->primarySession)
            ->getJson('/admin/report?start_date=2024-01-01&end_date=2024-12-31');

        $response->assertStatus(200);
    }

    public function test_report_rejects_invalid_date_format(): void
    {
        $response = $this->withSession($this->primarySession)
            ->getJson('/admin/report?start_date=not-a-date');

        $response->assertStatus(422);
    }

    public function test_sycash_admin_cannot_access_report(): void
    {
        $this->withSession($this->sycashSession)
            ->getJson('/admin/report')
            ->assertStatus(403);
    }

    // ─── SHOW ALL WALLETS ─────────────────────────────────────────────────────

    public function test_authenticated_admin_can_view_all_wallets(): void
    {
        $response = $this->withSession($this->primarySession)
            ->getJson('/admin/wallets');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['admin_wallets', 'all_wallets', 'total_count']);
    }

    public function test_unauthenticated_request_to_wallets_returns_401(): void
    {
        $this->getJson('/admin/wallets')->assertStatus(401);
    }

    // ─── VERIFICATIONS ────────────────────────────────────────────────────────

    public function test_primary_admin_can_list_pending_verifications(): void
    {
        User::factory()->create(['verification_status' => 'pending']);

        $response = $this->withSession($this->primarySession)
            ->getJson('/admin/verifications/pending');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_sycash_admin_cannot_list_pending_verifications(): void
    {
        $this->withSession($this->sycashSession)
            ->getJson('/admin/verifications/pending')
            ->assertStatus(403);
    }

    public function test_primary_admin_can_approve_verification(): void
    {
        $user = User::factory()->create([
            'verification_status' => 'pending',
            'password'            => bcrypt('password123'),
        ]);

        $response = $this->withSession($this->primarySession)
            ->postJson("/admin/verifications/{$user->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_sycash_admin_cannot_approve_verification(): void
    {
        $user = User::factory()->create(['verification_status' => 'pending']);

        $this->withSession($this->sycashSession)
            ->postJson("/admin/verifications/{$user->id}/approve")
            ->assertStatus(403);
    }

    public function test_primary_admin_can_reject_verification(): void
    {
        $user = User::factory()->create([
            'verification_status'   => 'pending',
            'is_verified_driver'    => true,
            'is_verified_passenger' => true,
        ]);

        $response = $this->withSession($this->primarySession)
            ->postJson("/admin/verifications/{$user->id}/reject");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'id'                    => $user->id,
            'verification_status'   => 'rejected',
            'is_verified_driver'    => false,
            'is_verified_passenger' => false,
        ]);
    }

    public function test_approve_verification_returns_422_for_nonexistent_user(): void
    {
        $response = $this->withSession($this->primarySession)
            ->postJson('/admin/verifications/999999/approve');

        $response->assertStatus(422);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    /**
     * Seed admin wallets using the overridden config values.
     */
    private function seedAdminWallets(): void
    {
        foreach (['primary', 'sycash'] as $type) {
            $config = config("admin.{$type}");

            $adminUser = User::firstOrCreate(
                ['email' => $config['email']],
                [
                    'first_name'        => $config['first_name'],
                    'last_name'         => $config['last_name'],
                    'phone_number'      => $config['phone'],
                    'password'          => bcrypt($config['password']),
                    'gender'            => 'M',
                    'address'           => 'دمشق',
                    'status'            => 1,
                    'email_verified_at' => now(),
                ]
            );

            if (!Wallet::where('phone_number', $config['phone'])->exists()) {
                $prefix = $config['wallet_prefix'];
                $wallet = Wallet::create([
                    'user_id'       => $adminUser->id,
                    'phone_number'  => $config['phone'],
                    'wallet_number' => $prefix . '_TEST_001',
                    'balance'       => 10_000_000,
                ]);
                $adminUser->update(['wallet_id' => $wallet->id]);
            }
        }

        $this->primaryAdminWallet = Wallet::where('phone_number', config('admin.primary.phone'))->first();
        $this->sycashAdminWallet  = Wallet::where('phone_number', config('admin.sycash.phone'))->first();
    }
}
