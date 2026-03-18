<?php

namespace App\Services\Profile;

use App\Interfaces\ProfileRepositoryInterface;
use App\Models\ProfileComment;
use App\Models\UserRating;
use App\Models\User;
use App\Models\Profile;
use Illuminate\Support\Facades\Log;

/**
 * Profile Interaction Service
 *
 * EXTRACTED FROM: ProfileController
 *
 * Single Responsibility: Handle profile interactions (comments, ratings)
 *
 * Separated from ProfileUpdateService because:
 * - Different responsibility (social interactions vs data updates)
 * - Can be tested independently
 * - Can be reused in other contexts
 */
final class ProfileInteractionService
{
    public function __construct(
        private readonly ProfileRepositoryInterface $profileRepo
    ) {}

    /**
     * Add a comment to a profile
     *
     * @throws \Exception if user tries to comment on own profile or profile not found
     */
    public function addComment(int $userId, int $targetUserId, string $commentText): array
    {
        // Prevent self-commenting
        if ($userId === $targetUserId) {
            throw new \Exception("You can't comment on your own profile.");
        }

        // Get target profile
        $profile = $this->profileRepo->getProfileByUserId($targetUserId);

        if (!$profile) {
            throw new \Exception('Profile not found');
        }

        // Create comment
        $comment = ProfileComment::create([
            'profile_id' => $profile->id,
            'user_id' => $userId,
            'comment' => $commentText,
        ]);

        // Load commenter relationship
        $comment->load('commenter');

        Log::info('Profile comment added', [
            'profile_id' => $profile->id,
            'commenter_id' => $userId,
        ]);

        return $this->formatComment($comment);
    }

    /**
     * Rate a user
     *
     * @throws \Exception if user tries to rate themselves or user not found
     */
    public function rateUser(int $raterId, int $targetUserId, float $rating): array
    {
        // Validate rating value
        if ($rating < 1 || $rating > 5) {
            throw new \Exception('Rating must be between 1 and 5');
        }

        // Prevent self-rating
        if ($raterId === $targetUserId) {
            throw new \Exception("You can't rate yourself.");
        }

        // Verify target user exists
        $ratedUser = User::find($targetUserId);

        if (!$ratedUser) {
            throw new \Exception('User not found');
        }

        // Create or update rating
        UserRating::updateOrCreate(
            [
                'rater_id' => $raterId,
                'rated_user_id' => $targetUserId
            ],
            [
                'rating' => $rating
            ]
        );

        Log::info('User rating submitted', [
            'rater_id' => $raterId,
            'rated_user_id' => $targetUserId,
            'rating' => $rating,
        ]);

        return $this->getRatingStats($targetUserId);
    }

    /**
     * Get rating statistics for a user
     */
    public function getRatingStats(int $userId): array
    {
        $stats = UserRating::where('rated_user_id', $userId)
            ->selectRaw('COUNT(*) as total_ratings, AVG(rating) as average_rating')
            ->first();

        return [
            'total_ratings' => (int) ($stats->total_ratings ?? 0),
            'average_rating' => $stats->average_rating ? round($stats->average_rating, 2) : 0,
        ];
    }

    /**
     * Get all comments for a profile
     */
    public function getProfileComments(int $userId): array
    {
        $profile = $this->profileRepo->getProfileWithUser($userId);

        if (!$profile) {
            return [];
        }

        return collect($profile->comments ?? [])
            ->map(fn($comment) => $this->formatComment($comment))
            ->all();
    }

    /**
     * Delete a comment
     *
     * @throws \Exception if comment not found or user doesn't have permission
     */
    public function deleteComment(int $commentId, int $userId): bool
    {
        $comment = ProfileComment::find($commentId);

        if (!$comment) {
            throw new \Exception('Comment not found');
        }

        // Only comment author or profile owner can delete
        if ($comment->user_id !== $userId && $comment->profile->user_id !== $userId) {
            throw new \Exception('You do not have permission to delete this comment');
        }

        return $comment->delete();
    }

    /**
     * Format comment for API response
     */
    private function formatComment(ProfileComment $comment): array
    {
        $user = $comment->commenter;
        $profile = Profile::where('user_id', $user->id)->first();

        $photo = null;
        if ($profile && $profile->profile_photo && file_exists(storage_path('app/public/' . $profile->profile_photo))) {
            $photo = asset('storage/' . $profile->profile_photo);
        }

        return [
            'id' => $comment->id,
            'comment' => $comment->comment,
            'commenter' => [
                'id' => $user->id,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'profile_photo' => $photo,
            ],
            'created_at' => $comment->created_at->toDateTimeString(),
        ];
    }
}
