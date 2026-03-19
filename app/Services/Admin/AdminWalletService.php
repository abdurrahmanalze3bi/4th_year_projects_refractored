<?php

namespace App\Services\Admin;

use App\Domain\ValueObjects\Money;
use App\Models\Wallet;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for admin wallet operations
 * Eliminates wallet logic from AdminDashboardController
 */
class AdminWalletService
{
    /**
     * Get or create admin wallet
     */
    public function getOrCreateWallet(array $adminConfig): Wallet
    {
        $wallet = Wallet::where('phone_number', $adminConfig['phone'])->first();

        if ($wallet) {
            return $wallet;
        }

        return $this->createAdminWallet($adminConfig);
    }

    /**
     * Create admin wallet with initial transaction
     */
    private function createAdminWallet(array $adminConfig): Wallet
    {
        return DB::transaction(function () use ($adminConfig) {
            // Create or find admin user
            $adminUser = User::firstOrCreate(
                ['email' => $adminConfig['email']],
                [
                    'first_name'        => $adminConfig['first_name'],
                    'last_name'         => $adminConfig['last_name'],
                    'phone_number'      => $adminConfig['phone'],
                    'email_verified_at' => now(),
                    'password'          => bcrypt($adminConfig['password']),
                    'status'            => 1,
                    'gender'            => 'M',
                    'address'           => $adminConfig['address'] ?? 'دمشق',
                ]
            );

            // Generate unique wallet number
            $walletNumber = $this->generateWalletNumber($adminConfig['wallet_prefix']);

            // Create admin wallet
            $wallet = Wallet::create([
                'user_id' => $adminUser->id,
                'wallet_number' => $walletNumber,
                'phone_number' => $adminConfig['phone'],
                'balance' => 0.00
            ]);

            // Update user with wallet_id
            $adminUser->wallet_id = $wallet->id;
            $adminUser->save();

            // Create initial transaction
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $adminUser->id,
                'type' => 'admin_credit',
                'amount' => 0.00,
                'previous_balance' => 0.00,
                'new_balance' => 0.00,
                'description' => ucfirst($adminConfig['type']) . ' admin wallet creation',
                'transaction_id' => $adminConfig['wallet_prefix'] . '_INIT_' . time() . '_' . Str::random(8),
                'status' => 'completed',
                'metadata' => [
                    'admin_email' => $adminConfig['email'],
                    'admin_type' => $adminConfig['type'],
                    'creation_type' => 'auto_generated',
                    'created_at' => now()
                ]
            ]);

            Log::info('Admin wallet created', [
                'wallet_id' => $wallet->id,
                'wallet_number' => $walletNumber,
                'admin_type' => $adminConfig['type']
            ]);

            return $wallet;
        });
    }

    /**
     * Generate unique wallet number
     */
    private function generateWalletNumber(string $prefix): string
    {
        do {
            $walletNumber = $prefix . str_pad(rand(0, 9999999999), 10, '0', STR_PAD_LEFT);
        } while (Wallet::where('wallet_number', $walletNumber)->exists());

        return $walletNumber;
    }

    /**
     * Charge a wallet (admin operation)
     */
    public function chargeWallet(
        string $phoneNumber,
        Money $amount,
        array $adminConfig
    ): array {
        return DB::transaction(function () use ($phoneNumber, $amount, $adminConfig) {
            // Find wallet
            $wallet = Wallet::where('phone_number', $phoneNumber)
                ->with('user:id,first_name,last_name')
                ->lockForUpdate()
                ->firstOrFail();

            // Calculate new balance
            $previousBalance = $wallet->balance;
            $newBalance = $previousBalance + $amount->amount();

            // Update wallet
            $wallet->balance = $newBalance;
            $wallet->save();

            // Create transaction
            $transactionId = strtoupper($adminConfig['type']) . '_' . time() . '_' . Str::random(8);

            $transaction = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $wallet->user_id,
                'type' => 'admin_credit',
                'amount' => $amount->amount(),
                'previous_balance' => $previousBalance,
                'new_balance' => $newBalance,
                'description' => 'Admin wallet charge by ' . $adminConfig['type'],
                'transaction_id' => $transactionId,
                'status' => 'completed',
                'metadata' => [
                    'admin_email' => $adminConfig['email'],
                    'admin_type' => $adminConfig['type'],
                    'charged_amount' => $amount->formatted()
                ]
            ]);

            Log::info('Wallet charged by admin', [
                'wallet_id' => $wallet->id,
                'amount' => $amount->amount(),
                'admin_type' => $adminConfig['type']
            ]);

            return [
                'wallet' => $wallet,
                'transaction' => $transaction,
                'previous_balance' => Money::from($previousBalance),
                'new_balance' => Money::from($newBalance)
            ];
        });
    }

    /**
     * Get all admin wallets
     */
    public function getAdminWallets(): array
    {
        $adminConfigs = config('admin');
        $adminPhones = [
            $adminConfigs['primary']['phone'],
            $adminConfigs['sycash']['phone']
        ];

        $wallets = Wallet::whereIn('phone_number', $adminPhones)
            ->with('user:id,first_name,last_name,email')
            ->get();

        return $wallets->map(function ($wallet) use ($adminConfigs) {
            // Determine admin type
            $adminType = null;
            foreach (['primary', 'sycash'] as $type) {
                if ($adminConfigs[$type]['phone'] === $wallet->phone_number) {
                    $adminType = $type;
                    break;
                }
            }

            return [
                'id' => $wallet->id,
                'wallet_number' => $wallet->wallet_number,
                'phone_number' => $wallet->phone_number,
                'balance' => Money::from($wallet->balance)->formatted(),
                'owner' => $wallet->user
                    ? $wallet->user->first_name . ' ' . $wallet->user->last_name
                    : 'Unknown',
                'admin_type' => $adminType,
                'created_at' => $wallet->created_at,
                'updated_at' => $wallet->updated_at
            ];
        })->toArray();
    }

    /**
     * Get wallet transactions with pagination
     */
    public function getWalletTransactions(int $walletId, int $perPage = 10): array
    {
        $wallet = Wallet::findOrFail($walletId);

        $transactions = WalletTransaction::where('wallet_id', $walletId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return [
            'wallet' => $wallet,
            'transactions' => $transactions
        ];
    }
    /**
     * Get all wallets with their owners paginated
     * Used for the admin wallets overview page
     */
    /**
     * Get all wallets with their owners for API response
     */
    public function getAllWallets(): array
    {
        return Wallet::with('user:id,first_name,last_name,email')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($wallet) {
                return [
                    'id'             => $wallet->id,
                    'wallet_number'  => $wallet->wallet_number,
                    'phone_number'   => $wallet->phone_number,
                    'balance'        => Money::from($wallet->balance)->formatted(),
                    'owner'          => $wallet->user
                        ? $wallet->user->first_name . ' ' . $wallet->user->last_name
                        : 'Unknown',
                    'owner_email'    => $wallet->user?->email,
                    'created_at'     => $wallet->created_at->toDateTimeString(),
                ];
            })
            ->toArray();
    }
}
