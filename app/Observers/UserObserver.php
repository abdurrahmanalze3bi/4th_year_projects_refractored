<?php

namespace App\Observers;

use App\Models\User;
use App\Models\UserRating;
use App\Interfaces\ProfileRepositoryInterface;
use App\Services\Score\ScoreService;

class UserObserver
{
    public function __construct(
        private ProfileRepositoryInterface $profileRepo,
        private ScoreService               $scoreService,
    ) {}

    public function created(User $user): void
    {
        // Auto-create profile row
        $this->profileRepo->createFromUser($user);

        // Initialize trust score at 70 (Silver tier) per SRS v5
        $this->scoreService->initializeScore($user);

        // Seed a 3.0 base rating so every new user starts visible with a rating.
        // The admin account acts as the system rater (same pattern as verifyDriver).
        // firstOrCreate prevents duplicates if the observer fires more than once.
        $adminUser = User::where('email', config('admin.primary.email'))->first();
        if ($adminUser) {
            UserRating::firstOrCreate(
                [
                    'rater_id'      => $adminUser->id,
                    'rated_user_id' => $user->id,
                ],
                [
                    'rating' => 3.0,
                ]
            );
        }
    }
}
