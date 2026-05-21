<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Complaint;
use App\Models\Profile;
use App\Models\Ride;
use App\Models\User;
use App\Models\UserRating;
use App\Models\WalletTransaction;
use App\Services\JwtService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * PassengerProfileController
 *
 * Provides the admin-facing passenger profile dashboard.
 *
 * ── Routes (all behind auth.admin middleware) ──────────────────────────────
 *
 *  BFF (single call, returns full page):
 *    GET  /api/admin/passengers/{userId}/full-profile   → fullProfile()
 *
 *  Individual (for partial refreshes):
 *    GET  /api/admin/passengers/{userId}/stats          → stats()
 *    GET  /api/admin/passengers/{userId}/monthly-trips  → monthlyTrips()
 *    GET  /api/admin/passengers/{userId}/recent-trips   → recentTrips()
 *    GET  /api/admin/passengers/{userId}/complaints     → complaints()
 *    GET  /api/admin/passengers/{userId}/wallet-charges → walletCharges()
 *
 *  Actions:
 *    POST /api/admin/passengers/{userId}/charge-wallet  → chargeWallet()
 *    POST /api/admin/users/{userId}/ban                 → ban()   (AdminBanController)
 *    POST /api/admin/users/{userId}/unban               → unban() (AdminBanController)
 */
final class PassengerProfileController extends Controller
{
    public function __construct(
        private readonly JwtService           $jwtService,
        private readonly NotificationService  $notificationService,
    ) {}

    // =========================================================================
    // BFF — full page in one call
    // =========================================================================

    /**
     * GET /api/admin/passengers/{userId}/full-profile
     *
     * Returns everything the passenger profile dashboard page needs.
     */
    public function fullProfile(int $userId): JsonResponse
    {
        try {
            $user = User::with(['profile', 'wallet', 'photos'])->findOrFail($userId);

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'user'          => $this->formatUser($user),
                    'stats'         => $this->buildStats($user),
                    'monthly_trips' => $this->buildMonthlyTrips($userId),
                    'recent_trips'  => $this->buildRecentTrips($userId),
                    'complaints'    => $this->buildComplaints($userId),
                    'wallet_charges'=> $this->buildWalletCharges($userId),
                ],
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        } catch (\Exception $e) {
            Log::error('PassengerProfile: fullProfile failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return $this->serverError();
        }
    }

    // =========================================================================
    // INDIVIDUAL REFRESH ENDPOINTS
    // =========================================================================

