<?php

namespace App\Http\Controllers\API;

use App\Domain\ValueObjects\Money;
use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAuthService;
use App\Services\Admin\AdminWalletService;
use App\Services\Admin\AdminReportService;
use App\Services\Admin\AdminExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

final class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly AdminAuthService   $authService,
        private readonly AdminWalletService $walletService,
        private readonly AdminReportService $reportService,
        private readonly AdminExportService $exportService,
    ) {}

    // =========================================================================
    // AUTH
    // =========================================================================

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required_without:username|nullable|string',
            'username' => 'required_without:email|nullable|string',
            'password' => 'required|string',
        ], [
            'email.required_without'    => 'Please provide an email or username.',
            'username.required_without' => 'Please provide an email or username.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'code'   => 'VALIDATION_FAILED',
                'errors' => $validator->errors(),
            ], 422);
        }

        $identifier = $request->input('username') ?? $request->input('email');
        $result     = $this->authService->authenticate($identifier, $request->password);

        if (!$result) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'INVALID_CREDENTIALS',
                'message' => 'Invalid admin credentials',
            ], 401);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Login successful',
            'admin'   => $result['admin'],
            'tokens'  => $result['tokens'],
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokens = $this->authService->refresh($request->refresh_token);

        if (!$tokens) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'REFRESH_TOKEN_INVALID',
                'message' => 'Invalid or expired refresh token',
            ], 401);
        }

        return response()->json([
            'status' => 'success',
            'tokens' => $tokens,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user()->id);

        return response()->json([
            'status'  => 'success',
            'message' => 'Logged out successfully',
        ]);
    }

    // =========================================================================
    // DASHBOARD — BFF
    // =========================================================================

    public function dashboard(): JsonResponse
    {
        try {
            $data = $this->reportService->getDashboardData();

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Dashboard data failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load dashboard data',
            ], 500);
        }
    }

    public function dashboardStats(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => $this->reportService->getStats(),
        ]);
    }

    public function dashboardGrowth(Request $request): JsonResponse
    {
        $months = (int) $request->get('months', 6);
        $months = max(1, min($months, 12));

        return response()->json([
            'status' => 'success',
            'data'   => $this->reportService->getGrowthChart($months),
        ]);
    }

    public function dashboardCities(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => $this->reportService->getCityDistribution(),
        ]);
    }

    public function dashboardRecent(Request $request): JsonResponse
    {
        $limit = (int) $request->get('limit', 10);
        $limit = max(1, min($limit, 50));

        return response()->json([
            'status' => 'success',
            'data'   => $this->reportService->getRecentActivities($limit),
        ]);
    }

    // =========================================================================
    // WALLET
    // =========================================================================

    public function getAdminWallet(Request $request): JsonResponse
    {
        $adminConfig = $this->authService->getAdminConfigFromRequest($request);
        $wallet      = $this->walletService->getOrCreateWallet($adminConfig);

        return response()->json([
            'status' => 'success',
            'wallet' => [
                'id'            => $wallet->id,
                'wallet_number' => $wallet->wallet_number,
                'phone_number'  => $wallet->phone_number,
                'balance'       => Money::from($wallet->balance)->formatted(),
                'admin_type'    => $adminConfig['type'],
            ],
        ]);
    }

    public function getAdminWallets(): JsonResponse
    {
        return response()->json([
            'status'        => 'success',
            'admin_wallets' => $this->walletService->getAdminWallets(),
            'all_wallets'   => $this->walletService->getAllWallets(),
        ]);
    }

    public function chargeWallet(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|min:10|max:15',
            'amount'       => 'required|numeric|min:1|max:1000000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'code'   => 'VALIDATION_FAILED',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $adminConfig = $this->authService->getAdminConfigFromRequest($request);
            $amount      = Money::from((float) $request->amount);
            $result      = $this->walletService->chargeWallet(
                $request->phone_number,
                $amount,
                $adminConfig
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Wallet charged successfully',
                'wallet'  => [
                    'phone_number'     => $result['wallet']->phone_number,
                    'previous_balance' => $result['previous_balance']->formatted(),
                    'new_balance'      => $result['new_balance']->formatted(),
                ],
                'transaction_id' => $result['transaction']->transaction_id,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'WALLET_NOT_FOUND',
                'message' => 'No wallet found for this phone number',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Admin wallet charge failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to charge wallet',
            ], 500);
        }
    }

    public function showWalletTransactions(int $walletId): JsonResponse
    {
        try {
            $result = $this->walletService->getWalletTransactions($walletId);

            return response()->json([
                'status'       => 'success',
                'wallet'       => $result['wallet'],
                'transactions' => $result['transactions'],
            ]);
        } catch (\Exception) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Wallet not found',
            ], 404);
        }
    }

    public function uploadAdminPhoto(Request $request): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => 'Photo uploaded']);
    }

    // =========================================================================
    // FINANCIAL REPORT  [primary only]
    // =========================================================================

    public function showReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $report = $this->reportService->generateReport(
                $request->input('start_date'),
                $request->input('end_date'),
            );

            return response()->json([
                'status'      => 'success',
                'report_data' => $report,
            ]);
        } catch (\Exception $e) {
            Log::error('Report generation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to generate report',
            ], 500);
        }
    }

    // =========================================================================
    // PDF EXPORT  [primary only]
    // =========================================================================

    /**
     * GET /api/admin/export/pdf
     *
     * Query params (all optional):
     *   start_date  Y-m-d
     *   end_date    Y-m-d   must be >= start_date
     *   sections[]  any of: stats, financial, growth, cities, recent
     *               omit to include ALL sections
     *
     * Returns: application/pdf (inline stream)
     */
    public function exportPdf(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'sections'   => 'nullable|array',
            'sections.*' => 'in:stats,financial,growth,cities,recent',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $pdfBytes = $this->exportService->exportDashboardPdf(
                startDate: $request->input('start_date'),
                endDate:   $request->input('end_date'),
                sections:  $request->input('sections', []),
            );

            $filename = $this->exportService->buildFilename(
                $request->input('start_date'),
                $request->input('end_date'),
            );

            return response($pdfBytes, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"{$filename}\"",
                'Content-Length'      => strlen($pdfBytes),
                'Cache-Control'       => 'no-store, no-cache',
            ]);

        } catch (\Exception $e) {
            Log::error('PDF export failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to generate PDF report',
            ], 500);
        }
    }

    // =========================================================================
    // VERIFICATION  [primary only]
    // =========================================================================

    public function pendingVerifications(): JsonResponse
    {
        $pending = \App\Models\User::with(['photos', 'profile'])
            ->where('verification_status', 'pending')
            ->get()
            ->map(function ($u) {
                $docTypes = $u->photos->pluck('type')->toArray();
                $isDriver = in_array('license', $docTypes) || in_array('mechanic_card', $docTypes);

                return [
                    'user_id'      => $u->id,
                    'name'         => trim("{$u->first_name} {$u->last_name}"),
                    'email'        => $u->email,
                    'type'         => $isDriver ? 'driver' : 'passenger',
                    'documents'    => $u->photos->map(fn($p) => [
                        'type' => $p->type,
                        'url'  => asset("storage/{$p->path}"),
                    ]),
                    'submitted_at' => $u->updated_at->toIso8601String(),
                ];
            });

        return response()->json(['status' => 'success', 'data' => $pending]);
    }

    public function approveVerification(int $userId): JsonResponse
    {
        try {
            $user     = \App\Models\User::with(['photos'])->findOrFail($userId);
            $docTypes = $user->photos->pluck('type')->toArray();
            $isDriver = in_array('license', $docTypes) || in_array('mechanic_card', $docTypes);

            $repo         = app(\App\Interfaces\VerificationRepositoryInterface::class);
            $verifiedUser = $isDriver
                ? $repo->verifyDriver($userId)
                : $repo->verifyPassenger($userId);

            return response()->json([
                'status'  => 'success',
                'message' => ($isDriver ? 'Driver' : 'Passenger') . ' verification approved',
                'user'    => [
                    'id'                  => $verifiedUser->id,
                    'verification_status' => $verifiedUser->verification_status,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function rejectVerification(int $userId): JsonResponse
    {
        $user = \App\Models\User::findOrFail($userId);
        $user->update([
            'verification_status'   => 'rejected',
            'is_verified_passenger' => false,
            'is_verified_driver'    => false,
        ]);

        return response()->json(['status' => 'success', 'message' => 'Verification rejected']);
    }
}
