<?php

namespace App\Http\Controllers\API\Staff;

use App\Enums\ComplaintStatus;
use App\Http\Controllers\Controller;
use App\Interfaces\VerificationRepositoryInterface;
use App\Models\User;
use App\Services\Staff\StaffComplaintService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * StaffAdminController
 *
 * Admin + System Admin only routes.
 * Protected by: middleware('staff:admin,system_admin')
 *
 * ── UC-ADM-10 : Review Verification Requests ──────────────────────────────
 *   GET   /api/staff/verifications/pending       → pendingVerifications()
 *
 * ── UC-ADM-11 : Approve / Reject Verification ─────────────────────────────
 *   POST  /api/staff/verifications/{userId}/approve  → approveVerification()
 *   POST  /api/staff/verifications/{userId}/reject   → rejectVerification()
 *
 * ── Escalated Complaints (admin handles after agent escalates) ─────────────
 *   GET   /api/staff/escalated-complaints            → escalatedComplaints()
 *   PATCH /api/staff/escalated-complaints/{id}/resolve → resolveEscalated()
 */
final class StaffAdminController extends Controller
{
    public function __construct(
        private readonly StaffComplaintService         $complaintService,
        private readonly VerificationRepositoryInterface $verificationRepo,
    ) {}

    // =========================================================================
    // UC-ADM-10 — PENDING VERIFICATIONS LIST
    // =========================================================================

    /**
     * GET /api/staff/verifications/pending
     *
     * Returns all users whose verification_status = 'pending',
     * along with their uploaded documents, so the admin can review them.
     */
    public function pendingVerifications(): JsonResponse
    {
        try {
            $pending = User::with(['photos', 'profile'])
                ->where('verification_status', 'pending')
                ->orderByDesc('updated_at')
                ->get()
                ->map(function (User $u) {
                    $docTypes = $u->photos->pluck('type')->toArray();
                    $isDriver = in_array('license', $docTypes)
                        || in_array('mechanic_card', $docTypes);

                    return [
                        'user_id'      => $u->id,
                        'name'         => trim("{$u->first_name} {$u->last_name}"),
                        'email'        => $u->email,
                        'gender'       => $u->gender,
                        'address'      => $u->address,
                        'type'         => $isDriver ? 'driver' : 'passenger',
                        'profile_photo'=> $u->profile?->profile_photo
                            ? asset('storage/' . $u->profile->profile_photo)
                            : null,
                        'documents'    => $u->photos->map(fn ($p) => [
                            'type' => $p->type,
                            'url'  => asset('storage/' . $p->path),
                        ])->values(),
                        'submitted_at' => $u->updated_at->toIso8601String(),
                    ];
                });

            return response()->json([
                'status' => 'success',
                'total'  => $pending->count(),
                'data'   => $pending,
            ]);
        } catch (\Exception $e) {
            Log::error('StaffAdmin: pendingVerifications failed', ['error' => $e->getMessage()]);
            return $this->serverError();
        }
    }

    // =========================================================================
    // UC-ADM-11 — APPROVE VERIFICATION
    // =========================================================================

