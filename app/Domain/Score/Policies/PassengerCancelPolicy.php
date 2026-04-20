<?php

namespace App\Domain\Score\Policies;

use App\Domain\Score\ScoreResult;
use App\Enums\ScoreAction;
use App\Models\UserScore;

/**
 * Passenger Cancel Policy
 *
 * Scoring grid (passenger, cancel seat(s)):
 *
 *  Elapsed     | cancel rate ≤ 50 % | cancel rate > 50 %
 *  0–30 %      |        0 pts        |      −10 pts
 *  30–50 %     |       −5 pts        |      −10 pts
 *  50–100 %    |      −10 pts        |      −10 pts
 *
 * The high-rate override only activates after MIN_INTERACTIONS total
 * rides+cancellations, preventing new users from being penalised on
 * their very first cancellation.
 */
final class PassengerCancelPolicy implements ScorePolicyInterface
{
    private const HIGH_RATE_THRESHOLD = 50.0; // > 50 % triggers the penalty tier
    private const MIN_INTERACTIONS    = 5;    // cancel rate only counts after this many total rides+cancellations

    // Base points per time tier (normal cancel rate)
    private const BASE_POINTS = [
        'passenger_cancel_early' => 0,
        'passenger_cancel_mid'   => -5,
        'passenger_cancel_late'  => -10,
    ];
    private const HIGH_RATE_POINTS = -10; // flat penalty when rate exceeded

    public function supports(ScoreAction $action): bool
    {
        return in_array($action, [
            ScoreAction::PASSENGER_CANCEL_EARLY,
            ScoreAction::PASSENGER_CANCEL_MID,
            ScoreAction::PASSENGER_CANCEL_LATE,
        ]);
    }

    public function calculate(ScoreAction $action, UserScore $userScore, array $context = []): ScoreResult
    {
        $totalInteractions = $userScore->total_rides + $userScore->total_cancellations;
        $highRate = $totalInteractions >= self::MIN_INTERACTIONS
            && $userScore->cancel_rate > self::HIGH_RATE_THRESHOLD;

        if ($highRate) {
            return ScoreResult::of(
                points: self::HIGH_RATE_POINTS,
                action: $action,
                reason: "Passenger cancelled ({$action->label()}) – high cancel rate penalty applied",
                highCancelRateApplied: true,
            );
        }

        $points = self::BASE_POINTS[$action->value];

        return ScoreResult::of(
            points: $points,
            action: $action,
            reason: "Passenger cancelled ({$action->label()})" . ($points === 0 ? ' – no penalty' : ''),
        );
    }
}
