<?php

namespace App\Http\Controllers\API;

use App\Domain\ValueObjects\Money;
use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAuthService;
use App\Services\Admin\AdminWalletService;
use App\Services\Admin\AdminReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;


class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly AdminAuthService $authService,
        private readonly AdminWalletService $walletService,
        private readonly AdminReportService $reportService
    ) {}

    // ============================================================
    // AUTHENTICATION
    // ============================================================

    /**
     * Admin login
     * POST /admin/login
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'code' => 'VALIDATION_FAILED',
                'errors' => $validator->errors()
            ], 422);
        }

        $adminConfig = $this->authService->authenticate(
            $request->email,
            $request->password
        );

        if (!$adminConfig) {
            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Invalid admin credentials'
            ], 401);
        }

        // Ensure admin wallet exists
        $this->walletService->getOrCreateWallet($adminConfig);

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'admin_type' => $adminConfig['type']
        ]);
    }

    /**
     * Admin logout
     * POST /admin/logout
     */
    public function logout(): JsonResponse
    {
        $this->authService->logout();

        return response()->json([
            'status' => 'success',
            'message' => 'Logout successful'
        ]);
    }

    /**
     * Get current admin info
     * GET /admin/info
     */
    public function getAdminInfo(): JsonResponse
    {
        if (!$this->authService->isAuthenticated()) {
            return response()->json([
                'status' => 'error',
                'code' => 'AUTH_REQUIRED',
                'message' => 'Admin session expired or invalid'
            ], 401);
        }

        $adminInfo = $this->authService->getAdminInfo();

        return response()->json([
            'status' => 'success',
            'admin' => $adminInfo
        ]);
    }

    // ============================================================
    // WALLET OPERATIONS
    // ============================================================

    /**
     * Get current admin wallet
     * GET /admin/wallet
     */
    public function getAdminWallet(): JsonResponse
    {
        if (!$this->authService->isAuthenticated()) {
            return $this->unauthorizedResponse();
        }

        $adminConfig = $this->authService->getCurrentAdmin();
        $wallet = $this->walletService->getOrCreateWallet($adminConfig);

        return response()->json([
            'status' => 'success',
            'wallet' => [
                'id' => $wallet->id,
                'wallet_number' => $wallet->wallet_number,
                'phone_number' => $wallet->phone_number,
                'balance' => Money::from($wallet->balance)->formatted(),
                'admin_type' => $adminConfig['type']
            ]
        ]);
    }

    /**
     * Get all admin wallets
     * GET /admin/wallets/admins
     */
    public function getAdminWallets(): JsonResponse
    {
        if (!$this->authService->isAuthenticated()) {
            return $this->unauthorizedResponse();
        }

        $wallets = $this->walletService->getAdminWallets();

        return response()->json([
            'status' => 'success',
            'admin_wallets' => $wallets
        ]);
    }

    /**
     * Charge a wallet (Primary Admin only)
     * POST /admin/wallet/charge
     */
    public function chargeWallet(Request $request): JsonResponse
    {
        if (!$this->authService->isPrimaryAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|min:10|max:15',
            'amount' => 'required|numeric|min:1|max:1000000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'code' => 'VALIDATION_FAILED',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $adminConfig = $this->authService->getCurrentAdmin();
            $amount = Money::from($request->amount);

            $result = $this->walletService->chargeWallet(
                $request->phone_number,
                $amount,
                $adminConfig
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Wallet charged successfully',
                'transaction_id' => $result['transaction']->transaction_id,
                'wallet' => [
                    'phone_number' => $result['wallet']->phone_number,
                    'previous_balance' => $result['previous_balance']->formatted(),
                    'new_balance' => $result['new_balance']->formatted(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Wallet charge failure', [
                'error' => $e->getMessage(),
                'phone' => $request->phone_number
            ]);

            return response()->json([
                'status' => 'error',
                'code' => 'PROCESSING_ERROR',
                'message' => 'Failed to charge wallet'
            ], 500);
        }
    }

    /**
     * Get wallet transactions
     * GET /admin/wallet/{walletId}/transactions
     */
    public function showWalletTransactions(int $walletId): JsonResponse
    {
        if (!$this->authService->isAuthenticated()) {
            return $this->unauthorizedResponse();
        }

        try {
            $result = $this->walletService->getWalletTransactions($walletId);

            return response()->json([
                'status' => 'success',
                'wallet' => $result['wallet'],
                'transactions' => $result['transactions']
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Wallet not found'
            ], 404);
        }
    }

    // ============================================================
    // REPORTS & DASHBOARD
    // ============================================================

    /**
     * Generate financial report
     * GET /admin/reports
     */
    public function showReport(Request $request): JsonResponse
    {
        if (!$this->authService->isPrimaryAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'code' => 'VALIDATION_FAILED',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $report = $this->reportService->generateReport(
                $request->input('start_date'),
                $request->input('end_date')
            );

            return response()->json([
                'status' => 'success',
                'report_data' => $report
            ]);
        } catch (\Exception $e) {
            Log::error('Report generation failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'code' => 'REPORT_ERROR',
                'message' => 'Failed to generate report'
            ], 500);
        }
    }

    /**
     * Get dashboard statistics
     * GET /admin/dashboard
     */
    public function showDashboard(): JsonResponse
    {
        if (!$this->authService->isAuthenticated()) {
            return $this->unauthorizedResponse();
        }

        try {
            $stats = $this->reportService->getDashboardStats();

            return response()->json([
                'status' => 'success',
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Dashboard stats failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load dashboard'
            ], 500);
        }
    }

    // ============================================================
    // VERIFICATION (Delegated to VerificationRepository)
    // ============================================================

    /**
     * List pending verifications
     * GET /admin/verifications/pending
     */
    public function pendingVerifications(Request $request): JsonResponse
    {
        if (!$this->authService->isPrimaryAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Access denied'], 403);
        }

        $pending = \App\Models\User::with(['photos', 'profile'])
            ->where('verification_status', 'pending')
            ->get()
            ->map(function ($u) {
                $documentTypes = $u->photos->pluck('type')->toArray();
                $isDriver = in_array('license', $documentTypes) ||
                    in_array('mechanic_card', $documentTypes) ||
                    !empty($u->profile->car_pic);

                return [
                    'user_id' => $u->id,
                    'name' => trim($u->first_name . ' ' . $u->last_name),
                    'email' => $u->email,
                    'type' => $isDriver ? 'driver' : 'passenger',
                    'documents' => $u->photos->map(fn($p) => [
                        'type' => $p->type,
                        'url' => asset("storage/{$p->path}")
                    ]),
                    'created_at' => $u->updated_at->toIso8601String()
                ];
            });

        return response()->json(['success' => true, 'data' => $pending]);
    }

    /**
     * Approve verification
     * POST /admin/verifications/{userId}/approve
     */
    public function approveVerification(Request $request, int $userId): JsonResponse
    {
        if (!$this->authService->isPrimaryAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Access denied'], 403);
        }

        try {
            $user = \App\Models\User::with(['photos', 'profile'])->findOrFail($userId);
            $repo = app(\App\Interfaces\VerificationRepositoryInterface::class);

            $documentTypes = $user->photos->pluck('type')->toArray();
            $isDriver = in_array('license', $documentTypes) ||
                in_array('mechanic_card', $documentTypes);

            $verifiedUser = $isDriver
                ? $repo->verifyDriver($userId)
                : $repo->verifyPassenger($userId);

            return response()->json([
                'success' => true,
                'message' => ($isDriver ? 'Driver' : 'Passenger') . ' verification approved',
                'user' => [
                    'id' => $verifiedUser->id,
                    'verification_status' => $verifiedUser->verification_status,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Reject verification
     * POST /admin/verifications/{userId}/reject
     */
    public function rejectVerification(Request $request, int $userId): JsonResponse
    {
        if (!$this->authService->isPrimaryAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Access denied'], 403);
        }

        $user = \App\Models\User::findOrFail($userId);

        $user->update([
            'verification_status' => 'rejected',
            'is_verified_passenger' => false,
            'is_verified_driver' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Verification rejected'
        ]);
    }

    // ============================================================
    // HELPER METHODS
    // ============================================================

    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'code' => 'AUTH_REQUIRED',
            'message' => 'Admin session expired or invalid'
        ], 401);
    }
    /**
     * Show all wallets overview page
     * Delegates all data fetching to AdminWalletService
     */
    /**
     * Get all wallets overview
     */
    public function showWallets()
    {
        if (!$this->authService->isAuthenticated()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $adminWallets = $this->walletService->getAdminWallets();
        $allWallets   = $this->walletService->getAllWallets();

        return response()->json([
            'status'        => 'success',
            'admin_wallets' => $adminWallets,
            'all_wallets'   => $allWallets,
            'total_count'   => count($allWallets),
        ]);
    }
}