    /**
     * POST /api/staff/verifications/{userId}/approve
     *
     * Approves the user's verification request.
     * - If the user uploaded driver docs → verifyDriver()
     * - Otherwise                        → verifyPassenger()
     */
    public function approveVerification(int $userId): JsonResponse
    {
        try {
            $user     = User::with('photos')->findOrFail($userId);
            $docTypes = $user->photos->pluck('type')->toArray();
            $isDriver = in_array('license', $docTypes)
                || in_array('mechanic_card', $docTypes);

            $verified = $isDriver
                ? $this->verificationRepo->verifyDriver($userId)
                : $this->verificationRepo->verifyPassenger($userId);

            // Fire the broadcast event so the user's app updates in real time
            event(new \App\Events\UserVerified(
                $verified,
                $isDriver ? 'driver' : 'passenger'
            ));

            return response()->json([
                'status'  => 'success',
                'message' => ($isDriver ? 'Driver' : 'Passenger') . ' verification approved.',
                'data'    => [
                    'user_id'             => $verified->id,
                    'verification_status' => $verified->verification_status,
                    'is_verified_driver'  => (bool) $verified->is_verified_driver,
                    'is_verified_passenger' => (bool) $verified->is_verified_passenger,
                ],
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        } catch (\Exception $e) {
            Log::error('StaffAdmin: approveVerification failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    // =========================================================================
    // UC-ADM-11 — REJECT VERIFICATION
    // =========================================================================

    /**
     * POST /api/staff/verifications/{userId}/reject
     *
     * Body: { "reason": "..." }   (optional but recommended for UX)
     */
    public function rejectVerification(int $userId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $user = User::findOrFail($userId);

            if ($user->verification_status !== 'pending') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'User does not have a pending verification request.',
                ], 422);
            }

            $user->update([
                'verification_status'   => 'rejected',
                'is_verified_passenger' => false,
                'is_verified_driver'    => false,
            ]);

            // Notify the user
            try {
                app(\App\Services\NotificationService::class)->createNotification(
                    $user,
                    'verification_rejected',
                    'طلب التوثيق مرفوض',
                    'تم رفض طلب توثيق حسابك.' . ($request->input('reason') ? ' السبب: ' . $request->input('reason') : ' يمكنك إعادة التقديم بعد تصحيح البيانات.'),
                    ['user_id' => $user->id],
                    'high',
                    'system'
                );
            } catch (\Throwable) {
                // Notification failure must never block the rejection
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Verification rejected. User has been notified.',
                'data'    => [
                    'user_id'             => $user->id,
                    'verification_status' => $user->verification_status,
                ],
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        } catch (\Exception $e) {
            Log::error('StaffAdmin: rejectVerification failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return $this->serverError();
        }
    }

    // =========================================================================
    // ESCALATED COMPLAINTS — LIST
    // =========================================================================

    /**
     * GET /api/staff/escalated-complaints
     *
     * Query params:
     *   status   = escalated | resolved | closed  (default: escalated)
     *   type     = trip_safety | driver_behavior | ...
     *   date     = last_7_days | last_30_days
     *   per_page = 1-50  (default 15)
     *   page     = int
     */
    public function escalatedComplaints(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status'   => 'sometimes|in:escalated,resolved,closed',
            'type'     => 'sometimes|in:trip_safety,driver_behavior,passenger_behavior,ride_cancellation,financial_issue,account_issue,technical_issue,other',
            'date'     => 'sometimes|in:last_7_days,last_30_days',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page'     => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $paginator = $this->complaintService->listEscalated(
            status:  $request->get('status'),
            type:    $request->get('type'),
            date:    $request->get('date'),
            perPage: (int) $request->get('per_page', 15),
            page:    (int) $request->get('page', 1),
        );

        return response()->json([
            'status' => 'success',
            'data'   => $paginator->getCollection()
                ->map(fn ($c) => $this->complaintService->format($c))
                ->values(),
            'meta'   => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'counts' => $this->escalatedStatusCounts(),
        ]);
    }

    // =========================================================================
    // ESCALATED COMPLAINTS — RESOLVE
    // =========================================================================

    /**
     * PATCH /api/staff/escalated-complaints/{id}/resolve
     *
     * Body:
     *   resolution_notes  string   required, min 10
     *   status            string   required, in: resolved, closed
     */
    public function resolveEscalated(int $complaintId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'resolution_notes' => 'required|string|min:10|max:3000',
            'status'           => 'required|in:resolved,closed',
        ], [
            'resolution_notes.required' => 'A resolution message is required.',
            'resolution_notes.min'      => 'Resolution must be at least 10 characters.',
            'status.in'                 => 'Status must be resolved or closed.',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $admin     = $request->attributes->get('staffEmployee');
            $newStatus = ComplaintStatus::from($request->input('status'));

            $complaint = $this->complaintService->resolveEscalated(
                complaintId:     $complaintId,
                resolutionNotes: $request->input('resolution_notes'),
                newStatus:       $newStatus,
                admin:           $admin,
            );

            return response()->json([
                'status'  => 'success',
                'message' => "Escalated complaint marked as {$newStatus->label()} and user has been notified.",
                'data'    => $this->complaintService->format($complaint),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Complaint not found.'], 404);
        } catch (\DomainException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('StaffAdmin: resolveEscalated failed', [
                'complaint_id' => $complaintId,
                'error'        => $e->getMessage(),
            ]);
            return $this->serverError();
        }
    }

    // =========================================================================
    // SHARED
    // =========================================================================

    private function escalatedStatusCounts(): array
    {
        return [
            'escalated' => \App\Models\Complaint::where('status', ComplaintStatus::ESCALATED->value)->count(),
            'resolved'  => \App\Models\Complaint::where('status', ComplaintStatus::RESOLVED->value)->count(),
            'closed'    => \App\Models\Complaint::where('status', ComplaintStatus::CLOSED->value)->count(),
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
