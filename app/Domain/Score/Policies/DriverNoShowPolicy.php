<?php

namespace App\Domain\Score\Policies;

use App\Domain\Score\ScoreResult;
use App\Enums\ScoreAction;
use App\Models\UserScore;

/**
 * Driver No-Show Policy
 *
 * Fixed −15 pts regardless of elapsed time or cancel rate.
 */
final class DriverNoShowPolicy implements ScorePolicyInterface
{
    private const POINTS = -15;

    public function supports(ScoreAction $action): bool
    {
        return $action === ScoreAction::DRIVER_NO_SHOW;
    }

    public function calculate(ScoreAction $action, UserScore $userScore, array $context = []): ScoreResult
    {
        return ScoreResult::of(
            points: self::POINTS,
            action: $action,
            reason: 'Driver absent at departure — no-show penalty applied',
        );
    }
}
