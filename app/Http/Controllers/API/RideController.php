<?php

namespace App\Http\Controllers\API;

use App\DTOs\Ride\CreateRideDTO;
use App\DTOs\Ride\BookRideDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateRideRequest;
use App\Http\Requests\BookRideRequest;
use App\Http\Resources\RideResource;
use App\Http\Resources\BookingResource;
use App\Services\Geocoding\GeocodingService;
use App\Services\Geocoding\RouteCalculationService;
use App\Services\Ride\RideService;
use App\Services\Ride\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Score\ScoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

/**
 * Ride Controller
 *
 * FIXED:
 * 1. Correct method signatures (passes DTOs)
 * 2. Eager loading to prevent N+1 queries
 * 3. Proper error handling
 *
 * Thin controller - delegates to services
 * BEFORE: 3024 lines | AFTER: ~200 lines = 93% REDUCTION!
 */
class RideController extends Controller
{
    public function __construct(
        private readonly RideService             $rideService,
        private readonly BookingService          $bookingService,
        private readonly GeocodingService        $geocodingService,
        private readonly RouteCalculationService $routeService,
    ) {}
    /**
     * Create a new ride
     *
     * POST /rides
     */
    public function create(CreateRideRequest $request): JsonResponse
    {
        try {
            $dto = CreateRideDTO::fromRequest($request->validated(), $request->user()->id);
            $ride = $this->rideService->createRide($dto, $request->user());

            return response()->json([
                'success' => true,
                'data' => new RideResource($ride),
                'message' => 'Ride created successfully'
            ], 201);
        } catch (\Exception $e) {
            // In create() method, change the return to:
            $score = app(ScoreService::class)->getScore($request->user());

            return response()->json([
                'success' => true,
                'data'    => new RideResource($ride),
                'message' => 'Ride created successfully',
                'driver_score' => ScoreController::formatScore($score),
            ], 201);
        }
    }

