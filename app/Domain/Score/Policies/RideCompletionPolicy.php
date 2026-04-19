<?php

namespace App\Domain\Score\Policies;

use App\Domain\Score\ScoreResult;
use App\Enums\ScoreAction;
use App\Models\UserScore;

/**
 * Ride Completion Policy
 *
 * Awards +10 points to every party on a successfully completed ride.
 * Not affected by cancel rate.
 */
final class RideCompletionPolicy implements ScorePolicyInterface
{
    private const POINTS = 10;

    public function supports(ScoreAction $action): bool
    {
        return $action === ScoreAction::RIDE_COMPLETED;
    }

    public function calculate(ScoreAction $action, UserScore $userScore, array $context = []): ScoreResult
    {
        return ScoreResult::of(
            points: self::POINTS,
            action: $action,
            reason: 'Ride completed successfully — all parties rewarded',
        );
    }
}
