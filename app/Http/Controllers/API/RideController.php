<?php

namespace App\Http\Controllers\API;

use App\DTOs\Ride\BookRideDTO;
use App\DTOs\Ride\CreateRideDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookRideRequest;
use App\Http\Requests\CreateRideRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\RideResource;
use App\Models\Booking;
use App\Models\Ride;
use App\Services\Geocoding\GeocodingService;
use App\Services\Geocoding\RouteCalculationService;
use App\Services\NotificationService;
use App\Services\Ride\BookingService;
use App\Services\Ride\RideService;
use App\Services\Score\ScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RideController
 *
 * Organised in four sections:
 *   1. Ride creation & route tooling
 *   2. Ride retrieval & search
 *   3. Booking actions  (book / accept / reject / cancel / confirm / no-show)
 *   4. Ride lifecycle   (cancel / finish / driver-confirm / driver-no-show)
 *
 * REMOVED DUPLICATES vs the old file:
 *   getRides()      → index()
 *   getRideDetails()→ show()
 *   cancel()        → cancelRide()
 *   finish()        → finishRide()
 *   searchRides()   → search()
 *
 * All catch blocks use \Throwable (catches PHP Errors as well as Exceptions).
 * All notifications are fired through notify() and never block the response.
 */
class RideController extends Controller
{
    public function __construct(
        private readonly RideService             $rideService,
        private readonly BookingService          $bookingService,
        private readonly GeocodingService        $geocodingService,
        private readonly RouteCalculationService $routeService,
        private readonly NotificationService     $notificationService,
        private readonly ScoreService            $scoreService,
    ) {}

    // =========================================================================
    // 1. RIDE CREATION & ROUTE TOOLING
    // =========================================================================

    /**
     * POST /api/rides
     *
     * Minimal ride creation — coordinates + price + seats.
     * Route geometry is calculated automatically by the service if not supplied.
     */
    public function create(CreateRideRequest $request): JsonResponse
    {
        try {
            $dto  = CreateRideDTO::fromRequest($request->validated(), $request->user()->id);
            $ride = $this->rideService->createRide($dto, $request->user());

            return response()->json([
                'success'      => true,
                'message'      => 'Ride created successfully',
                'data'         => new RideResource($ride),
                'driver_score' => ScoreController::formatScore(
                    $this->scoreService->getScore($request->user())
                ),
            ], 201);

        } catch (\Throwable $e) {
            Log::error('Ride creation failed', [
                'user_id' => $request->user()->id,
                'error'   => $e->getMessage(),
                'class'   => get_class($e),
            ]);
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/rides/route-options
     *
     * Returns up to 3 alternative routes for the driver to choose before
     * creating a ride. CACHED 30 min per coordinate pair.
     */
    public function getRouteOptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pickup_lat'      => 'required|numeric|between:-90,90',
            'pickup_lng'      => 'required|numeric|between:-180,180',
            'destination_lat' => 'required|numeric|between:-90,90',
            'destination_lng' => 'required|numeric|between:-180,180',
        ]);

