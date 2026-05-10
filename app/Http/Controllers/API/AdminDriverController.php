<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminDriverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * AdminDriverController
 *
 * All endpoints are protected by the  auth.admin  middleware.
 * Primary-only routes are further protected by  auth.admin:primary.
 *
 * ── Routes ──────────────────────────────────────────────────────────────────
 *
 *  GET  /api/admin/drivers/dashboard          → full BFF payload
 *
 *  GET  /api/admin/drivers/stats              → stats cards only
 *  GET  /api/admin/drivers                    → driver table (paginated + filtered)
 *  GET  /api/admin/drivers/activity           → recent activity feed
 *
 *  GET  /api/admin/drivers/{driverId}/profile → single driver detail
 */
final class AdminDriverController extends Controller
{
    public function __construct(
        private readonly AdminDriverService $driverService,
    ) {}

    // =========================================================================
    // BFF  –  full dashboard
    // =========================================================================

    /**
     * GET /api/admin/drivers/dashboard
     *
     * Returns every widget the driver management page needs in one call:
     *   - admin_photo
     *   - stats  (total, active, pending, suspended, avg_rating)
     *   - recent_activity
     *
     * The driver table is intentionally excluded from the BFF because it is
     * paginated; the frontend fetches it separately via GET /api/admin/drivers.
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $data = $this->driverService->getDashboardData(
                $request->user()?->id
            );

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Driver dashboard failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load driver dashboard',
            ], 500);
        }
    }

    // =========================================================================
    // STATS CARDS
    // =========================================================================

    /**
     * GET /api/admin/drivers/stats
     *
     * Returns the four stat cards independently so the frontend can refresh
     * just the numbers without reloading the whole page.
     */
    public function stats(): JsonResponse
    {
        try {
            return response()->json([
                'status' => 'success',
                'data'   => $this->driverService->getStats(),
            ]);
        } catch (\Exception $e) {
            Log::error('Driver stats failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load driver stats',
            ], 500);
        }
    }

    // =========================================================================
    // DRIVER TABLE
    // =========================================================================

    /**
     * GET /api/admin/drivers
     *
     * Query params:
     *   filter   = all | verified | pending | suspended   (default: all)
     *   per_page = 1-50                                   (default: 10)
     *   page     = int                                    (default: 1)
     *   search   = string  (optional – matches name or email)
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'filter'   => 'sometimes|in:all,verified,pending,suspended',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page'     => 'sometimes|integer|min:1',
            'search'   => 'sometimes|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $filter  = $request->get('filter', 'all');
        $perPage = (int) $request->get('per_page', 10);
        $page    = (int) $request->get('page', 1);
        $search  = $request->get('search');

        try {
            $paginator = $this->driverService->getDrivers($filter, $perPage, $page, $search);

            $data = $paginator->getCollection()
                ->map(fn($driver) => $this->driverService->formatDriver($driver))
                ->values();

            return response()->json([
                'status' => 'success',
                'data'   => $data,
                'meta'   => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'filter'       => $filter,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Driver index failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load drivers',
            ], 500);
        }
    }

    // =========================================================================
    // RECENT ACTIVITY
    // =========================================================================

    /**
     * GET /api/admin/drivers/activity
     *
     * Query params:
     *   limit = 1-50   (default: 10)
     */
    public function activity(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'sometimes|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $limit = (int) $request->get('limit', 10);

            return response()->json([
                'status' => 'success',
                'data'   => $this->driverService->getRecentActivity($limit),
            ]);
        } catch (\Exception $e) {
            Log::error('Driver activity feed failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load activity feed',
            ], 500);
        }
    }

    // =========================================================================
    // DRIVER PROFILE  (detail / modal view)
    // =========================================================================

    /**
     * GET /api/admin/drivers/{driverId}/profile
     *
     * Returns the full profile of a single driver including:
     *   - personal info, vehicle details, documents, rating, recent rides
     */
    public function driverProfile(int $driverId): JsonResponse
    {
        try {
            $profile = $this->driverService->getDriverProfile($driverId);

            return response()->json([
                'status' => 'success',
                'data'   => $profile,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Driver not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Driver profile failed', [
                'driver_id' => $driverId,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load driver profile',
            ], 500);
        }
    }
    public function verificationEfficiency(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period' => 'sometimes|in:day,week,month',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $period = $request->get('period', 'week');

            return response()->json([
                'status' => 'success',
                'data'   => $this->driverService->getVerificationEfficiency($period),
            ]);
        } catch (\Exception $e) {
            Log::error('Verification efficiency failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load verification efficiency',
            ], 500);
        }
    }
    private function resolvePeriodBounds(string $period): array
    {
        $now = \Carbon\Carbon::now();

        switch ($period) {
            case 'day':
                $currentStart  = $now->copy()->startOfDay();
                $currentEnd    = $now->copy()->endOfDay();
                $previousStart = $now->copy()->subDay()->startOfDay();
                $previousEnd   = $now->copy()->subDay()->endOfDay();
                $label         = 'day';
                $prevLabel     = 'day';
                break;

            case 'month':
                $currentStart  = $now->copy()->startOfMonth();
                $currentEnd    = $now->copy()->endOfMonth();
                $previousStart = $now->copy()->subMonth()->startOfMonth();
                $previousEnd   = $now->copy()->subMonth()->endOfMonth();
                $label         = 'month';
                $prevLabel     = 'month';
                break;

            case 'week':
            default:
                $currentStart  = $now->copy()->startOfWeek();
                $currentEnd    = $now->copy()->endOfWeek();
                $previousStart = $now->copy()->subWeek()->startOfWeek();
                $previousEnd   = $now->copy()->subWeek()->endOfWeek();
                $label         = 'week';
                $prevLabel     = 'week';
                break;
        }

        return [$currentStart, $currentEnd, $previousStart, $previousEnd, $label, $prevLabel];
    }
    private function countProcessedVerifications(
        \Carbon\Carbon $start,
        \Carbon\Carbon $end
    ): int {
        return \App\Models\User::whereIn('verification_status', ['approved', 'rejected'])
            ->whereHas('photos', fn($p) => $p->whereIn('type', ['license', 'mechanic_card']))
            ->whereBetween('updated_at', [$start, $end])
            ->count();
    }
    private function countIncomingVerifications(
        \Carbon\Carbon $start,
        \Carbon\Carbon $end
    ): int {
        return \App\Models\User::whereHas('photos', function ($q) use ($start, $end) {
            $q->whereIn('type', ['license', 'mechanic_card'])
                ->whereBetween('created_at', [$start, $end]);
        })
            ->count();
    }
}
