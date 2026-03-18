<?php

namespace Tests\Feature\Wallet;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $this->token = $this->getToken($this->user);
    }

    // ─── Initiate wallet creation ─────────────────────────────────────────────

    public function test_can_initiate_wallet_creation(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => '0983337214',
                'password'     => 'password123',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'message', 'phone_number']);
    }

    public function test_initiate_returns_otp_in_testing_mode(): void
    {
        // WALLET_OTP_MODE=testing is set in phpunit.xml
        $response = $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => '0983337214',
                'password'     => 'password123',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['otp_code']);

        // OTP should be 6 digits
        $otp = $response->json('otp_code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp);
    }

    public function test_initiate_fails_with_wrong_password(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => '0983337214',
                'password'     => 'wrong_password',
            ]);

        $response->assertStatus(401);
    }

    public function test_initiate_fails_if_wallet_already_exists(): void
    {
        Wallet::create([
            'user_id'      => $this->user->id,
            'phone_number' => '0983337214',
            'balance'      => 0,
        ]);
        $this->user->wallet_id = Wallet::where('user_id', $this->user->id)->first()->id;
        $this->user->save();

        $response = $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => '0912345678',
                'password'     => 'password123',
            ]);

        $response->assertStatus(409);
    }

    public function test_initiate_fails_with_duplicate_phone(): void
    {
        // Another user already has this phone
        $otherUser = User::factory()->create();
        Wallet::create([
            'user_id'      => $otherUser->id,
            'phone_number' => '0983337214',
            'balance'      => 0,
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => '0983337214',
                'password'     => 'password123',
            ]);

        $response->assertStatus(422);
    }

    // ─── Verify and create wallet ─────────────────────────────────────────────

    public function test_can_create_wallet_after_otp_verification(): void
    {
        // Step 1: initiate
        $initResponse = $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => '0983337214',
                'password'     => 'password123',
            ]);

        $otp = $initResponse->json('otp_code');

        // Step 2: verify
        $response = $this->withToken($this->token)
            ->postJson('/api/wallet/verify-and-create', [
                'phone_number' => '0983337214',
                'otp_code'     => $otp,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['success', 'wallet_number', 'phone_number']);

        $this->assertDatabaseHas('wallets', [
            'user_id'      => $this->user->id,
            'phone_number' => '0983337214',
        ]);
    }

    public function test_wallet_creation_fails_with_wrong_otp(): void
    {
        // Initiate first to set up session
        $this->withToken($this->token)
            ->postJson('/api/wallet/initiate', [
                'phone_number' => '0983337214',
                'password'     => 'password123',
            ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/wallet/verify-and-create', [
                'phone_number' => '0983337214',
                'otp_code'     => '000000', // wrong code
            ]);

        $response->assertStatus(400);
    }

    // ─── Check balance ────────────────────────────────────────────────────────

    public function test_can_check_wallet_balance(): void
    {
        $wallet = Wallet::create([
            'user_id'      => $this->user->id,
            'phone_number' => '0983337214',
            'balance'      => 500_000,
        ]);
        $this->user->update(['wallet_id' => $wallet->id]);

        $response = $this->withToken($this->token)
            ->getJson('/api/wallet/balance');

        $response->assertStatus(200)
            ->assertJsonStructure(['balance', 'wallet_number'])
            ->assertJsonPath('balance', '500000.00');
    }

    public function test_balance_check_fails_without_wallet(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/wallet/balance');

        $response->assertStatus(404);
    }

    public function test_balance_requires_authentication(): void
    {
        $response = $this->getJson('/api/wallet/balance');
        $response->assertStatus(401);
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
