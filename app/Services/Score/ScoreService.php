<?php
namespace App\Services\Score;

use App\Domain\Score\ScorePolicyFactory;
use App\Domain\Score\ScoreResult;
use App\Enums\PaymentMethod;
use App\Enums\ScoreAction;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\ScoreTransaction;
use App\Models\User;
use App\Models\UserScore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ScoreService
{
    public function __construct(
        private readonly ScorePolicyFactory $policyFactory,
    ) {}

    // =========================================================================
    // INITIALIZATION (called by UserObserver on signup)
    // =========================================================================

    /**
     * Create UserScore row for a new user with starting score of 70 (Silver tier).
     * Called once from UserObserver::created().
     */
    public function initializeScore(User $user): UserScore
    {
        return UserScore::firstOrCreate(
            ['user_id' => $user->id],
            ['score' => 70, 'total_rides' => 0, 'total_cancellations' => 0]
        );
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Award +10 to the driver and every confirmed passenger when a ride completes.
     * Applies to both CASH and E-PAY rides.
     */
    public function recordRideCompleted(Ride $ride): void
    {
        DB::transaction(function () use ($ride) {
            $this->applyAction(
                user:      $ride->driver,
                action:    ScoreAction::RIDE_COMPLETED,
                reference: $ride,
                context:   [],
            );
            $this->incrementRides($ride->driver);

            $ride->bookings()
                ->where('status', 'completed')
                ->with('user')
                ->get()
                ->each(function ($booking) use ($ride) {
                    $this->applyAction(
                        user:      $booking->user,
                        action:    ScoreAction::RIDE_COMPLETED,
                        reference: $ride,
                        context:   [],
                    );
                    $this->incrementRides($booking->user);
                });
        });
    }

    /**
     * Penalise a passenger who cancelled seat(s).
     *
     * CASH rides only — for E-PAY rides the wallet time-based refund is the
     * "penalty"; no score change is applied.
     */
    public function recordPassengerCancel(
        User    $passenger,
        Booking $booking,
        float   $elapsedPct,
        string  $paymentMethod = 'cash',
    ): void {
        if ($paymentMethod !== 'cash') {
            return; // E-PAY: wallet handles the financial penalty, no score change
        }

        DB::transaction(function () use ($passenger, $booking, $elapsedPct) {
            $action = ScoreAction::passengerCancelAction($elapsedPct);
            $this->applyAction(
                user:      $passenger,
                action:    $action,
                reference: $booking,
                context:   ['elapsed_pct' => $elapsedPct],
            );
            $this->incrementCancellations($passenger);
        });
    }

    /**
     * Penalise a passenger marked as no-show.
     *
     * CASH rides only: −15 pts.
     * E-PAY rides: wallet split handles the penalty (95% driver, 5% SyCash) — no score change.
     */
    public function recordPassengerNoShow(
        \App\Models\User    $passenger,
        \App\Models\Booking $booking,
        string              $paymentMethod
    ): void {
        $userScore = $this->getOrCreateScore($passenger);
        $action    = \App\Enums\ScoreAction::PASSENGER_NO_SHOW;
        $result    = $this->policyFactory->make($action)
            ->calculate($action, $userScore);

        // E-PAY: wallet already penalises the passenger — no score deduction.
        // We still write a zero-point transaction so the history is complete.
        $points = ($paymentMethod === \App\Enums\PaymentMethod::E_PAY->value)
            ? 0
            : $result->points;            // −15 for CASH

        $previousScore = $userScore->score;
        $userScore->applyDelta($points);  // clamps to [0, 100] and saves
        $userScore->incrementNoShows();   // bumps total_no_shows

        \App\Models\ScoreTransaction::create([
            'user_id'                  => $passenger->id,
            'action'                   => $action->value,
            'points'                   => $points,
            'previous_score'           => $previousScore,
            'new_score'                => $userScore->score,
            'reference_type'           => \App\Models\Booking::class,
            'reference_id'             => $booking->id,
            'reason'                   => $paymentMethod === \App\Enums\PaymentMethod::E_PAY->value
                ? 'Passenger no-show (e-pay) — score unchanged, wallet settled'
                : $result->reason,
            'high_cancel_rate_applied' => false,
            'metadata'                 => [
                'payment_method' => $paymentMethod,
                'booking_id'     => $booking->id,
            ],
        ]);
    }



    /**
     * Penalise a driver who cancelled a single passenger seat.
     * −5 pts always, regardless of payment method or cancel rate.
     */
    public function recordDriverCancelSeat(User $driver, Booking $booking): void
    {
        DB::transaction(function () use ($driver, $booking) {
            $this->applyAction(
                user:      $driver,
                action:    ScoreAction::DRIVER_CANCEL_SEAT,
                reference: $booking,
                context:   [],
            );
        });
    }

    /**
     * Penalise a driver who cancelled their entire ride.
     * Applies to both CASH and E-PAY rides.
     * Tier: 0–30% = 0pts, 30–50% = −7pts, 50–100% = −12pts; ≥50% cancel rate → −15 always.
     */
    public function recordDriverCancelRide(
        User  $driver,
        Ride  $ride,
        float $elapsedPct,
    ): void {
        DB::transaction(function () use ($driver, $ride, $elapsedPct) {
            $action = ScoreAction::driverCancelRideAction($elapsedPct);
            $this->applyAction(
                user:      $driver,
                action:    $action,
                reference: $ride,
                context:   ['elapsed_pct' => $elapsedPct],
            );
            $this->incrementCancellations($driver);
        });
    }

    /**
     * Penalise a driver who was marked as no-show.
     * −15 pts always for BOTH cash and e-pay rides.
     */
    public function recordDriverNoShow(
        \App\Models\User $driver,
        \App\Models\Ride $ride,
        string           $paymentMethod
    ): void {
        $userScore = $this->getOrCreateScore($driver);
        $action    = \App\Enums\ScoreAction::DRIVER_NO_SHOW;
        $result    = $this->policyFactory->make($action)
            ->calculate($action, $userScore);

        $points = ($paymentMethod === \App\Enums\PaymentMethod::E_PAY->value)
            ? 0
            : $result->points;            // −15 for CASH

        $previousScore = $userScore->score;
        $userScore->applyDelta($points);
        $userScore->incrementNoShows();

        \App\Models\ScoreTransaction::create([
            'user_id'                  => $driver->id,
            'action'                   => $action->value,
            'points'                   => $points,
            'previous_score'           => $previousScore,
            'new_score'                => $userScore->score,
            'reference_type'           => \App\Models\Ride::class,
            'reference_id'             => $ride->id,
            'reason'                   => $paymentMethod === \App\Enums\PaymentMethod::E_PAY->value
                ? 'Driver no-show (e-pay) — score unchanged, passengers refunded'
                : $result->reason,
            'high_cancel_rate_applied' => false,
            'metadata'                 => [
                'payment_method' => $paymentMethod,
                'ride_id'        => $ride->id,
            ],
        ]);
    }

    // =========================================================================
    // READ
    // =========================================================================

    public function getScore(User $user): UserScore
    {
        return UserScore::firstOrCreate(
            ['user_id' => $user->id],
            ['score' => 70, 'total_rides' => 0, 'total_cancellations' => 0],
        );
    }
    private function getOrCreateScore(\App\Models\User $user): \App\Models\UserScore
    {
        return \App\Models\UserScore::firstOrCreate(
            ['user_id' => $user->id],
            [
                'score'               => 70,
                'total_rides'         => 0,
                'total_cancellations' => 0,
                'total_no_shows'      => 0,
            ]
        );
    }
    public function getHistory(User $user, int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        return ScoreTransaction::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /** Calculate elapsed percentage from ride/booking creation to departure time. */
    public static function calculateElapsedPct(
        \Carbon\Carbon $createdAt,
        \Carbon\Carbon $departureTime,
    ): float {
        $now            = now();
        $totalMinutes   = $createdAt->diffInMinutes($departureTime);
        $elapsedMinutes = $createdAt->diffInMinutes($now);

        return $totalMinutes > 0
            ? min(100, round(($elapsedMinutes / $totalMinutes) * 100, 2))
            : 100.0;
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function applyAction(
        User        $user,
        ScoreAction $action,
        object      $reference,
        array       $context,
    ): ScoreResult {
        $userScore = $this->getScore($user);
        $policy    = $this->policyFactory->make($action);
        $result    = $policy->calculate($action, $userScore, $context);

        $previousScore = $userScore->score;
        $userScore->applyDelta($result->points);

        ScoreTransaction::create([
            'user_id'                  => $user->id,
            'action'                   => $result->action->value,
            'points'                   => $result->points,
            'previous_score'           => $previousScore,
            'new_score'                => $userScore->score,
            'reference_type'           => get_class($reference),
            'reference_id'             => $reference->id,
            'reason'                   => $result->reason,
            'high_cancel_rate_applied' => $result->highCancelRateApplied,
            'metadata'                 => array_merge($context, $result->toArray()),
        ]);

        Log::info('Score applied', [
            'user_id'        => $user->id,
            'action'         => $action->value,
            'points'         => $result->points,
            'previous_score' => $previousScore,
            'new_score'      => $userScore->score,
        ]);

        return $result;
    }

    private function incrementRides(User $user): void
    {
        $this->getScore($user)->incrementRides();
    }

    private function incrementCancellations(User $user): void
    {
        $this->getScore($user)->incrementCancellations();
    }
}
