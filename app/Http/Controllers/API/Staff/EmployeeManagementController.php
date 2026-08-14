<?php

namespace App\Http\Controllers\API\Staff;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Services\Staff\EmployeeManagementService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * EmployeeManagementController
 *
 * Thin controller. Route-level access is enforced by StaffJwtMiddleware
 * (system_admin or admin). Fine-grained authorization (who can manage whom)
 * is enforced by EmployeeManagementService.
 */
final class EmployeeManagementController extends Controller
{
    public function __construct(
        private readonly EmployeeManagementService $managementService,
    ) {}

    // ── GET /api/employees ────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $requester = $request->attributes->get('staffEmployee');

        try {
            $employees = $this->managementService->getAll($requester);

            return response()->json([
                'status' => 'success',
                'data'   => $employees->map(
                    fn($e) => $this->managementService->formatEmployee($e)
                )->values(),
            ]);
        } catch (\Exception $e) {
            Log::error('Employee list failed', ['error' => $e->getMessage()]);
            return $this->serverError();
        }
    }

    // ── POST /api/employees ───────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $allowedRoles = array_map(
            fn(StaffRole $r) => $r->value,
            $request->attributes->get('staffEmployee')->role->creatableRoles()
        );

        $validator = Validator::make($request->all(), [
            'username'   => 'required|string|min:3|max:50|alpha_dash',
            'email'      => 'nullable|email|max:255',
            'password'   => 'required|string|min:8',
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'role'       => 'required|in:' . implode(',', $allowedRoles),
        ], [
            'username.alpha_dash' => 'Username may only contain letters, numbers, dashes, and underscores.',
            'role.in'             => 'You are not permitted to assign this role.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $employee = $this->managementService->create(
                $validator->validated(),
                $request->attributes->get('staffEmployee')
            );

            return response()->json([
                'status'   => 'success',
                'message'  => 'Employee created successfully.',
                'employee' => $this->managementService->formatEmployee($employee),
            ], 201);
        } catch (\DomainException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 403);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            Log::error('Employee creation failed', ['error' => $e->getMessage()]);
            return $this->serverError();
        }
    }

    // ── GET /api/employees/{id} ───────────────────────────────────────────────

    public function show(int $id, Request $request): JsonResponse
    {
        try {
            $employee = $this->managementService->getById(
                $id,
                $request->attributes->get('staffEmployee')
            );

            return response()->json([
                'status' => 'success',
                'data'   => $this->managementService->formatEmployee($employee),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Employee not found.'], 404);
        } catch (\DomainException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 403);
        }
    }

    // ── PUT /api/employees/{id} ───────────────────────────────────────────────

    public function update(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:100',
            'last_name'  => 'sometimes|string|max:100',
            'email'      => 'sometimes|nullable|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $employee = $this->managementService->update(
                $id,
                $validator->validated(),
                $request->attributes->get('staffEmployee')
            );

            return response()->json([
                'status'   => 'success',
                'message'  => 'Employee updated successfully.',
                'employee' => $this->managementService->formatEmployee($employee),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Employee not found.'], 404);
        } catch (\DomainException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 403);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            Log::error('Employee update failed', ['error' => $e->getMessage()]);
            return $this->serverError();
        }
    }

    // ── PATCH /api/employees/{id}/toggle-active ───────────────────────────────

    public function toggleActive(int $id, Request $request): JsonResponse
    {
        try {
            $employee = $this->managementService->toggleActive(
                $id,
                $request->attributes->get('staffEmployee')
            );

            $status = $employee->is_active ? 'activated' : 'deactivated';

            return response()->json([
                'status'   => 'success',
                'message'  => "Employee {$status} successfully.",
                'employee' => $this->managementService->formatEmployee($employee),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Employee not found.'], 404);
        } catch (\DomainException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            Log::error('Toggle active failed', ['error' => $e->getMessage()]);
            return $this->serverError();
        }
    }

    // ── PATCH /api/employees/{id}/reset-password ──────────────────────────────

    public function resetPassword(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'new_password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $this->managementService->rotatePassword(
                $id,
                $request->input('new_password'),
                $request->attributes->get('staffEmployee')
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Password reset successfully. All sessions for this employee have been invalidated.',
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Employee not found.'], 404);
        } catch (\DomainException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            Log::error('Password reset failed', ['error' => $e->getMessage()]);
            return $this->serverError();
        }
    }

    // ── DELETE /api/employees/{id} ────────────────────────────────────────────

    public function destroy(int $id, Request $request): JsonResponse
    {
        try {
            $this->managementService->delete(
                $id,
                $request->attributes->get('staffEmployee')
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Employee deleted successfully.',
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Employee not found.'], 404);
        } catch (\DomainException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            Log::error('Employee deletion failed', ['error' => $e->getMessage()]);
            return $this->serverError();
        }
    }

    // ── Shared ────────────────────────────────────────────────────────────────

    private function serverError(): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => 'An unexpected error occurred. Please try again.',
        ], 500);
    }
}
