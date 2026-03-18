<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\ProfileRepositoryInterface;
use App\Services\Profile\ProfileUpdateService;
use App\Services\Profile\ProfileInteractionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

/**
 * Profile Controller (REFACTORED)
 *
 * BEFORE: 500+ lines doing everything
 * AFTER: 150 lines - thin controller delegating to services
 *
 * Delegates to:
 * - ProfileUpdateService: Profile updates
 * - ProfileInteractionService: Comments and ratings
 * - ProfileRepositoryInterface: Data retrieval
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileRepositoryInterface $profileRepo,
        private readonly ProfileUpdateService $updateService,
        private readonly ProfileInteractionService $interactionService
    ) {}

    /**
     * Show profile
     * GET /profile/{userId}
     */
    public function show(Request $request, int $userId)
    {
        try {
            $authUser = $request->user();
            $profile = $this->profileRepo->getProfileWithUser($userId);
            $isOwner = ($authUser->id == $userId);

            return response()->json([
                'success' => true,
                'data' => $this->formatProfileData($profile, $profile->user, $isOwner),
            ], 200);
        } catch (\Exception $e) {
            Log::error("Profile fetch error: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }
    }

    /**
     * Update profile
     * POST /profile
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:500',
            'address' => 'nullable|in:دمشق,درعا,القنيطرة,السويداء,ريف دمشق,حمص,حماة,اللاذقية,طرطوس,حلب,ادلب,الحسكة,الرقة,دير الزور',
            'gender' => 'nullable|in:M,F',
            'type_of_car' => 'nullable|string|max:255',
            'color_of_car' => 'nullable|string|max:50',
            'number_of_seats' => 'nullable|integer|min:1|max:12',
            'radio' => 'nullable|boolean',
            'smoking' => 'nullable|boolean',
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'car_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'face_id_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'back_id_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'driving_license_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'mechanic_card_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Cast booleans
        foreach (['radio', 'smoking'] as $key) {
            if ($request->has($key)) {
                $data[$key] = (bool) $request->input($key);
            }
        }

        // Prevent manual ride count updates
        if (isset($data['number_of_rides'])) {
            return response()->json([
                'success' => false,
                'message' => 'Ride count cannot be updated manually.',
            ], 422);
        }

        try {
            // Delegate to service
            $result = $this->updateService->updateProfile($user, $data);

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $this->formatProfileData($result['profile'], $result['user']),
            ], 200);
        } catch (\Exception $e) {
            Log::error("Profile update error: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Add comment to profile
     * POST /profile/{userId}/comments
     */
    public function comment(Request $request, int $userId)
    {
        $validator = Validator::make($request->all(), [
            'comment' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $comment = $this->interactionService->addComment(
                $request->user()->id,
                $userId,
                $request->input('comment')
            );

            return response()->json([
                'success' => true,
                'message' => 'Comment added',
                'data' => $comment,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Rate user
     * POST /profile/{userId}/rate
     */
    public function rateUser(Request $request, int $userId)
    {
        $validator = Validator::make($request->all(), [
            'rating' => 'required|numeric|min:1|max:5',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $ratingStats = $this->interactionService->rateUser(
                $request->user()->id,
                $userId,
                (float) $request->input('rating')
            );

            return response()->json([
                'success' => true,
                'message' => 'Rating submitted successfully',
                'data' => $ratingStats,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Format profile data for API response
     */
    private function formatProfileData($profile, $user, bool $isOwner = false): array
    {
        $comments = $this->interactionService->getProfileComments($user->id);
        $ratingStats = $this->interactionService->getRatingStats($user->id);

        // Get documents
        $photoRepo = app(\App\Interfaces\PhotoRepositoryInterface::class);
        $docs = $photoRepo->getUserDocumentsByType(
            $user->id,
            ['face_id', 'back_id', 'license', 'mechanic_card']
        )
            ->mapWithKeys(fn($d) => ["{$d->type}_pic" => asset("storage/{$d->path}")])
            ->toArray();

        return [
            'user_id' => $user->id,
            'full_name' => trim("{$user->first_name} {$user->last_name}"),
            'verification_status' => $user->verification_status,
            'address' => $profile->address,
            'gender' => $profile->gender,
            'profile_photo' => $profile->profile_photo
                ? asset("storage/{$profile->profile_photo}")
                : null,
            'description' => $profile->description,
            'type_of_car' => $profile->type_of_car,
            'color_of_car' => $profile->color_of_car,
            'number_of_seats' => $profile->number_of_seats,
            'car_pic' => $profile->car_pic ? asset("storage/{$profile->car_pic}") : null,
            'radio' => $profile->radio,
            'smoking' => $profile->smoking,
            'number_of_rides' => $profile->number_of_rides,
            'documents' => $docs,
            'comments' => $comments,
            'rating' => $ratingStats,
        ];
    }
}