    /**
     * Get route options
     *
     * GET /rides/route-options
     */
    /**
     * Get multiple route alternatives between two points
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

            $routes = $this->routeService->getRouteAlternatives($origin, $destination, 3);

            return response()->json([
                'success' => true,
                'data'    => [
                    'routes'      => $routes,
                    'pickup'      => $origin,
                    'destination' => $destination,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Route options failed', [
                'error'   => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get route options: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Book a ride
     *
     * FIXED: Passes DTO correctly
     *
     * POST /rides/{rideId}/book
     */
    public function bookRide(BookRideRequest $request, int $rideId): JsonResponse
    {
        try {
            // ✅ FIXED: Create DTO and pass to service correctly
            $dto = BookRideDTO::fromRequest($request->validated(), $request->user()->id, $rideId);
            $booking = $this->bookingService->bookRide($dto, $request->user());

            return response()->json([
                'success' => true,
                'data' => new BookingResource($booking),
                'message' => 'Ride booked successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Get ride details
     *
     * GET /rides/{rideId}
     */
    public function show(int $rideId): JsonResponse
    {
        try {
            $ride = $this->rideService->getRideById($rideId);

            // Load booking count for seats calculation
            $ride->loadCount(['bookings as total_booked_seats' => function ($query) {
                $query->select(DB::raw('COALESCE(SUM(seats), 0)'));
            }]);

            return response()->json([
                'success' => true,
                'data' => new RideResource($ride)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ride not found'
            ], 404);
        }
    }

    /**
     * Get all rides for the authenticated driver
     */
    public function getRides(Request $request): JsonResponse
    {
        try {
            $rides = $this->rideService->getDriverRides($request->user()->id);

            return response()->json([
                'success' => true,
                'data'    => $rides,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch driver rides', [
                'driver_id' => $request->user()->id,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch rides: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get details of a single ride
     */
    public function getRideDetails(int $rideId): JsonResponse
    {
        try {
            $ride = $this->rideService->getRideById($rideId);

            // ✅ FIXED: load the count so RideResource can compute seats.booked
            $ride->loadCount(['bookings as total_booked_seats' => function ($query) {
                $query->select(DB::raw('COALESCE(SUM(seats), 0)'));
            }]);

            return response()->json([
                'success' => true,
                'data'    => new RideResource($ride),  // ← was: $ride (raw model)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ride not found: ' . $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Search for available rides
     */
    public function searchRides(Request $request): JsonResponse
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

            return response()->json([
                'success' => true,
                'data'    => $rides,
            ]);

        } catch (\Exception $e) {
            Log::error('Ride search failed', [
                'error'  => $e->getMessage(),
                'params' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Search failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Address autocomplete
     * Query param: text (not query — matches route definition)
     */
    public function autocomplete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string|min:2|max:255',
        ]);

        try {
            $results = $this->geocodingService->autocomplete($validated['text']);

            return response()->json([
                'success' => true,
                'data'    => $results,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's rides (as driver)
     *
     * GET /rides/my-rides
     */
    public function index(Request $request): JsonResponse
    {
        $rides = $this->rideService->getUserRides($request->user()->id);

        // ✅ FIXED: Eager load to prevent N+1
        $rides->load(['driver', 'driver.profile'])
            ->loadCount(['bookings as total_booked_seats' => function ($query) {
                $query->select(DB::raw('COALESCE(SUM(seats), 0)'));
            }]);

        return response()->json([
            'success' => true,
            'data' => RideResource::collection($rides)
        ]);
    }

    /**
     * Search rides
     *
     * FIXED: Eager loads relationships
     *
     * GET /rides/search
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'source_lat' => 'required|numeric',
            'source_lng' => 'required|numeric',
            'dest_lat' => 'required|numeric',
            'dest_lng' => 'required|numeric',
            'departure_date' => 'required|date',
            'seats_required' => 'required|integer|min:1',
        ]);

        try {
            $rides = $this->rideService->searchRides($request->all());

            // ✅ Note: RideSearchService already eager loads these
            // But we add the count here to be explicit
            $rides->loadCount(['bookings as total_booked_seats' => function ($query) {
                $query->select(DB::raw('COALESCE(SUM(seats), 0)'));
            }]);

            return response()->json([
                'success' => true,
                'data' => RideResource::collection($rides),
                'count' => $rides->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel ride
     *
     * POST /rides/{rideId}/cancel
     */
    public function cancel(int $rideId, Request $request): JsonResponse
    {
        try {
            $ride = $this->rideService->cancelRide($rideId, $request->user());

            return response()->json([
                'success' => true,
                'data' => new RideResource($ride),
                'message' => 'Ride cancelled successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Finish ride
     *
     * POST /rides/{rideId}/finish
     */
    public function finish(int $rideId, Request $request): JsonResponse
    {
        try {
            $ride = $this->rideService->finishRide($rideId, $request->user());

            return response()->json([
                'success' => true,
                'data' => new RideResource($ride),
                'message' => 'Ride finished successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Get user's bookings (as passenger)
     *
     * FIXED: Eager loads relationships
     *
     * GET /bookings/my-bookings
     */
    public function myBookings(Request $request): JsonResponse
    {
        $bookings = $this->bookingService->getUserBookings($request->user()->id);

        // ✅ Note: BookingService already eager loads, but being explicit
        $bookings->load(['user', 'user.profile', 'ride', 'ride.driver', 'ride.driver.profile']);

        return response()->json([
            'success' => true,
            'data' => BookingResource::collection($bookings)
        ]);
    }

    /**
     * Cancel booking
     *
     * POST /bookings/{bookingId}/cancel
     */
    public function cancelBooking(int $bookingId, Request $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->cancelBooking($bookingId, $request->user());

            return response()->json([
                'success' => true,
                'data' => new BookingResource($booking),
                'message' => 'Booking cancelled successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Accept booking (driver only)
     *
     * POST /bookings/{bookingId}/accept
     */
    public function acceptBooking(int $bookingId, Request $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->acceptBooking($bookingId, $request->user());

            return response()->json([
                'success' => true,
                'data' => new BookingResource($booking),
                'message' => 'Booking accepted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Reject booking (driver only)
     *
     * POST /bookings/{bookingId}/reject
     */
    public function rejectBooking(int $bookingId, Request $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->rejectBooking($bookingId, $request->user());

            return response()->json([
                'success' => true,
                'data' => new BookingResource($booking),
                'message' => 'Booking rejected successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }


    /**
     * Create a ride with pre-calculated route geometry
     * Used when the client has already selected a route from /rides/route-options
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
            'departure_time' => 'required|date|after:' . now()->addMinutes(5)->toDateTimeString(),
            'available_seats'      => 'required|integer|min:1|max:8',
            'price_per_seat'       => 'required|numeric|min:0',
            'vehicle_type'         => 'required|string|max:100',
            'payment_method'       => 'required|in:cash,e-pay',
            'booking_type'         => 'required|in:direct,request',
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
            // Resolve addresses from coordinates if not provided by client
            if (empty($validated['pickup_address'])) {
                $validated['pickup_address'] = $this->geocodingService->reverseGeocode(
                    $validated['pickup_lat'],
                    $validated['pickup_lng']
                );
            }

            if (empty($validated['destination_address'])) {
                $validated['destination_address'] = $this->geocodingService->reverseGeocode(
                    $validated['destination_lat'],
                    $validated['destination_lng']
                );
            }

            // Resolve route geometry, distance and duration if not provided by client
            if (empty($validated['route_geometry'])
                || empty($validated['distance'])
                || empty($validated['duration'])
            ) {
                $route = $this->routeService->getRouteDetails(
                    ['lat' => $validated['pickup_lat'],      'lng' => $validated['pickup_lng']],
                    ['lat' => $validated['destination_lat'], 'lng' => $validated['destination_lng']]
                );

                $validated['distance']       = $validated['distance']  ?? $route['distance'];
                $validated['duration']       = $validated['duration']  ?? $route['duration'];
                $validated['route_geometry'] = $validated['route_geometry'] ?? [
                    'type'        => 'LineString',
                    'coordinates' => $route['geometry'],
                ];
            }

            $dto  = CreateRideDTO::fromRequest($validated, $request->user()->id);
            $ride = $this->rideService->createRide($dto, $request->user());

            return response()->json([
                'status'  => 'success',
                'message' => 'Ride created successfully',
                'ride'    => $ride,
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Create ride with route failed', [
                'user_id' => $request->user()->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Cancel a ride — delegates all business logic and refund processing to RideService
     */
    public function cancelRide(Request $request, int $rideId): JsonResponse
    {
        try {
            $ride = $this->rideService->cancelRide($rideId, $request->user());

            return response()->json([
                'status'  => 'success',
                'message' => 'Ride cancelled successfully.',
                'ride'    => $ride,
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Ride cancellation failed', [
                'ride_id' => $rideId,
                'user_id' => $request->user()->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Finish a ride — delegates all status transitions and booking completion to RideService
     */
    public function finishRide(Request $request, int $rideId): JsonResponse
    {
        try {
            $result = $this->rideService->finishRide($rideId, $request->user());

            return response()->json([
                'status'  => 'success',
                'message' => $result['message'],
                'data'    => [
                    'ride_status'            => $result['status'],
                    'requires_confirmation'  => $result['requires_confirmation'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Ride finish failed', [
                'ride_id' => $rideId,
                'user_id' => $request->user()->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
    /**
     * Driver confirms ride completion
     */
    public function driverConfirmCompletion(Request $request, int $rideId): JsonResponse
    {
        try {
            $result = $this->rideService->driverConfirmCompletion($rideId, $request->user());

            return response()->json([
                'status'  => 'success',
                'message' => $result['message'],
            ]);

        } catch (\Exception $e) {
            Log::error('Driver confirmation failed', [
                'ride_id' => $rideId,
                'user_id' => $request->user()->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Cancel partial seats on a booking
     */
    public function cancelPartialSeats(Request $request, int $bookingId): JsonResponse
    {
        $validated = $request->validate([
            'seats_to_cancel' => 'required|integer|min:1',
        ]);

        try {
            $result = $this->bookingService->cancelPartialSeats(
                $bookingId,
                $validated['seats_to_cancel'],
                $request->user()
            );

            return response()->json([
                'status'  => 'success',
                'message' => $result['message'],
                'data'    => $result['data'],
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Partial seat cancellation failed', [
                'booking_id' => $bookingId,
                'user_id'    => $request->user()->id,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
    public function passengerConfirmCompletion(Request $request, int $booking): JsonResponse
    {
        try {
            $result = $this->bookingService->passengerConfirmCompletion($booking, $request->user());
            return response()->json([
                'status'  => 'success',
                'message' => $result['message'],
            ]);
        } catch (\Exception $e) {
            Log::error('Passenger confirmation failed', [
                'booking_id' => $booking,
                'user_id'    => $request->user()->id,
                'error'      => $e->getMessage(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
    public function getMyBookings(Request $request): JsonResponse
    {
        $bookings = $this->bookingService->getUserBookings($request->user()->id);
        $bookings->load(['user', 'user.profile', 'ride', 'ride.driver', 'ride.driver.profile']);

        return response()->json([
            'success' => true,
            'data'    => BookingResource::collection($bookings),
        ]);
    }



    public function reportPassengerNoShow(Request $request, int $bookingId): JsonResponse
    {
        try {
            $result = $this->bookingService->reportPassengerNoShow($bookingId, $request->user());
            return response()->json(['status' => 'success', 'message' => $result['message']]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Passenger reports driver as no-show.
     * POST /rides/{rideId}/driver-no-show
     */
    public function reportDriverNoShow(Request $request, int $rideId): JsonResponse
    {
        try {
            $result = $this->rideService->reportDriverNoShow($rideId, $request->user());
            return response()->json(['status' => 'success', 'message' => $result['message']]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

}
