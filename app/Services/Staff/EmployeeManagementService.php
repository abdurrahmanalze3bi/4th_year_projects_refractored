<?php

namespace App\Services\Staff;

use App\Enums\StaffRole;
use App\Interfaces\EmployeeRepositoryInterface;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * EmployeeManagementService
 *
 * Handles employee CRUD with role-based authorization enforcement.
 *
 * Authorization matrix:
 *   system_admin → can create / manage admin + support_agent
 *   admin        → can create / manage support_agent only
 *   support_agent→ no management permissions
 *
 * The system_admin role can only be created via the seeder / artisan command.
 * This prevents privilege escalation through the API.
 */
final class EmployeeManagementService
{
    public function __construct(
        private readonly EmployeeRepositoryInterface $employeeRepository,
        private readonly StaffJwtService             $jwtService,
        private readonly EmployeeAuthService         $authService,
    ) {}

    // ── Create ────────────────────────────────────────────────────────────────

    /**
     * Create a new employee.
     *
     * @throws \DomainException  if the creator lacks permission to assign the role.
     * @throws \RuntimeException if the username/email is already taken.
     */
    public function create(array $data, Employee $creator): Employee
    {
        $targetRole = StaffRole::from($data['role']);

        $this->assertCanCreateRole($creator, $targetRole);
        $this->assertUsernameAvailable($data['username']);

        if (!empty($data['email'])) {
            $this->assertEmailAvailable($data['email']);
        }

        $employee = $this->employeeRepository->create([
            'username'     => $data['username'],
            'email'        => $data['email'] ?? null,
            'password'     => Hash::make($data['password']),
            'first_name'   => $data['first_name'],
            'last_name'    => $data['last_name'],
            'role'         => $targetRole->value,
            'is_active'    => true,
            'created_by'   => $creator->id,
            'token_version'=> 0,
        ]);

        Log::info('Employee created', [
            'new_employee_id' => $employee->id,
            'role'            => $employee->role->value,
            'created_by'      => $creator->id,
        ]);

        return $employee;
    }

    // ── Update ────────────────────────────────────────────────────────────────

    /**
     * Update an employee's non-sensitive fields.
     *
     * @throws \DomainException if the updater lacks authority over the target.
     */
    public function update(int $id, array $data, Employee $updater): Employee
    {
        $target = $this->findOrFail($id);

        $this->assertCanManage($updater, $target);

        // Strip fields the caller is not allowed to change via this method.
        $allowed = array_intersect_key($data, array_flip([
            'first_name', 'last_name', 'email',
        ]));

        if (!empty($allowed['email'])) {
            $this->assertEmailAvailable($allowed['email'], excludeId: $id);
        }

        return $this->employeeRepository->update($id, $allowed);
    }

    // ── Toggle active status ──────────────────────────────────────────────────

    /**
     * @throws \DomainException
     */
    public function toggleActive(int $id, Employee $updater): Employee
    {
        $target = $this->findOrFail($id);
        $this->assertCanManage($updater, $target);

        $newStatus = !$target->is_active;

        $employee = $this->employeeRepository->update($id, ['is_active' => $newStatus]);

        // Revoke all tokens when deactivating.
        if (!$newStatus) {
            $this->jwtService->revokeAllTokens($id);
        }

        Log::info('Employee active status toggled', [
            'employee_id' => $id,
            'is_active'   => $newStatus,
            'changed_by'  => $updater->id,
        ]);

        return $employee;
    }

    // ── Reset password ────────────────────────────────────────────────────────

    /**
     * @throws \DomainException
     */
    public function resetPassword(int $id, string $newPassword, Employee $updater): void
    {
        $target = $this->findOrFail($id);
        $this->assertCanManage($updater, $target);

        $this->employeeRepository->update($id, [
            'password' => Hash::make($newPassword),
        ]);

        // Invalidate all active sessions for the target employee.
        $this->jwtService->revokeAllTokens($id);

        Log::info('Employee password reset', [
            'employee_id' => $id,
            'reset_by'    => $updater->id,
        ]);
    }

    // ── List ──────────────────────────────────────────────────────────────────

    public function list(Employee $requestingEmployee): Collection
    {
        return $requestingEmployee->isSystemAdmin()
            ? $this->employeeRepository->listAll()
            : $this->employeeRepository->listManageableBy($requestingEmployee);
    }

    // ── Get one ───────────────────────────────────────────────────────────────

    /**
     * @throws \DomainException if requester can't see this employee.
     */
    public function getById(int $id, Employee $requester): Employee
    {
        $target = $this->findOrFail($id);

        // system_admin can view anyone; others only view those they can manage.
        if (!$requester->isSystemAdmin() && !$requester->canManage($target)) {
            throw new \DomainException('You do not have permission to view this employee.');
        }

        return $target->load('creator:id,username,first_name,last_name');
    }

    // ── Format ────────────────────────────────────────────────────────────────

    public function formatEmployee(Employee $employee): array
    {
        return [
            'id'           => $employee->id,
            'username'     => $employee->username,
            'email'        => $employee->email,
            'full_name'    => $employee->fullName(),
            'first_name'   => $employee->first_name,
            'last_name'    => $employee->last_name,
            'role'         => $employee->role->value,
            'role_label'   => $employee->role->label(),
            'is_active'    => $employee->is_active,
            'created_by'   => $employee->creator ? [
                'id'       => $employee->creator->id,
                'username' => $employee->creator->username,
                'name'     => $employee->creator->fullName(),
            ] : null,
            'last_login_at'=> $employee->last_login_at?->toIso8601String(),
            'created_at'   => $employee->created_at->toIso8601String(),
        ];
    }

    // ── Private authorization guards ──────────────────────────────────────────

    /**
     * @throws \DomainException
     */
    private function assertCanManage(Employee $manager, Employee $target): void
    {
        if (!$manager->canManage($target)) {
            throw new \DomainException(
                "Your role ({$manager->role->label()}) does not have authority over " .
                "this employee's role ({$target->role->label()})."
            );
        }
    }

    /**
     * @throws \DomainException
     */
    private function assertCanCreateRole(Employee $creator, StaffRole $targetRole): void
    {
        // Prevent anyone from creating a system_admin through the API.
        if ($targetRole === StaffRole::SYSTEM_ADMIN) {
            throw new \DomainException(
                'System administrators can only be created via the artisan command.'
            );
        }

        if (!$creator->role->canManage($targetRole)) {
            throw new \DomainException(
                "Your role ({$creator->role->label()}) cannot create a {$targetRole->label()}."
            );
        }
    }

    private function assertUsernameAvailable(string $username): void
    {
        if ($this->employeeRepository->findByUsername($username)) {
            throw new \RuntimeException("Username '{$username}' is already taken.");
        }
    }

    private function assertEmailAvailable(string $email, ?int $excludeId = null): void
    {
        $existing = $this->employeeRepository->findByEmail($email);

        if ($existing && $existing->id !== $excludeId) {
            throw new \RuntimeException("Email '{$email}' is already taken.");
        }
    }

    private function findOrFail(int $id): Employee
    {
        $employee = $this->employeeRepository->findById($id);

        if (!$employee) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                "Employee with ID {$id} not found."
            );
        }

        return $employee;
    }
}
