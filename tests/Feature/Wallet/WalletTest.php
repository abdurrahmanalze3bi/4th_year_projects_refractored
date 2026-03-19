<?php

namespace Tests\Feature\Wallet;

use App\Models\Otp;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    private User   $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear ALL OTP records before each test so rate limiter never triggers
        Otp::truncate();

        $this->user  = User::factory()->create(['password' => bcrypt('password123')]);
        $this->seedAdminWallets();
        $this->token = $this->getToken($this->user);
    }

    // ── Initiate ──────────────────────────────────────────────────────────────

    public function test_can_initiate_wallet_creation(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => '0983337214',
                'password'     => 'password123',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_initiate_returns_otp_in_testing_mode(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => '0983337214',
                'password'     => 'password123',
            ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('otp_code'));
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $response->json('otp_code'));
    }

    public function test_initiate_fails_with_wrong_password(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => '0983337214',
                'password'     => 'wrong_password',
            ])
            ->assertStatus(401);
    }

    public function test_initiate_fails_if_wallet_already_exists(): void
    {
        $wallet = Wallet::create([
            'user_id'       => $this->user->id,
            'phone_number'  => '0983337214',
            'wallet_number' => 'WLT-TEST-001',
            'balance'       => 0,
        ]);
        $this->user->update(['wallet_id' => $wallet->id]);

        $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => '0912345678',
                'password'     => 'password123',
            ])
            ->assertStatus(409);
    }

    public function test_initiate_fails_with_duplicate_phone(): void
    {
        $other = User::factory()->create();
        Wallet::create([
            'user_id'       => $other->id,
            'phone_number'  => '0983337214',
            'wallet_number' => 'WLT-OTHER-001',
            'balance'       => 0,
        ]);

        $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => '0983337214',
                'password'     => 'password123',
            ])
            ->assertStatus(422);
    }

    // ── Verify and create ─────────────────────────────────────────────────────

    public function test_can_create_wallet_after_otp_verification(): void
    {
        $initResponse = $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => '0983337214',
                'password'     => 'password123',
            ]);

        $initResponse->assertStatus(200);
        $otp = $initResponse->json('otp_code');
        $this->assertNotNull($otp);

        $this->withToken($this->token)
            ->postJson('/api/wallet/verify-and-create', [
                'phone_number' => '0983337214',
                'otp_code'     => $otp,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('wallets', ['user_id' => $this->user->id]);
    }

    public function test_wallet_creation_fails_with_wrong_otp(): void
    {
        $this->withToken($this->token)->postJson('/api/wallet/initiate', [
            'phone_number' => '0983337214',
            'password'     => 'password123',
        ]);

        $this->withToken($this->token)
            ->postJson('/api/wallet/verify-and-create', [
                'phone_number' => '0983337214',
                'otp_code'     => '000000',
            ])
            ->assertStatus(400);
    }

    // ── Balance ───────────────────────────────────────────────────────────────

    public function test_can_check_wallet_balance(): void
    {
        $wallet = Wallet::create([
            'user_id'       => $this->user->id,
            'phone_number'  => '0983337214',
            'wallet_number' => 'WLT-BAL-001',
            'balance'       => 500_000,
        ]);
        $this->user->update(['wallet_id' => $wallet->id]);

        $this->withToken($this->token)->getJson('/api/wallet/balance')
            ->assertStatus(200)
            ->assertJsonStructure(['balance', 'wallet_number']);
    }

    public function test_balance_check_fails_without_wallet(): void
    {
        $this->withToken($this->token)->getJson('/api/wallet/balance')->assertStatus(404);
    }

    public function test_balance_requires_authentication(): void
    {
        $this->getJson('/api/wallet/balance')->assertStatus(401);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    private function getToken(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }
}
