<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookRideRequest;
use App\Http\Resources\BookingResource;
use App\Services\Ride\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * BookingController - Handles all booking operations
 *
 * Extracted from RideController to follow Single Responsibility Principle
 */
final class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookingService,
    ) {}

    /**
     * Book a ride
     * POST /api/rides/{rideId}/bookings
     */
    public function store(BookRideRequest $request, int $rideId): JsonResponse
    {
        try {
            $booking = $this->bookingService->bookRide(
                $rideId,
                $request->user(),
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'data' => new BookingResource($booking),
                'message' => 'Ride booked successfully',
            ], 201);

        } catch (\Exception $e) {
            Log::error('Booking failed', [
                'ride_id' => $rideId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Accept a booking request (driver only)
     * POST /api/bookings/{bookingId}/accept
     */
    public function accept(Request $request, int $bookingId): JsonResponse
    {
        try {
            $booking = $this->bookingService->acceptBooking(
                $bookingId,
                $request->user()
            );

            return response()->json([
                'success' => true,
                'data' => new BookingResource($booking),
                'message' => 'Booking accepted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Accept booking failed', [
                'booking_id' => $bookingId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Reject a booking request (driver only)
     * POST /api/bookings/{bookingId}/reject
     */
    public function reject(Request $request, int $bookingId): JsonResponse
    {
        try {
            $booking = $this->bookingService->rejectBooking(
                $bookingId,
                $request->user()
            );

            return response()->json([
                'success' => true,
                'data' => new BookingResource($booking),
                'message' => 'Booking rejected',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get user's bookings (as passenger)
     * GET /api/my-bookings
     */
    public function myBookings(Request $request): JsonResponse
    {
        $bookings = $request->user()
            ->bookings()
            ->with(['ride.driver.profile'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => BookingResource::collection($bookings),
        ]);
    }
    /**
     * Passenger confirms ride completion
     */
    public function passengerConfirmCompletion(Request $request, int $bookingId): JsonResponse
    {
        try {
            $result = $this->bookingService->passengerConfirmCompletion(
                $bookingId,
                $request->user()
            );

            return response()->json([
                'status'  => 'success',
                'message' => $result['message'],
            ]);

        } catch (\Exception $e) {
            Log::error('Passenger confirmation failed', [
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
    public function bookRide(Request $request, int $rideId): JsonResponse
    {
        $validated = $request->validate([
            'seats'                => 'required|integer|min:1|max:10',
            'communication_number' => 'required|string|regex:/^09\d{8}$/',
        ]);

        try {
            $dto = new \App\DTOs\Ride\BookRideDTO(
                passengerId:         $request->user()->id,
                rideId:              $rideId,
                seats:               $validated['seats'],
                communicationNumber: \App\Domain\ValueObjects\PhoneNumber::from($validated['communication_number']),
                idempotencyKey:      'book-' . $request->user()->id . '-' . $rideId . '-' . $validated['seats'],
            );

            $booking = $this->bookingService->bookRide($dto, $request->user());

            return response()->json([
                'status'  => 'success',
                'message' => 'Ride booked successfully',
                'booking' => $booking,
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Booking failed', [
                'ride_id'    => $rideId,
                'user_id'    => $request->user()->id,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