    /**
     * GET /api/admin/passengers/{userId}/stats
     */
    public function stats(int $userId): JsonResponse
    {
        try {
            $user = User::with('wallet')->findOrFail($userId);
            return response()->json(['status' => 'success', 'data' => $this->buildStats($user)]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        }
    }

    /**
     * GET /api/admin/passengers/{userId}/monthly-trips
     *
     * Query params:
     *   months = 1-12 (default 6)
     */
    public function monthlyTrips(int $userId, Request $request): JsonResponse
    {
        $months = min(12, max(1, (int) $request->get('months', 6)));
        return response()->json([
            'status' => 'success',
            'data'   => $this->buildMonthlyTrips($userId, $months),
        ]);
    }

    /**
     * GET /api/admin/passengers/{userId}/recent-trips
     *
     * Query params:
     *   limit = 1-50 (default 10)
     */
    public function recentTrips(int $userId, Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->get('limit', 10)));
        return response()->json([
            'status' => 'success',
            'data'   => $this->buildRecentTrips($userId, $limit),
        ]);
    }

    /**
     * GET /api/admin/passengers/{userId}/complaints
     *
     * Query params:
     *   status = all | pending | in_review | resolved | closed | escalated
     *   type   = all | trip_safety | driver_behavior | ... (see ComplaintType enum)
     *   per_page = 1-50 (default 15)
     *   page     = int
     */
    public function complaints(int $userId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status'   => 'sometimes|in:all,pending,in_review,resolved,closed,escalated',
            'type'     => 'sometimes|in:all,trip_safety,driver_behavior,passenger_behavior,ride_cancellation,financial_issue,account_issue,technical_issue,other',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page'     => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $query = Complaint::with(['assignedAgent:id,first_name,last_name', 'attachments'])
            ->where('user_id', $userId);

        $status = $request->get('status', 'all');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $type = $request->get('type', 'all');
        if ($type !== 'all') {
            $query->where('type', $type);
        }

        $paginator = $query
            ->orderByDesc('created_at')
            ->paginate(
                (int) $request->get('per_page', 15),
                ['*'],
                'page',
                (int) $request->get('page', 1)
            );

        return response()->json([
            'status' => 'success',
            'data'   => $paginator->getCollection()
                ->map(fn($c) => $this->formatComplaint($c))
                ->values(),
            'meta'   => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            // Tab badge counts for this user only
            'counts' => $this->complaintCounts($userId),
        ]);
    }

    /**
     * GET /api/admin/passengers/{userId}/wallet-charges
     *
     * Returns wallet top-up transactions for this user (admin charges only).
     *
     * Query params:
     *   per_page = 1-50 (default 15)
     *   page     = int
     */
    public function walletCharges(int $userId, Request $request): JsonResponse
    {
        $user = User::with('wallet')->find($userId);
        if (!$user || !$user->wallet) {
            return response()->json(['status' => 'success', 'data' => [], 'meta' => []]);
        }

        $paginator = \App\Models\WalletTransaction::with([
            'user:id,first_name,last_name',   // the admin who processed it
        ])
            ->where('wallet_id', $user->wallet->id)
            ->where('type', 'admin_charge')
            ->orderByDesc('created_at')
            ->paginate(
                (int) $request->get('per_page', 15),
                ['*'],
                'page',
                (int) $request->get('page', 1)
            );

        return response()->json([
            'status' => 'success',
            'data'   => $paginator->getCollection()
                ->map(fn($tx) => $this->formatWalletCharge($tx))
                ->values(),
            'meta'   => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    // =========================================================================
    // ACTIONS
    // =========================================================================

    /**
     * POST /api/admin/passengers/{userId}/charge-wallet
     *
     * Body:
     *   amount      numeric  required, min 1
     *   admin_notes string   optional
     */
    public function chargeWallet(int $userId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount'      => 'required|numeric|min:1|max:10000000',
            'admin_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $user = User::with('wallet')->findOrFail($userId);

            if (!$user->wallet) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'This user does not have a wallet yet.',
                ], 422);
            }

            $amount = (float) $request->input('amount');

            DB::transaction(function () use ($user, $amount, $request) {
                $wallet = \App\Models\Wallet::lockForUpdate()->findOrFail($user->wallet->id);

                $previousBalance = (float) $wallet->balance;
                $newBalance      = $previousBalance + $amount;
                $wallet->balance = $newBalance;
                $wallet->save();

                \App\Models\WalletTransaction::create([
                    'wallet_id'        => $wallet->id,
                    'user_id'          => $request->user()?->id,  // the admin
                    'type'             => 'admin_charge',
                    'amount'           => $amount,
                    'previous_balance' => $previousBalance,
                    'new_balance'      => $newBalance,
                    'description'      => $request->input('admin_notes') ?? 'Admin wallet charge',
                    'transaction_id'   => 'ADM-' . $user->id . '-' . now()->timestamp,
                    'status'           => 'completed',
                    'reference'        => 'admin_charge:' . $user->id,
                ]);

                Log::info('Admin charged passenger wallet', [
                    'passenger_id'     => $user->id,
                    'admin_id'         => $request->user()?->id,
                    'amount'           => $amount,
                    'previous_balance' => $previousBalance,
                    'new_balance'      => $newBalance,
                ]);
            });

            // Notify the user
            try {
                $this->notificationService->createNotification(
                    $user,
                    'wallet_charged',
                    'تم شحن محفظتك',
                    "تم إضافة {$amount} ر.س إلى محفظتك بواسطة الإدارة." .
                    ($request->input('admin_notes') ? ' ملاحظة: ' . $request->input('admin_notes') : ''),
                    ['amount' => $amount],
                    'normal',
                    'system'
                );
            } catch (\Throwable) {
                // Never let notification failure block the charge
            }

            $user->wallet->refresh();

            return response()->json([
                'status'      => 'success',
                'message'     => "Wallet charged successfully. New balance: {$user->wallet->balance} SYP.",
                'new_balance' => (float) $user->wallet->balance,
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        } catch (\Exception $e) {
            Log::error('PassengerProfile: chargeWallet failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return $this->serverError();
        }
    }

    // =========================================================================
    // PRIVATE BUILDERS
    // =========================================================================

    private function buildStats(User $user): array
    {
        $userId = $user->id;

        $totalRides = Booking::where('user_id', $userId)
            ->whereIn('status', ['completed'])
            ->count();

        $totalSpending = Booking::join('rides', 'bookings.ride_id', '=', 'rides.id')
            ->where('bookings.user_id', $userId)
            ->where('bookings.status', 'completed')
            ->selectRaw('SUM(bookings.seats * rides.price_per_seat) as total')
            ->value('total') ?? 0.0;

        $avgRating = UserRating::where('rated_user_id', $userId)->avg('rating') ?? 0.0;

        $walletBalance = $user->wallet?->balance ?? 0.0;

        return [
            'total_rides'    => $totalRides,
            'total_spending' => round((float) $totalSpending, 2),
            'avg_rating'     => round((float) $avgRating, 1),
            'wallet_balance' => round((float) $walletBalance, 2),
        ];
    }

    /**
     * Monthly trip counts + total cost (seats × price_per_seat) for each month.
     */
    private function buildMonthlyTrips(int $userId, int $months = 6): array
    {
        $rows = Booking::join('rides', 'bookings.ride_id', '=', 'rides.id')
            ->where('bookings.user_id', $userId)
            ->where('bookings.status', 'completed')
            ->where('rides.departure_time', '>=', now()->subMonths($months)->startOfMonth())
            ->selectRaw(
                "DATE_FORMAT(rides.departure_time, '%Y-%m') as month_key,
                 COUNT(*) as trips,
                 SUM(bookings.seats * rides.price_per_seat) as total_cost"
            )
            ->groupBy('month_key')
            ->orderBy('month_key')
            ->get()
            ->keyBy('month_key');

        $result = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $date     = now()->subMonths($i)->startOfMonth();
            $key      = $date->format('Y-m');
            $row      = $rows->get($key);
            $result[] = [
                'month'      => $date->locale('ar')->isoFormat('MMMM'),
                'month_key'  => $key,
                'trips'      => $row ? (int) $row->trips : 0,
                'total_cost' => $row ? round((float) $row->total_cost, 2) : 0.0,
            ];
        }
        return $result;
    }

    /**
     * Recent bookings with correct total cost = seats × price_per_seat.
     */
    private function buildRecentTrips(int $userId, int $limit = 10): array
    {
        return Booking::with([
            'ride:id,pickup_address,destination_address,departure_time,price_per_seat,driver_id',
            'ride.driver:id,first_name,last_name',
        ])
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn($b) => [
                'id'             => $b->id,
                'date'           => $b->created_at->toDateString(),
                'route_from'     => $b->ride?->pickup_address,
                'route_to'       => $b->ride?->destination_address,
                'driver'         => $b->ride?->driver
                    ? trim("{$b->ride->driver->first_name} {$b->ride->driver->last_name}")
                    : null,
                'seats'          => $b->seats,
                'price_per_seat' => (float) ($b->ride?->price_per_seat ?? 0),
                // ✅ Total = seats × price_per_seat (not just price_per_seat)
                'total_cost'     => round($b->seats * (float) ($b->ride?->price_per_seat ?? 0), 2),
                'status'         => $b->status,
                'departure_time' => $b->ride?->departure_time?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Complaints for this user.
     * No ride_id exposed — only complaint ID, type, and status.
     */
    private function buildComplaints(int $userId, string $status = 'all', string $type = 'all'): array
    {
        $query = Complaint::with(['assignedAgent:id,first_name,last_name'])
            ->where('user_id', $userId);

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($type !== 'all') {
            $query->where('type', $type);
        }

        return $query
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($c) => $this->formatComplaint($c))
            ->values()
            ->all();
    }

    /**
     * Wallet top-up transactions (admin_charge type only).
     * Returns who processed the charge (name + photo).
     * No payment method — it's always an admin action.
     */
    private function buildWalletCharges(int $userId): array
    {
        $user = User::with('wallet')->find($userId);
        if (!$user?->wallet) return [];

        return \App\Models\WalletTransaction::with([
            'user:id,first_name,last_name',  // admin who did the charge
        ])
            ->where('wallet_id', $user->wallet->id)
            ->where('type', 'admin_charge')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($tx) => $this->formatWalletCharge($tx))
            ->values()
            ->all();
    }

    // =========================================================================
    // FORMATTERS
    // =========================================================================

    private function formatUser(User $user): array
    {
        $profile = $user->profile;
        return [
            'id'                    => $user->id,
            'full_name'             => trim("{$user->first_name} {$user->last_name}"),
            'email'                 => $user->email,
            'phone'                 => $profile?->phone ?? null,
            'address'               => $user->address,
            'gender'                => $user->gender,
            'joined_at'             => $user->created_at->toIso8601String(),
            'profile_photo'         => $profile?->profile_photo
                ? asset("storage/{$profile->profile_photo}") : null,
            'verification_status'   => $user->verification_status,
            'is_verified_passenger' => (bool) $user->is_verified_passenger,
            'account_status'        => $user->account_status,  // uses accessor
            'ban' => $user->status == -1 ? [
                'reason'     => $user->ban_reason,
                'type'       => $user->ban_type,
                'expires_at' => $user->ban_expires_at?->toIso8601String(),
                'banned_at'  => $user->banned_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function formatComplaint(\App\Models\Complaint $c): array
    {
        // Type label map (mirrors ComplaintType enum labels)
        $typeLabels = [
            'trip_safety'        => 'أمان الرحلة',
            'driver_behavior'    => 'سلوك السائق',
            'passenger_behavior' => 'سلوك الراكب',
            'ride_cancellation'  => 'إلغاء الرحلة',
            'financial_issue'    => 'مشكلة مالية',
            'account_issue'      => 'مشكلة في الحساب',
            'technical_issue'    => 'عطل تقني',
            'other'              => 'أخرى',
        ];

        $typeValue = $c->type instanceof \App\Enums\ComplaintType
            ? $c->type->value : (string) $c->type;

        $statusValue = $c->status instanceof \App\Enums\ComplaintStatus
            ? $c->status->value : (string) $c->status;

        return [
            'id'         => $c->id,
            'type'       => $typeValue,
            'type_label' => $typeLabels[$typeValue] ?? $typeValue,
            'status'     => $statusValue,
            'created_at' => $c->created_at->toDateString(),
            'assigned_to'=> $c->assignedAgent
                ? trim("{$c->assignedAgent->first_name} {$c->assignedAgent->last_name}")
                : null,
        ];
    }

    private function formatWalletCharge(\App\Models\WalletTransaction $tx): array
    {
        $admin = $tx->user;  // the admin user who performed the charge
        $adminProfile = $admin
            ? \App\Models\Profile::where('user_id', $admin->id)->first()
            : null;

        return [
            'id'                   => $tx->id,
            'transaction_id'       => $tx->transaction_id,
            'amount'               => (float) $tx->amount,
            'previous_balance'     => (float) $tx->previous_balance,
            'new_balance'          => (float) $tx->new_balance,
            'status'               => $tx->status,
            'notes'                => $tx->description,
            'date'                 => $tx->created_at->toDateString(),
            'created_at'           => $tx->created_at->toIso8601String(),
            // Who processed the charge (admin info)
            'processed_by_id'      => $admin?->id,
            'processed_by_name'    => $admin
                ? trim("{$admin->first_name} {$admin->last_name}") : null,
            'processed_by_photo'   => $adminProfile?->profile_photo
                ? asset("storage/{$adminProfile->profile_photo}") : null,
        ];
    }

    private function complaintCounts(int $userId): array
    {
        $rows = Complaint::where('user_id', $userId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return [
            'all'       => array_sum($rows),
            'pending'   => $rows['pending']   ?? 0,
            'in_review' => $rows['in_review'] ?? 0,
            'escalated' => $rows['escalated'] ?? 0,
            'resolved'  => $rows['resolved']  ?? 0,
            'closed'    => $rows['closed']    ?? 0,
        ];
    }

    // =========================================================================
    // SHARED
    // =========================================================================

    private function serverError(): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => 'An unexpected error occurred. Please try again.',
        ], 500);
    }}
