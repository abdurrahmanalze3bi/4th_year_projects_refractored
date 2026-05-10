<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminTripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * AdminTripController
 *
 * Thin controller — delegates entirely to AdminTripService.
 * Mirrors AdminDashboardController: Validator::make() for input,
 * JsonResponse returns, try/catch around every service call.
 *
 * Routes (all behind auth.admin middleware):
 *   GET  /api/admin/trips                → index()
 *   GET  /api/admin/trips/live           → live()
 *   GET  /api/admin/routes/popular       → popularRoutes()
 *   GET  /api/admin/drivers/top          → topDrivers()
 */
final class AdminTripController extends Controller
{
    public function __construct(
        private readonly AdminTripService $tripService,
    ) {}

    // =========================================================================
    // TRIP LIST
    // =========================================================================

    /**
     * GET /api/admin/trips
     *
     * Query params:
     *   filter   = all | active | scheduled | completed | cancelled  (default: all)
     *   per_page = 1-50   (default: 15)
     *   page     = int    (default: 1)
     *
     * Status semantics:
     *   "scheduled" → DB status = 'active'  AND departure_time >  now()
     *   "active"    → DB status = 'active'  AND departure_time <= now()
     *   "completed" → DB status = 'finished'
     *   "cancelled" → DB status = 'cancelled'
     *   "all"       → all of the above
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'filter'   => 'sometimes|in:all,active,scheduled,completed,cancelled,awaiting', // ← add awaiting
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page'     => 'sometimes|integer|min:1',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $filter  = $request->get('filter', 'all');
        $perPage = (int) $request->get('per_page', 15);
        $page    = (int) $request->get('page', 1);

        try {
            $paginator = $this->tripService->getFilteredTrips($filter, $perPage, $page);

            $data = $paginator->getCollection()
                ->map(fn($ride) => $this->tripService->formatTrip($ride))
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
                'counts' => $this->tripService->getStatusCounts(),
            ]);
        } catch (\Exception $e) {
            Log::error('Admin trip list failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load trips',
            ], 500);
        }
    }

    // =========================================================================
    // LIVE TRIPS
    // =========================================================================

    /**
     * GET /api/admin/trips/live
     *
     * Rides currently on the road:
     *   status = 'active'  AND  departure_time <= now()
     * Returns full route geometry + confirmed passenger list.
     */
    public function live(): JsonResponse
    {
        try {
            $trips = $this->tripService->getLiveTrips();

            return response()->json([
                'status' => 'success',
                'data'   => $trips,
                'total'  => count($trips),
            ]);
        } catch (\Exception $e) {
            Log::error('Admin live trips failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load live trips',
            ], 500);
        }
    }

    // =========================================================================
    // POPULAR ROUTES
    // =========================================================================

    /**
     * GET /api/admin/routes/popular
     *
     * Routes sorted descending by trip count.
     * "Damascus → Homs (5 trips)" ranks above "Homs → Aleppo (4 trips)".
     *
     * Query params:
     *   limit = 1-50  (default: 10)
     */
    public function popularRoutes(Request $request): JsonResponse
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
            return response()->json([
                'status' => 'success',
                'data'   => $this->tripService->getPopularRoutes((int) $request->get('limit', 10)),
            ]);
        } catch (\Exception $e) {
            Log::error('Popular routes failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load popular routes',
            ], 500);
        }
    }

    // =========================================================================
    // TOP DRIVERS
    // =========================================================================

    /**
     * GET /api/admin/drivers/top
     *
     * Verified drivers sorted by average rating (highest first).
     * Drivers with no ratings yet appear last.
     *
     * Query params:
     *   limit = 1-50  (default: 10)
     */
    public function topDrivers(Request $request): JsonResponse
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
            return response()->json([
                'status' => 'success',
                'data'   => $this->tripService->getTopDrivers((int) $request->get('limit', 10)),
            ]);
        } catch (\Exception $e) {
            Log::error('Top drivers failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load top drivers',
            ], 500);
        }
    }
}
