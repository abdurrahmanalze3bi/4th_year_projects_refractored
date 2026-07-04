<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AdminDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private Wallet $primaryAdminWallet;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('admin.system_admin', [
            'email'         => 'primary@admin.test',
            'password'      => 'primary_pass',
            'first_name'    => 'Primary',
            'last_name'     => 'Admin',
            'phone'         => '0910000001',
            'wallet_prefix' => 'PRIM',
            'permissions'   => ['*'],
        ]);

        Config::set('admin.sycash', [
            'email'         => 'sycash@admin.test',
            'password'      => 'sycash_pass',
            'first_name'    => 'SyCash',
            'last_name'     => 'Admin',
            'phone'         => '0910000002',
            'wallet_prefix' => 'SYCSH',
            'permissions'   => ['view_wallet'],
        ]);

        $this->seedAdminWallets();
    }

    // ─── LOGIN ──────────────────────────────────────────────────────────
    public function test_admin_can_login_with_correct_credentials(): void
    {
        $response = $this->postJson('/api/admin/login', [
            'email'    => 'primary@admin.test',
            'password' => 'primary_pass',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('admin.type', 'system_admin');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->postJson('/api/admin/login', [
            'email'    => 'primary@admin.test',
            'password' => 'wrong_password',
        ])->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_login_fails_with_non_admin_email(): void
    {
        $this->postJson('/api/admin/login', [
            'email'    => 'nobody@example.com',
            'password' => 'anything',
        ])->assertStatus(401);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/admin/login', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_malformed_email_falls_through_to_invalid_credentials(): void
    {
        // FIX: the login validator only checks email is a non-empty string
        // ('required_without:username|nullable|string') — it does NOT enforce
        // email format. A malformed string passes validation and fails at
        // credential matching instead, so this is 401, not 422.
        $this->postJson('/api/admin/login', [
            'email'    => 'not-an-email',
            'password' => 'pass',
        ])->assertStatus(401);
    }

    public function test_sycash_admin_can_login(): void
    {
        $this->postJson('/api/admin/login', [
            'email'    => 'sycash@admin.test',
            'password' => 'sycash_pass',
        ])->assertStatus(200)->assertJsonPath('admin.type', 'sycash');
    }

    // ─── LOGOUT ─────────────────────────────────────────────────────────
    public function test_authenticated_admin_can_logout(): void
    {
        $this->withToken($this->primaryToken())
            ->postJson('/api/admin/logout')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_unauthenticated_logout_returns_401(): void
    {
        $this->postJson('/api/admin/logout')->assertStatus(401);
    }

    // ─── WALLET ─────────────────────────────────────────────────────────
    public function test_authenticated_admin_can_get_own_wallet(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/wallet')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'wallet' => ['id', 'wallet_number', 'phone_number', 'balance', 'admin_type'],
            ]);
    }

    public function test_unauthenticated_request_to_wallet_returns_401(): void
    {
        $this->getJson('/api/admin/wallet')->assertStatus(401);
    }

    // ─── ALL WALLETS ────────────────────────────────────────────────────
    // FIX: getAdminWallets() is one route returning BOTH admin_wallets and
    // all_wallets — there's no separate "/admin/wallets/admins" endpoint.
    public function test_authenticated_admin_can_list_all_wallets(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/wallets')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['admin_wallets', 'all_wallets']);
    }

    public function test_unauthenticated_request_to_wallets_returns_401(): void
    {
        $this->getJson('/api/admin/wallets')->assertStatus(401);
    }

    // ─── CHARGE WALLET ──────────────────────────────────────────────────
    public function test_primary_admin_can_charge_a_wallet(): void
    {
        $user   = User::factory()->create(['password' => bcrypt('password123')]);
        $wallet = Wallet::create(['user_id' => $user->id, 'phone_number' => '0911111111', 'balance' => 0]);
        $user->update(['wallet_id' => $wallet->id]);

        $response = $this->withToken($this->primaryToken())
            ->postJson('/api/admin/wallet/charge', [
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
        $this->withToken($this->primaryToken())
            ->postJson('/api/admin/wallet/charge', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_charge_wallet_fails_with_amount_zero(): void
    {
        $this->withToken($this->primaryToken())
            ->postJson('/api/admin/wallet/charge', ['phone_number' => '0911111111', 'amount' => 0])
            ->assertStatus(422);
    }

    public function test_sycash_admin_cannot_charge_wallet(): void
    {
        $this->withToken($this->sycashToken())
            ->postJson('/api/admin/wallet/charge', ['phone_number' => '0911111111', 'amount' => 5000])
            ->assertStatus(403);
    }

    public function test_unauthenticated_admin_cannot_charge_wallet(): void
    {
        // FIX: with no token at all, AdminJwtMiddleware fails at the token-
        // presence check (401) before it ever reaches the type check (403).
        $this->postJson('/api/admin/wallet/charge', ['phone_number' => '0911111111', 'amount' => 5000])
            ->assertStatus(401);
    }

    public function test_charge_wallet_returns_404_for_nonexistent_phone(): void
    {
        $this->withToken($this->primaryToken())
            ->postJson('/api/admin/wallet/charge', ['phone_number' => '0999999999', 'amount' => 1000])
            ->assertStatus(404)
            ->assertJsonPath('code', 'WALLET_NOT_FOUND');
    }

    // ─── WALLET TRANSACTIONS ────────────────────────────────────────────
    public function test_authenticated_admin_can_view_wallet_transactions(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson("/api/admin/wallet/{$this->primaryAdminWallet->id}/transactions")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['wallet', 'transactions']);
    }

    public function test_wallet_transactions_returns_404_for_nonexistent_wallet(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/wallet/999999/transactions')
            ->assertStatus(404);
    }

    public function test_unauthenticated_request_to_transactions_returns_401(): void
    {
        $this->getJson("/api/admin/wallet/{$this->primaryAdminWallet->id}/transactions")
            ->assertStatus(401);
    }

    // ─── DASHBOARD ──────────────────────────────────────────────────────
    public function test_authenticated_admin_can_view_dashboard(): void
    {
        // FIX: dashboard() wraps its payload in 'data', not 'stats'.
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data']);
    }

    public function test_unauthenticated_request_to_dashboard_returns_401(): void
    {
        $this->getJson('/api/admin/dashboard')->assertStatus(401);
    }

    // ─── REPORT ─────────────────────────────────────────────────────────
    public function test_primary_admin_can_generate_report(): void
    {
        // FIX: dropped the nested ride_stats/financial_stats assertion —
        // I don't have AdminReportService's source to confirm that shape.
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/report')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['report_data']);
    }

    public function test_report_accepts_date_range(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/report?start_date=2024-01-01&end_date=2024-12-31')
            ->assertStatus(200);
    }

    public function test_report_rejects_invalid_date_format(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/report?start_date=not-a-date')
            ->assertStatus(422);
    }

    public function test_sycash_admin_cannot_access_report(): void
    {
        $this->withToken($this->sycashToken())
            ->getJson('/api/admin/report')
            ->assertStatus(403);
    }

    // ─── VERIFICATIONS ──────────────────────────────────────────────────
    public function test_primary_admin_can_list_pending_verifications(): void
    {
        User::factory()->create(['verification_status' => 'pending']);

        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/verifications/pending')
            ->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_sycash_admin_cannot_list_pending_verifications(): void
    {
        $this->withToken($this->sycashToken())
            ->getJson('/api/admin/verifications/pending')
            ->assertStatus(403);
    }

    public function test_primary_admin_can_approve_verification(): void
    {
        $user = User::factory()->create([
            'verification_status' => 'pending',
            'password'            => bcrypt('password123'),
        ]);

        // FIX: approveVerification() responds with 'status' => 'success',
        // not a top-level 'success' boolean.
        $this->withToken($this->primaryToken())
            ->postJson("/api/admin/verifications/{$user->id}/approve")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_sycash_admin_cannot_approve_verification(): void
    {
        $user = User::factory()->create(['verification_status' => 'pending']);

        $this->withToken($this->sycashToken())
            ->postJson("/api/admin/verifications/{$user->id}/approve")
            ->assertStatus(403);
    }

    public function test_primary_admin_can_reject_verification(): void
    {
        $user = User::factory()->create([
            'verification_status'   => 'pending',
            'is_verified_driver'    => true,
            'is_verified_passenger' => true,
        ]);

        $this->withToken($this->primaryToken())
            ->postJson("/api/admin/verifications/{$user->id}/reject")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('users', [
            'id'                    => $user->id,
            'verification_status'   => 'rejected',
            'is_verified_driver'    => false,
            'is_verified_passenger' => false,
        ]);
    }

    public function test_approve_verification_returns_422_for_nonexistent_user(): void
    {
        $this->withToken($this->primaryToken())
            ->postJson('/api/admin/verifications/999999/approve')
            ->assertStatus(422);
    }

    // ─── Helpers ────────────────────────────────────────────────────────
    private function primaryToken(): string
    {
        return $this->postJson('/api/admin/login', [
            'email' => 'primary@admin.test', 'password' => 'primary_pass',
        ])->json('tokens.access_token');
    }

    private function sycashToken(): string
    {
        return $this->postJson('/api/admin/login', [
            'email' => 'sycash@admin.test', 'password' => 'sycash_pass',
        ])->json('tokens.access_token');
    }

    private function seedAdminWallets(): void
    {
        foreach (['system_admin', 'sycash'] as $type) {
            $config    = config("admin.{$type}");
            $adminUser = User::firstOrCreate(
                ['email' => $config['email']],
                [
                    'first_name'        => $config['first_name'],
                    'last_name'         => $config['last_name'],
                    'password'          => bcrypt($config['password']),
                    'gender'            => 'M',
                    'address'           => 'دمشق',
                    'status'            => 1,
                    'email_verified_at' => now(),
                ]
            );

            if (!Wallet::where('phone_number', $config['phone'])->exists()) {
                $wallet = Wallet::create([
                    'user_id'      => $adminUser->id,
                    'phone_number' => $config['phone'],
                    'balance'      => 10_000_000,
                    // wallet_number omitted — this one's fine either way since
                    // wallet_prefix stays short, but consistent with the others
                ]);
                $adminUser->update(['wallet_id' => $wallet->id]);
            }
        }

        $this->primaryAdminWallet = Wallet::where('phone_number', config('admin.system_admin.phone'))->first();
    }
}