        try {
            $origin = [
                'lat' => (float) $validated['pickup_lat'],
                'lng' => (float) $validated['pickup_lng'],
            ];
            $destination = [
                'lat' => (float) $validated['destination_lat'],
                'lng' => (float) $validated['destination_lng'],
            ];

            $cacheKey = sprintf(
                'route.options.%s.%s.%s.%s',
                round($origin['lat'],      4), round($origin['lng'],      4),
                round($destination['lat'], 4), round($destination['lng'], 4)
            );

            $routes = Cache::remember($cacheKey, 1800, fn () =>
            $this->routeService->getRouteAlternatives($origin, $destination, 3)
            );

            return response()->json([
                'success' => true,
                'data'    => [
                    'routes'      => $routes,
                    'pickup'      => $origin,
                    'destination' => $destination,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Route options failed', ['error' => $e->getMessage()]);
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/rides/create-with-route
     *
     * Creates a ride with a pre-selected route from getRouteOptions().
     * Reverse-geocodes addresses and fills geometry automatically when omitted.
     */
    public function createRideWithRoute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pickup_lat'           => 'required|numeric|between:-90,90',
            'pickup_lng'           => 'required|numeric|between:-180,180',
            'destination_lat'      => 'required|numeric|between:-90,90',
            'destination_lng'      => 'required|numeric|between:-180,180',
            'pickup_address'       => 'nullable|string|max:500',
            'destination_address'  => 'nullable|string|max:500',
            'departure_time'       => 'required|date|after:' . now()->addMinutes(5)->toDateTimeString(),
            'available_seats'      => 'required|integer|min:1|max:8',
            'price_per_seat'       => 'required|numeric|min:100|max:100000',
            'payment_method'       => 'required|in:cash,e-pay',
            'booking_type'         => 'required|in:direct,request',
            'vehicle_type'         => 'required|string|max:100',
            'communication_number' => 'required|string',
            'notes'                => 'nullable|string|max:1000',
            'route_geometry'       => 'nullable|array',
            'route_geometry.type'  => 'nullable|string|in:LineString',
            'route_geometry.coordinates' => 'nullable|array|min:2',
            'route_index'          => 'nullable|integer|min:0',
            'distance'             => 'nullable|numeric|min:0',
            'duration'             => 'nullable|numeric|min:0',
        ]);

        try {
            // Auto reverse-geocode if addresses not supplied
            $validated['pickup_address'] ??= $this->geocodingService->reverseGeocode(
                $validated['pickup_lat'], $validated['pickup_lng']
            );
            $validated['destination_address'] ??= $this->geocodingService->reverseGeocode(
                $validated['destination_lat'], $validated['destination_lng']
            );

            // Auto-fill route when driver did not pass one
            if (empty($validated['route_geometry']) || empty($validated['distance'])) {
                $route = $this->routeService->getRouteDetails(
                    ['lat' => $validated['pickup_lat'],      'lng' => $validated['pickup_lng']],
                    ['lat' => $validated['destination_lat'], 'lng' => $validated['destination_lng']]
                );
                $validated['distance']       ??= $route['distance'];
                $validated['duration']       ??= $route['duration'];
                $validated['route_geometry'] ??= [
                    'type'        => 'LineString',
                    'coordinates' => $route['geometry'],
                ];
            }

            $dto  = CreateRideDTO::fromRequest($validated, $request->user()->id);
            $ride = $this->rideService->createRide($dto, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Ride created successfully',
                'data'    => new RideResource($ride),
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('Create-with-route failed', [
                'user_id' => $request->user()->id,
                'error'   => $e->getMessage(),
                'class'   => get_class($e),
            ]);
            return $this->error($e->getMessage());
        }
    }

    // =========================================================================
    // 2. RIDE RETRIEVAL & SEARCH
    // =========================================================================

    /**
     * GET /api/rides
     * The authenticated driver's own published rides.
     */
    public function index(Request $request): JsonResponse
    {
        $rides = $this->rideService->getUserRides($request->user()->id);

        $rides->load(['driver', 'driver.profile'])
            ->loadCount(['bookings as total_booked_seats' => fn ($q) =>
            $q->select(DB::raw('COALESCE(SUM(seats), 0)'))
            ]);

        return response()->json([
            'success' => true,
            'data'    => RideResource::collection($rides),
        ]);
    }

    /**
     * GET /api/rides/{rideId}
     *
     * Full ride snapshot:
     *   • All ride fields (status, seats, price, route geometry, …)
     *   • Driver profile + vehicle details
     *   • bookings_summary  — counts by status
     *   • bookings[]        — every booking with passenger name, photo, flags
     *
     * NOT cached — available_seats and booking statuses change constantly.
     */
    public function show(int $rideId): JsonResponse
    {
        try {
            $ride = Ride::with([
                // Driver + vehicle
                'driver:id,first_name,last_name,email,gender,address,is_verified_driver,verification_status',
                'driver.profile:user_id,profile_photo,type_of_car,color_of_car,number_of_seats,car_pic,radio,smoking',
                // All bookings, oldest first
                'bookings'             => fn ($q) => $q->orderBy('created_at'),
                'bookings.user:id,first_name,last_name,email,gender,is_verified_passenger',
                'bookings.user.profile:user_id,profile_photo',
            ])->findOrFail($rideId);

            $driver        = $ride->driver;
            $driverProfile = $driver?->profile;

            // Compute active seat count from the already-loaded collection (no extra query).
            $totalBookedSeats = (int) $ride->bookings
                ->whereIn('status', ['confirmed', 'pending'])
                ->sum('seats');

            // Format each booking with its passenger snapshot.
            $bookings = $ride->bookings->map(fn (Booking $b) => [
                'booking_id'           => $b->id,
                'seats'                => $b->seats,
                'status'               => $b->status,
                'communication_number' => $b->communication_number,
                'booked_at'            => $b->created_at->toIso8601String(),
                'completed_at'         => $b->completed_at?->toIso8601String(),
                'passenger' => $b->user ? [
                    'id'                    => $b->user->id,
                    'full_name'             => trim("{$b->user->first_name} {$b->user->last_name}"),
                    'email'                 => $b->user->email,
                    'gender'                => $b->user->gender,
                    'is_verified_passenger' => (bool) $b->user->is_verified_passenger,
                    'profile_photo'         => $b->user->profile?->profile_photo
                        ? asset('storage/' . $b->user->profile->profile_photo)
                        : null,
                ] : null,
            ])->values();

            return response()->json([
                'success' => true,
                'data'    => [
                    // ── Core ride ────────────────────────────────────────────
                    'id'                   => $ride->id,
                    'status'               => $ride->status,
                    'payment_method'       => $ride->payment_method,
                    'booking_type'         => $ride->booking_type,
                    'vehicle_type'         => $ride->vehicle_type,
                    'pickup_address'       => $ride->pickup_address,
                    'destination_address'  => $ride->destination_address,
                    'departure_time'       => $ride->departure_time->toIso8601String(),
                    'price_per_seat'       => (float) $ride->price_per_seat,
                    'available_seats'      => $ride->available_seats,
                    'total_booked_seats'   => $totalBookedSeats,
                    'notes'                => $ride->notes,
                    'communication_number' => $ride->communication_number,
                    'distance'             => $ride->distance,     // metres
                    'duration'             => $ride->duration,     // seconds
                    'route_geometry'       => $ride->route_geometry, // GeoJSON LineString
                    'created_at'           => $ride->created_at->toIso8601String(),

                    // ── Driver ───────────────────────────────────────────────
                    'driver' => $driver ? [
                        'id'                 => $driver->id,
                        'full_name'          => trim("{$driver->first_name} {$driver->last_name}"),
                        'email'              => $driver->email,
                        'gender'             => $driver->gender,
                        'address'            => $driver->address,
                        'is_verified_driver' => (bool) $driver->is_verified_driver,
                        'profile_photo'      => $driverProfile?->profile_photo
                            ? asset('storage/' . $driverProfile->profile_photo)
                            : null,
                        'vehicle' => [
                            'type'    => $driverProfile?->type_of_car,
                            'color'   => $driverProfile?->color_of_car,
                            'seats'   => $driverProfile?->number_of_seats,
                            'car_pic' => $driverProfile?->car_pic
                                ? asset('storage/' . $driverProfile->car_pic)
                                : null,
                            'radio'   => (bool) $driverProfile?->radio,
                            'smoking' => (bool) $driverProfile?->smoking,
                        ],
                    ] : null,

                    // ── Booking counts (useful for UI badge / tabs) ───────────
                    'bookings_summary' => [
                        'total'     => $ride->bookings->count(),
                        'confirmed' => $ride->bookings->where('status', 'confirmed')->count(),
                        'pending'   => $ride->bookings->where('status', 'pending')->count(),
                        'cancelled' => $ride->bookings->where('status', 'cancelled')->count(),
                        'completed' => $ride->bookings->where('status', 'completed')->count(),
                        'no_show'   => $ride->bookings->where('status', 'no_show')->count(),
                    ],

                    // ── Full booking list with passengers ─────────────────────
                    'bookings' => $bookings,
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('Ride not found', 404);
        } catch (\Throwable $e) {
            Log::error('Ride show failed', ['ride_id' => $rideId, 'error' => $e->getMessage()]);
            return $this->error($e->getMessage());
        }
    }

    /**
     * GET /api/rides/search  |  POST /api/rides/search
     * Search rides by source/destination + date + seats.
     * Accepts both address strings (geocoded) and raw lat/lng.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_address'      => 'required_without_all:source_lat,source_lng|string|max:255',
            'source_lat'          => 'required_with:source_lng|numeric',
            'source_lng'          => 'required_with:source_lat|numeric',
            'destination_address' => 'required_without_all:dest_lat,dest_lng|string|max:255',
            'dest_lat'            => 'required_with:dest_lng|numeric',
            'dest_lng'            => 'required_with:dest_lat|numeric',
            'departure_date'      => 'required|date|after:yesterday',
            'seats_required'      => 'required|integer|min:1',
        ]);

        try {
            $source = $request->filled('source_address')
                ? $this->geocodingService->geocodeAddress($validated['source_address'])
                : ['lat' => (float) $validated['source_lat'], 'lng' => (float) $validated['source_lng']];

            $destination = $request->filled('destination_address')
                ? $this->geocodingService->geocodeAddress($validated['destination_address'])
                : ['lat' => (float) $validated['dest_lat'], 'lng' => (float) $validated['dest_lng']];

            $rides = $this->rideService->searchRides([
                'departure_date' => $validated['departure_date'],
                'seats_required' => $validated['seats_required'],
                'source_lat'     => $source['lat'],
                'source_lng'     => $source['lng'],
                'dest_lat'       => $destination['lat'],
                'dest_lng'       => $destination['lng'],
            ]);

            $rides->loadCount(['bookings as total_booked_seats' => fn ($q) =>
            $q->select(DB::raw('COALESCE(SUM(seats), 0)'))
            ]);

            return response()->json([
                'success' => true,
                'count'   => $rides->count(),
                'data'    => RideResource::collection($rides),
            ]);

        } catch (\Throwable $e) {
            Log::error('Ride search failed', ['error' => $e->getMessage()]);
            return $this->error($e->getMessage());
        }
    }

    /**
     * GET /api/autocomplete
     * Location autocomplete via geocoding service. CACHED 1 hour per term.
     */
    public function autocomplete(Request $request): JsonResponse
    {
        $validated = $request->validate(['text' => 'required|string|min:2|max:255']);

        try {
            $results = Cache::remember(
                'geocode.autocomplete.' . strtolower(trim($validated['text'])),
                3600,
                fn () => $this->geocodingService->autocomplete($validated['text'])
            );

            return response()->json(['success' => true, 'data' => $results]);

        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * GET /api/bookings
     * The authenticated passenger's booking history.
     */
    public function myBookings(Request $request): JsonResponse
    {
        $bookings = $this->bookingService->getUserBookings($request->user()->id);
        $bookings->load(['user', 'user.profile', 'ride', 'ride.driver', 'ride.driver.profile']);

        return response()->json([
            'success' => true,
            'data'    => BookingResource::collection($bookings),
        ]);
    }

    // =========================================================================
    // 3. BOOKING ACTIONS
    // =========================================================================

    /**
     * POST /api/rides/{rideId}/book
     */
    public function bookRide(BookRideRequest $request, int $rideId): JsonResponse
    {
        try {
            $dto     = BookRideDTO::fromRequest($request->validated(), $request->user()->id, $rideId);
            $booking = $this->bookingService->bookRide($dto, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Ride booked successfully',
                'data'    => new BookingResource($booking),
            ], 201);

        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/bookings/{bookingId}/accept  (driver)
     */
    public function acceptBooking(int $bookingId, Request $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->acceptBooking($bookingId, $request->user());

            $this->notify(function () use ($booking) {
                $booking->loadMissing('user');
                if (!$booking->user) return;
                $this->notificationService->createNotification(
                    $booking->user,
                    'booking_accepted',
                    'تم قبول حجزك',
                    'قبل السائق طلب حجزك. تحقق من تفاصيل رحلتك.',
                    ['booking_id' => $booking->id, 'ride_id' => $booking->ride_id],
                    'high', 'ride'
                );
            });

            return response()->json([
                'success' => true,
                'message' => 'Booking accepted',
                'data'    => new BookingResource($booking),
            ]);

        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/bookings/{bookingId}/reject  (driver)
     */
    public function rejectBooking(int $bookingId, Request $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->rejectBooking($bookingId, $request->user());

            $this->notify(function () use ($booking) {
                $booking->loadMissing('user');
                if (!$booking->user) return;
                $this->notificationService->createNotification(
                    $booking->user,
                    'booking_rejected',
                    'تم رفض طلب حجزك',
                    'اعتذر السائق عن قبول طلبك. يمكنك البحث عن رحلة أخرى.',
                    ['booking_id' => $booking->id, 'ride_id' => $booking->ride_id],
                    'normal', 'ride'
                );
            });

            return response()->json([
                'success' => true,
                'message' => 'Booking rejected',
                'data'    => new BookingResource($booking),
            ]);

        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/bookings/{bookingId}/cancel  (passenger)
     */
    public function cancelBooking(int $bookingId, Request $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->cancelBooking($bookingId, $request->user());

            $this->notify(function () use ($booking) {
                $booking->loadMissing('ride.driver');
                if (!$booking->ride?->driver) return;
                $this->notificationService->createNotification(
                    $booking->ride->driver,
                    'booking_cancelled_by_passenger',
                    'إلغاء حجز',
                    "ألغى أحد الركاب حجزه ({$booking->seats} مقعد). تم إعادة المقاعد لرحلتك.",
                    ['booking_id' => $booking->id, 'ride_id' => $booking->ride_id],
                    'normal', 'ride'
                );
            });

            return response()->json([
                'success' => true,
                'message' => 'Booking cancelled',
                'data'    => new BookingResource($booking),
            ]);

        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/bookings/{bookingId}/cancel-seats  (passenger, partial)
     */
    public function cancelPartialSeats(Request $request, int $bookingId): JsonResponse
    {
        $request->validate(['seats_to_cancel' => 'required|integer|min:1']);
        $seatsToCancel = $request->integer('seats_to_cancel');

        try {
            $result = $this->bookingService->cancelPartialSeats(
                $bookingId, $seatsToCancel, $request->user()
            );

            $this->notify(function () use ($bookingId, $seatsToCancel) {
                $booking = Booking::with('ride.driver')->find($bookingId);
                if (!$booking?->ride?->driver) return;
                $this->notificationService->createNotification(
                    $booking->ride->driver,
                    'seats_partially_cancelled',
                    'إلغاء جزئي للمقاعد',
                    "ألغى أحد الركاب {$seatsToCancel} مقعد من حجزه.",
                    ['booking_id' => $bookingId, 'ride_id' => $booking->ride_id],
                    'normal', 'ride'
                );
            });

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data'    => $result['data'],
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('Partial seat cancel failed', [
                'booking_id' => $bookingId,
                'error'      => $e->getMessage(),
                'class'      => get_class($e),
            ]);
            return $this->error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/bookings/{bookingId}/passenger-confirm  (passenger)
     *
     * FIXED from original: catch (\Exception) → catch (\Throwable)
     * The old code silently swallowed ArgumentCountError thrown by
     * PaymentStrategyFactory, leaving the escrow committed but driver unpaid.
     */
    public function passengerConfirmCompletion(Request $request, int $bookingId): JsonResponse
    {
        try {
            $result = $this->bookingService->passengerConfirmCompletion($bookingId, $request->user());

            $this->notify(function () use ($bookingId) {
                $booking = Booking::with('ride.driver')->find($bookingId);
                if (!$booking?->ride?->driver) return;
                $this->notificationService->createNotification(
                    $booking->ride->driver,
                    'passenger_confirmed',
                    'تأكيد إتمام الرحلة',
                    'أكد أحد الركاب وصوله وإتمام الرحلة بنجاح.',
                    ['booking_id' => $bookingId, 'ride_id' => $booking->ride_id],
                    'normal', 'ride'
                );
            });

            return response()->json(['success' => true, 'message' => $result['message']]);

        } catch (\Throwable $e) {
            Log::error('Passenger confirmation failed', [
                'booking_id' => $bookingId,
                'error'      => $e->getMessage(),
                'class'      => get_class($e),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ]);
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/bookings/{bookingId}/passenger-no-show  (driver reports absent passenger)
     */
    public function reportPassengerNoShow(Request $request, int $bookingId): JsonResponse
    {
        try {
            $result = $this->bookingService->reportPassengerNoShow($bookingId, $request->user());

            $this->notify(function () use ($bookingId) {
                $booking = Booking::with('user')->find($bookingId);
                if (!$booking?->user) return;
                $this->notificationService->createNotification(
                    $booking->user,
                    'no_show_recorded',
                    'تسجيل غياب',
                    'أفاد السائق بغيابك في موعد انطلاق الرحلة. تم تسجيل ذلك في ملفك.',
                    ['booking_id' => $bookingId],
                    'high', 'ride'
                );
            });

            return response()->json(['success' => true, 'message' => $result['message']]);

        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // =========================================================================
    // 4. RIDE LIFECYCLE
    // =========================================================================

    /**
     * PATCH /api/rides/{rideId}/cancel  (driver)
     */
    public function cancelRide(Request $request, int $rideId): JsonResponse
    {
        try {
            $ride = $this->rideService->cancelRide($rideId, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Ride cancelled successfully',
                'data'    => new RideResource($ride),
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('Ride cancellation failed', [
                'ride_id' => $rideId,
                'user_id' => $request->user()->id,
                'error'   => $e->getMessage(),
            ]);
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/rides/{rideId}/finish  (driver)
     *
     * Marks the ride finished and fires driver-confirm in one step,
     * then notifies all confirmed passengers to confirm their side.
     */
    public function finishRide(Request $request, int $rideId): JsonResponse
    {
        try {
            $this->rideService->finishRide($rideId, $request->user());
            $this->rideService->driverConfirmCompletion($rideId, $request->user());

            $this->notify(function () use ($rideId) {
                $ride = $this->rideService->getRideById($rideId);
                $ride->bookings()
                    ->where('status', 'confirmed')
                    ->with('user')
                    ->get()
                    ->each(function (Booking $booking) use ($rideId) {
                        if (!$booking->user) return;
                        $this->notificationService->createNotification(
                            $booking->user,
                            'ride_finished',
                            'وصلت رحلتك',
                            'أعلن السائق إتمام الرحلة. يرجى تأكيد وصولك.',
                            ['ride_id' => $rideId],
                            'high', 'ride'
                        );
                    });
            });

            return response()->json([
                'success' => true,
                'message' => 'Ride finished. Waiting for passengers to confirm.',
                'data'    => [
                    'ride_status'      => 'awaiting_confirmation',
                    'driver_confirmed' => true,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Ride finish failed', [
                'ride_id' => $rideId,
                'user_id' => $request->user()->id,
                'error'   => $e->getMessage(),
                'class'   => get_class($e),
            ]);
            return $this->error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/rides/{rideId}/driver-confirm  (driver, standalone confirm step)
     */
    public function driverConfirmCompletion(Request $request, int $rideId): JsonResponse
    {
        try {
            $result = $this->rideService->driverConfirmCompletion($rideId, $request->user());

            return response()->json(['success' => true, 'message' => $result['message']]);

        } catch (\Throwable $e) {
            Log::error('Driver confirmation failed', [
                'ride_id' => $rideId,
                'error'   => $e->getMessage(),
                'class'   => get_class($e),
            ]);
            return $this->error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/rides/{rideId}/driver-no-show  (passenger reports absent driver)
     */
    public function reportDriverNoShow(Request $request, int $rideId): JsonResponse
    {
        try {
            $result = $this->rideService->reportDriverNoShow($rideId, $request->user());

            $this->notify(function () use ($rideId) {
                $ride = $this->rideService->getRideById($rideId);
                if (!$ride->driver) return;
                $this->notificationService->createNotification(
                    $ride->driver,
                    'driver_no_show_recorded',
                    'تقرير غياب',
                    'أفاد أحد الركاب بغيابك في موعد انطلاق الرحلة.',
                    ['ride_id' => $rideId],
                    'high', 'ride'
                );
            });

            return response()->json(['success' => true, 'message' => $result['message']]);

        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Fire a notification block silently.
     * A notification failure must NEVER bubble up to the caller or roll back
     * a transaction — this wrapper guarantees that.
     */
    private function notify(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable) {
            // Intentionally swallowed.
        }
    }

    /**
     * Standard JSON error response.
     */
    private function error(string $message, int $status = 500): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
