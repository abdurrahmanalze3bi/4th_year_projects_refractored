<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletRequest;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * AdminWalletRequestController
 *
 * Admin endpoints for reviewing and acting on wallet charge/withdraw requests.
 *
 * Routes (all behind `auth.admin` middleware):
 *   GET   /api/admin/wallet/requests           → index()
 *   POST  /api/admin/wallet/requests/{id}/approve → approve()
 *   POST  /api/admin/wallet/requests/{id}/reject  → reject()
 */
final class AdminWalletRequestController extends Controller
{
    // ── GET /api/admin/wallet/requests ────────────────────────────────────────

    /**
     * List wallet requests with optional filters.
     *
     * Query params:
     *   status  = pending | approved | rejected   (default: pending)
     *   type    = charge | withdraw               (default: all)
     *   per_page = 1-50                           (default: 15)
     *   page    = int
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status'   => 'sometimes|in:pending,approved,rejected',
            'type'     => 'sometimes|in:charge,withdraw',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page'     => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $query = WalletRequest::with([
            'user:id,first_name,last_name,email',
            'wallet:id,wallet_number,phone_number,balance',
        ])->orderByDesc('created_at');

        $status = $request->get('status', 'pending');
        $query->where('status', $status);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $paginator = $query->paginate(
            (int) $request->get('per_page', 15),
            ['*'],
            'page',
            (int) $request->get('page', 1)
        );

        // Counts for tab badges
        $counts = [
            'pending'  => WalletRequest::where('status', 'pending')->count(),
            'approved' => WalletRequest::where('status', 'approved')->count(),
            'rejected' => WalletRequest::where('status', 'rejected')->count(),
        ];

        return response()->json([
            'status' => 'success',
            'data'   => $paginator->getCollection()
                ->map(fn($r) => $this->formatRequest($r))
                ->values(),
            'meta'   => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'counts' => $counts,
        ]);
    }

    // ── POST /api/admin/wallet/requests/{id}/approve ──────────────────────────

    /**
     * Approve a pending request.
     *
     * Charge  → adds  amount to user's wallet balance.
     * Withdraw → deducts amount from user's wallet balance (re-checks balance).
     *
     * Both operations are wrapped in a DB transaction so the balance
     * update and the request status change are atomic.
     */
    public function approve(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'admin_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $walletRequest = WalletRequest::with('wallet')->findOrFail($id);

            if (!$walletRequest->isPending()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "This request has already been {$walletRequest->status}.",
                ], 422);
            }

            DB::transaction(function () use ($walletRequest, $request) {
                $wallet = Wallet::lockForUpdate()->findOrFail($walletRequest->wallet_id);
                $amount = (float) $walletRequest->amount;

                if ($walletRequest->isWithdraw()) {
                    // Re-check balance at approval time
                    if ($amount > (float) $wallet->balance) {
                        throw new \DomainException(
                            "Insufficient wallet balance ({$wallet->balance} SYP) to process withdrawal of {$amount} SYP."
                        );
                    }
                    $previousBalance = (float) $wallet->balance;
                    $newBalance      = $previousBalance - $amount;
                    $wallet->balance = $newBalance;
                    $transactionType = 'withdrawal';
                    $description     = "Withdrawal processed by admin";
                } else {
                    // Charge: add to balance
                    $previousBalance = (float) $wallet->balance;
                    $newBalance      = $previousBalance + $amount;
                    $wallet->balance = $newBalance;
                    $transactionType = 'admin_charge';
                    $description     = "Balance topped up by admin";
                }

                $wallet->save();

                // Record the wallet transaction for audit trail
                WalletTransaction::create([
                    'wallet_id'        => $wallet->id,
                    'user_id'          => $walletRequest->user_id,
                    'type'             => $transactionType,
                    'amount'           => $amount,
                    'previous_balance' => $previousBalance,
                    'new_balance'      => $newBalance,
                    'description'      => $description,
                    'transaction_id'   => 'WR-' . $walletRequest->id . '-' . now()->timestamp,
                    'status'           => 'completed',
                    'reference'        => 'wallet_request:' . $walletRequest->id,
                ]);

                // Mark request as approved
                $walletRequest->update([
                    'status'       => 'approved',
                    'admin_notes'  => $request->input('admin_notes'),
                    'processed_by' => $request->user()?->id,
                    'processed_at' => now(),
                ]);

                Log::info('Wallet request approved', [
                    'request_id'       => $walletRequest->id,
                    'type'             => $walletRequest->type,
                    'amount'           => $amount,
                    'user_id'          => $walletRequest->user_id,
                    'previous_balance' => $previousBalance,
                    'new_balance'      => $newBalance,
                ]);
            });

            $walletRequest->refresh()->load('wallet');

            // Notify user
            try {
                $label = $walletRequest->isCharge() ? 'Wallet Charge' : 'Wallet Withdrawal';
                $msg   = $walletRequest->isCharge()
                    ? "Your wallet charge request of {$walletRequest->amount} SYP has been approved."
                    : "Your withdrawal request of {$walletRequest->amount} SYP has been approved.";

                app(\App\Services\NotificationService::class)->createNotification(
                    $walletRequest->user,
                    'wallet_request_approved',
                    $label . ' - موافق',
                    $msg,
                    ['wallet_request_id' => $walletRequest->id],
                    'high',
                    'system'
                );
            } catch (\Throwable) {
                // Notification failure must never block the approval
            }

            return response()->json([
                'status'  => 'success',
                'message' => ucfirst($walletRequest->type) . ' request approved. Wallet balance updated.',
                'data'    => $this->formatRequest($walletRequest),
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Request not found.'], 404);
        } catch (\DomainException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Wallet request approval failed', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->serverError();
        }
    }

    // ── POST /api/admin/wallet/requests/{id}/reject ───────────────────────────

    /**
     * Reject a pending request — no balance change occurs.
     *
     * Body: { "admin_notes": "reason..." }   (recommended)
     */
    public function reject(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'admin_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $walletRequest = WalletRequest::findOrFail($id);

            if (!$walletRequest->isPending()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "This request has already been {$walletRequest->status}.",
                ], 422);
            }

            $walletRequest->update([
                'status'       => 'rejected',
                'admin_notes'  => $request->input('admin_notes'),
                'processed_by' => $request->user()?->id,
                'processed_at' => now(),
            ]);

            Log::info('Wallet request rejected', [
                'request_id' => $walletRequest->id,
                'type'       => $walletRequest->type,
                'amount'     => $walletRequest->amount,
                'user_id'    => $walletRequest->user_id,
            ]);

            // Notify user
            try {
                $label = $walletRequest->isCharge() ? 'Wallet Charge' : 'Wallet Withdrawal';
                $reason = $request->input('admin_notes') ? ' Reason: ' . $request->input('admin_notes') : '';

                app(\App\Services\NotificationService::class)->createNotification(
                    $walletRequest->user,
                    'wallet_request_rejected',
                    $label . ' - Rejected',
                    "Your request for {$walletRequest->amount} SYP has been rejected.{$reason}",
                    ['wallet_request_id' => $walletRequest->id],
                    'normal',
                    'system'
                );
            } catch (\Throwable) {
                // Notification failure must never block the rejection
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Request rejected.',
                'data'    => $this->formatRequest($walletRequest),
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Request not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Wallet request rejection failed', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->serverError();
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function formatRequest(WalletRequest $r): array
    {
        return [
            'id'             => $r->id,
            'type'           => $r->type,
            'amount'         => (float) $r->amount,
            'status'         => $r->status,
            'user_notes'     => $r->user_notes,
            'admin_notes'    => $r->admin_notes,
            'processed_at'   => $r->processed_at?->toIso8601String(),
            'created_at'     => $r->created_at->toIso8601String(),
            'user' => $r->user ? [
                'id'    => $r->user->id,
                'name'  => trim("{$r->user->first_name} {$r->user->last_name}"),
                'email' => $r->user->email,
            ] : null,
            'wallet' => $r->wallet ? [
                'id'             => $r->wallet->id,
                'wallet_number'  => $r->wallet->wallet_number,
                'phone_number'   => $r->wallet->phone_number,
                'current_balance'=> (float) $r->wallet->balance,
            ] : null,
        ];
    }

    private function serverError(): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => 'An unexpected error occurred. Please try again.',
        ], 500);
    }
}
