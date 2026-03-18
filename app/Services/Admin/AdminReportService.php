<?php

namespace App\Services\Admin;

use App\Domain\ValueObjects\Money;
use App\Models\Ride;
use App\Models\Booking;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service for generating admin reports
 * Eliminates report generation logic from AdminDashboardController
 */
class AdminReportService
{
    public function __construct(
        private AdminWalletService $walletService
    ) {}

    /**
     * Generate financial and ride statistics report
     */
    public function generateReport(?string $startDate, ?string $endDate): array
    {
        $startDateObj = $startDate ? Carbon::parse($startDate)->startOfDay() : null;
        $endDateObj = $endDate ? Carbon::parse($endDate)->endOfDay() : null;

        return [
            'ride_stats' => $this->getRideStatistics($startDateObj, $endDateObj),
            'financial_stats' => $this->getFinancialStatistics($startDateObj, $endDateObj),
            'date_range' => [
                'start' => $startDateObj?->format('Y-m-d H:i:s'),
                'end' => $endDateObj?->format('Y-m-d H:i:s')
            ]
        ];
    }

    /**
     * Get ride statistics for date range
     */
    private function getRideStatistics(?Carbon $startDate, ?Carbon $endDate): array
    {
        $query = Ride::query();

        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        $total = $query->count();

        return [
            'total' => $total,
            'canceled' => (clone $query)->where('status', 'canceled')->count(),
            'active' => (clone $query)->where('status', 'active')->count(),
            'awaiting_confirmation' => (clone $query)->where('status', 'awaiting_confirmation')->count(),
            'completed' => (clone $query)->where('status', 'finished')->count(),
        ];
    }

    /**
     * Get financial statistics for date range
     */
    private function getFinancialStatistics(?Carbon $startDate, ?Carbon $endDate): array
    {
        $adminConfigs = config('admin');

        // Get admin wallets
        $syCashWallet = Wallet::where('phone_number', $adminConfigs['sycash']['phone'])->firstOrFail();
        $primaryAdminWallet = Wallet::where('phone_number', $adminConfigs['primary']['phone'])->firstOrFail();

        // Build queries for SyCash fees
        $syCashFeeQuery = WalletTransaction::where('wallet_id', $syCashWallet->id)
            ->where('type', 'ride_creation_fee');

        // Build queries for Primary Admin collections
        $adminCollectionQuery = WalletTransaction::where('wallet_id', $primaryAdminWallet->id)
            ->where('type', 'ride_booking_received')
            ->where('amount', '>', 0);

        // Build queries for driver payouts (money OUT from admin wallet)
        $adminTransferQuery = $this->buildDriverPayoutQuery($primaryAdminWallet->id);

        // Apply date filters
        if ($startDate && $endDate) {
            $syCashFeeQuery->whereBetween('created_at', [$startDate, $endDate]);
            $adminCollectionQuery->whereBetween('created_at', [$startDate, $endDate]);
            $adminTransferQuery->whereBetween('created_at', [$startDate, $endDate]);
        }

        // Calculate totals
        $totalTransferred = abs((float) $adminTransferQuery->sum('amount'));

        // Calculate active ride amounts
        $activeRideAmount = $this->calculateActiveRideAmount();

        return [
            'sycash' => [
                'current_balance' => Money::from($syCashWallet->balance)->formatted(),
                'total_ride_creation_fees' => Money::from($syCashFeeQuery->sum('amount'))->formatted(),
            ],
            'admin_wallet' => [
                'current_balance' => Money::from($primaryAdminWallet->balance)->formatted(),
                'total_booking_collected' => Money::from($adminCollectionQuery->sum('amount'))->formatted(),
                'total_booking_transferred' => Money::from($totalTransferred)->formatted(),
            ],
            'active_rides_amount' => Money::from($activeRideAmount)->formatted(),
        ];
    }

    /**
     * Build query for driver payouts (comprehensive)
     */
    private function buildDriverPayoutQuery(int $adminWalletId)
    {
        return WalletTransaction::where('wallet_id', $adminWalletId)
            ->where(function($query) {
                $query->where('type', 'ride_completion_payment')
                    ->orWhere('type', 'payment_to_driver')
                    ->orWhere('type', 'driver_payment')
                    ->orWhere('type', 'ride_payout')
                    ->orWhere('type', 'driver_payout')
                    ->orWhere('type', 'admin_debit')
                    ->orWhere(function($q) {
                        $q->where('description', 'LIKE', '%payment to driver%')
                            ->orWhere('description', 'LIKE', '%driver payout%')
                            ->orWhere('description', 'LIKE', '%ride completion%')
                            ->orWhere('description', 'LIKE', '%transfer to driver%');
                    });
            })
            ->where('amount', '<', 0);
    }

    /**
     * Calculate total amount in active rides
     */
    private function calculateActiveRideAmount(): float
    {
        return Booking::whereHas('ride', function ($query) {
            $query->whereIn('status', ['active', 'awaiting_confirmation']);
        })
            ->join('rides', 'bookings.ride_id', '=', 'rides.id')
            ->sum(DB::raw('bookings.seats * rides.price_per_seat'));
    }

    /**
     * Get dashboard overview statistics
     */
    public function getDashboardStats(): array
    {
        $adminConfigs = config('admin');

        return [
            'total_wallets' => Wallet::count(),
            'total_users' => \App\Models\User::count(),
            'total_balance' => Money::from(Wallet::sum('balance'))->formatted(),
            'total_transactions' => WalletTransaction::count(),
            'all_admin_wallets' => $this->walletService->getAdminWallets(),
            'recent_transactions' => WalletTransaction::with('wallet:id,wallet_number,phone_number')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(fn($t) => [
                    'id' => $t->id,
                    'wallet_number' => $t->wallet->wallet_number,
                    'type' => $t->type,
                    'amount' => Money::from($t->amount)->formatted(),
                    'created_at' => $t->created_at->toDateTimeString()
                ])
                ->toArray()
        ];
    }
}
