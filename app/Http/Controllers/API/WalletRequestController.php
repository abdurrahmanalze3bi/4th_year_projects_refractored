<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WalletRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * WalletRequestController
 *
 * User-facing endpoints for submitting and viewing wallet requests.
 *
 * Routes (all behind `jwt` middleware):
 *   POST /api/wallet/request-charge    → requestCharge()
 *   POST /api/wallet/request-withdraw  → requestWithdraw()
 *   GET  /api/wallet/requests          → myRequests()
 */
class WalletRequestController extends Controller
{
    // ── POST /api/wallet/request-charge ──────────────────────────────────────

    /**
     * User asks admin to top up their wallet.
     *
     * The user specifies the amount they want added; the admin reviews
     * and, when approved, manually adds the balance (offline transfer).
     */
    public function requestCharge(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->wallet) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have a wallet yet. Please create one first.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1|max:10000000',
            'notes'  => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Prevent multiple pending charge requests from the same user
        $alreadyPending = WalletRequest::where('user_id', $user->id)
            ->where('type', 'charge')
            ->where('status', 'pending')
            ->exists();

        if ($alreadyPending) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending charge request. Please wait for it to be reviewed.',
            ], 409);
        }

        $walletRequest = WalletRequest::create([
            'user_id'    => $user->id,
            'wallet_id'  => $user->wallet->id,
            'type'       => 'charge',
            'amount'     => $request->input('amount'),
            'status'     => 'pending',
            'user_notes' => $request->input('notes'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Charge request submitted. The admin will review it shortly.',
            'data'    => $this->formatRequest($walletRequest),
        ], 201);
    }

    // ── POST /api/wallet/request-withdraw ────────────────────────────────────

    /**
     * User asks admin to withdraw funds from their wallet.
     *
     * Validated immediately against current balance so the user
     * gets instant feedback; balance is only deducted on approval.
     */
    public function requestWithdraw(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->wallet) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have a wallet yet.',
            ], 422);
        }

        $wallet = $user->wallet;

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'notes'  => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $amount = (float) $request->input('amount');

        // Check current balance
        if ($amount > (float) $wallet->balance) {
            return response()->json([
                'success' => false,
                'message' => "Insufficient balance. Your current balance is {$wallet->balance} SYP.",
            ], 422);
        }

        // Prevent multiple pending withdraw requests
        $pendingTotal = WalletRequest::where('user_id', $user->id)
            ->where('type', 'withdraw')
            ->where('status', 'pending')
            ->sum('amount');

        if (($pendingTotal + $amount) > (float) $wallet->balance) {
            return response()->json([
                'success' => false,
                'message' => "You already have pending withdraw requests totalling {$pendingTotal} SYP. This request would exceed your balance.",
            ], 422);
        }

        $walletRequest = WalletRequest::create([
            'user_id'    => $user->id,
            'wallet_id'  => $wallet->id,
            'type'       => 'withdraw',
            'amount'     => $amount,
            'status'     => 'pending',
            'user_notes' => $request->input('notes'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Withdraw request submitted. The admin will process it shortly.',
            'data'    => $this->formatRequest($walletRequest),
        ], 201);
    }

    // ── GET /api/wallet/requests ──────────────────────────────────────────────

    /**
     * Returns the authenticated user's own wallet requests (newest first).
     */
    public function myRequests(Request $request): JsonResponse
    {
        $requests = WalletRequest::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $requests->map(fn($r) => $this->formatRequest($r))->values(),
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function formatRequest(WalletRequest $r): array
    {
        return [
            'id'           => $r->id,
            'type'         => $r->type,
            'amount'       => (float) $r->amount,
            'status'       => $r->status,
            'user_notes'   => $r->user_notes,
            'admin_notes'  => $r->admin_notes,
            'processed_at' => $r->processed_at?->toIso8601String(),
            'created_at'   => $r->created_at->toIso8601String(),
        ];
    }
}
