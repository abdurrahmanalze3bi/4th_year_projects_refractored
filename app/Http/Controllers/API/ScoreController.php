<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Score\ScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ScoreController extends Controller
{
    public function __construct(private readonly ScoreService $scoreService) {}

    /**
     * GET /api/score
     * Returns the authenticated user's current score, tier, and stats.
     */
    public function show(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = Cache::remember("score.user.{$userId}", 120, function () use ($request) {
            return $this->formatScore($this->scoreService->getScore($request->user()));
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * GET /api/score/history?limit=20
     * Returns recent score transaction history.
     */
    public function history(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $limit  = min((int) $request->get('limit', 20), 50);

        $data = Cache::remember("score.history.{$userId}.{$limit}", 60, function () use ($request, $limit) {
            return $this->scoreService->getHistory($request->user(), $limit)->map(fn($tx) => [
                'id'                       => $tx->id,
                'action'                   => $tx->action,
                'points'                   => $tx->formatted_points,
                'previous_score'           => $tx->previous_score,
                'new_score'                => $tx->new_score,
                'reason'                   => $tx->reason,
                'high_cancel_rate_applied' => $tx->high_cancel_rate_applied,
                'reference_type'           => class_basename($tx->reference_type ?? ''),
                'reference_id'             => $tx->reference_id,
                'created_at'               => $tx->created_at->toIso8601String(),
            ])->all();
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /** Reusable score formatter used by other controllers. */
    public static function formatScore(\App\Models\UserScore $userScore): array
    {
        return [
            'score'               => $userScore->score,
            'tier'                => $userScore->tier,
            'cancel_rate'         => round($userScore->cancel_rate, 2),
            'total_rides'         => $userScore->total_rides,
            'total_cancellations' => $userScore->total_cancellations,
            'can_create_rides'    => $userScore->score >= 50,
            'can_book_rides'      => $userScore->score >= 40,
        ];
    }
}
