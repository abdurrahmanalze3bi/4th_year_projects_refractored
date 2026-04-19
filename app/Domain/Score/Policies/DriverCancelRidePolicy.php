<?php

namespace App\Domain\Score\Policies;

use App\Domain\Score\ScoreResult;
use App\Enums\ScoreAction;
use App\Models\UserScore;

/**
 * Driver Cancel Ride Policy
 *
 * Scoring grid (driver, cancel whole ride):
 *
 *  Elapsed     | cancel rate < 50 % | cancel rate ≥ 50 %
 *  0–30 %      |        0 pts        |      −15 pts
 *  30–50 %     |       −7 pts        |      −15 pts
 *  50–100 %    |      −12 pts        |      −15 pts
 *
 * When cancel rate ≥ 50 %, the rate always overrides the time tier.
 */
final class DriverCancelRidePolicy implements ScorePolicyInterface
{
    private const HIGH_RATE_THRESHOLD = 50.0; // ≥ 50 % triggers the override

    private const BASE_POINTS = [
        'driver_cancel_ride_early' => 0,
        'driver_cancel_ride_mid'   => -7,
        'driver_cancel_ride_late'  => -12,
    ];

    private const HIGH_RATE_POINTS = -15;

    public function supports(ScoreAction $action): bool
    {
        return in_array($action, [
            ScoreAction::DRIVER_CANCEL_RIDE_EARLY,
            ScoreAction::DRIVER_CANCEL_RIDE_MID,
            ScoreAction::DRIVER_CANCEL_RIDE_LATE,
        ]);
    }

    public function calculate(ScoreAction $action, UserScore $userScore, array $context = []): ScoreResult
    {
        $highRate = $userScore->cancel_rate >= self::HIGH_RATE_THRESHOLD;

        if ($highRate) {
            return ScoreResult::of(
                points: self::HIGH_RATE_POINTS,
                action: $action,
                reason: "Driver cancelled ride ({$action->label()}) — high cancel rate overrides time tier",
                highCancelRateApplied: true,
            );
        }

        $points = self::BASE_POINTS[$action->value];

        return ScoreResult::of(
            points: $points,
            action: $action,
            reason: "Driver cancelled ride ({$action->label()})" . ($points === 0 ? ' — no penalty' : ''),
        );
    }
}
