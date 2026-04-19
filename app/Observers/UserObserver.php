<?php
namespace App\Observers;

use App\Models\User;
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
    }
}
