<?php

namespace App\Services\Staff;

use App\Enums\StaffRole;
use App\Models\Employee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * EmployeeManagementService
 *
 * All CRUD for Employee accounts lives here — never in the controller.
 *
 * Invariants enforced unconditionally:
 *   1. system_admin and sycash (restricted roles) can never be created,
 *      deactivated, or deleted via the API. They are seeded once at
 *      deployment by SpecialAccountSeeder. Password rotation goes through
 *      `php artisan admin:rotate-password {role}`.
 *   2. A requester can only manage roles that StaffRole::canManage() permits
 *      for their own role tier.
 */
class EmployeeManagementService
{
    public function __construct(
        private readonly StaffJwtService $staffJwtService,
    ) {}

    // =========================================================================
    // READ
    // =========================================================================

    /**
     * All employees visible to the requester.
     *
     * system_admin → everyone
     * admin        → admin + support_agent  (restricted rows hidden)
     * others       → caller should gate before reaching here
     */
    public function getAll(Employee $requester): Collection
    {
        return Employee::orderBy('role')
            ->orderBy('username')
            ->get()
            ->filter(fn (Employee $emp) =>
                $emp->id === $requester->id
                || $requester->role->canManage($emp->role)
            )
            ->values();
    }

    /**
     * Single employee — enforces visibility by role tier.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \DomainException
     */
    public function getById(int $id, Employee $requester): Employee
    {
        $employee = Employee::findOrFail($id);

        if (
            $employee->id !== $requester->id
            && !$requester->role->canManage($employee->role)
        ) {
            throw new \DomainException(
                "Your role ({$requester->role->label()}) cannot view this account."
            );
        }

        return $employee;
    }

    // =========================================================================
    // CREATE
    // =========================================================================

    /**
     * Create a new employee account.
     *
     * Guards (checked in order before any DB write):
     *   1. Target role must not be restricted (system_admin / sycash).
     *   2. Requester's role must be permitted to manage the target role.
     *   3. Username must be unique across the employees table.
     *   4. Email (optional) must be unique across the employees table.
     *
     * @throws \DomainException
     */
    public function create(array $data, Employee $requester): Employee
    {
        $role = StaffRole::from($data['role']);

        // ── Guard 1: restricted roles can never be created via the API ────────
        if ($role->isRestricted()) {
            throw new \DomainException(
                "The '{$role->label()}' account cannot be created via the API. " .
                "It is seeded once at deployment. To rotate the password run: " .
                "php artisan admin:rotate-password {$role->value}"
            );
        }

        // ── Guard 2: requester must be permitted to create this role ──────────
        if (!$requester->role->canManage($role)) {
            throw new \DomainException(
                "Your role ({$requester->role->label()}) is not permitted to " .
                "create a '{$role->label()}' account."
            );
        }

        // ── Guard 3: unique username ──────────────────────────────────────────
        if (Employee::where('username', $data['username'])->exists()) {
            throw new \DomainException("Username '{$data['username']}' is already taken.");
        }

        // ── Guard 4: unique email (field is optional) ─────────────────────────
        if (
            !empty($data['email'])
            && Employee::where('email', $data['email'])->exists()
        ) {
            throw new \DomainException("Email '{$data['email']}' is already in use.");
        }

        return Employee::create([
            'username'   => $data['username'],
            'email'      => $data['email'] ?? null,
            'password'   => Hash::make($data['password']),
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'role'       => $role->value,
            'is_active'  => true,
        ]);
    }

    // =========================================================================
    // UPDATE (non-password fields)
    // =========================================================================

    /**
     * Update mutable profile fields on an employee.
     * Password changes are handled by rotatePassword() — never here.
     *
     * @throws \DomainException
     */
    public function update(int $id, array $data, Employee $requester): Employee
    {
        $employee = $this->getById($id, $requester);

        // Restricted accounts are immutable via the API
        if ($employee->role->isRestricted()) {
            throw new \DomainException(
                "The '{$employee->role->label()}' account cannot be modified via the API."
            );
        }

        // Role changes must target a non-restricted role the requester can manage
        if (isset($data['role'])) {
            $newRole = StaffRole::from($data['role']);

            if ($newRole->isRestricted()) {
                throw new \DomainException(
                    "Cannot assign the restricted role '{$newRole->label()}' via the API."
                );
            }

            if (!$requester->role->canManage($newRole)) {
                throw new \DomainException(
                    "Your role ({$requester->role->label()}) cannot assign '{$newRole->label()}'."
                );
            }

            $data['role'] = $newRole->value;
        }

        // Username uniqueness when changing
        if (
            isset($data['username'])
            && $data['username'] !== $employee->username
            && Employee::where('username', $data['username'])->exists()
        ) {
            throw new \DomainException("Username '{$data['username']}' is already taken.");
        }

        // Email uniqueness when changing
        if (
            isset($data['email'])
            && $data['email'] !== $employee->email
            && Employee::where('email', $data['email'])->exists()
        ) {
            throw new \DomainException("Email '{$data['email']}' is already in use.");
        }

        $employee->fill(
            array_intersect_key($data, array_flip([
                'username', 'email', 'first_name', 'last_name', 'role',
            ]))
        );
        $employee->save();

        return $employee;
    }

    // =========================================================================
    // PASSWORD ROTATION
    // =========================================================================

    /**
     * Rotate an employee's password and invalidate all active sessions.
     *
     * Restricted accounts (system_admin / sycash) must use the Artisan command
     * instead — this method throws for them so the API can never touch them.
     *
     * @throws \DomainException
     */
    public function rotatePassword(int $id, string $newPassword, Employee $requester): Employee
    {
        $employee = $this->getById($id, $requester);

        if ($employee->role->isRestricted()) {
            throw new \DomainException(
                "The '{$employee->role->label()}' password must be rotated via Artisan: " .
                "php artisan admin:rotate-password {$employee->role->value}"
            );
        }

        $employee->password      = Hash::make($newPassword);
        $employee->token_version = ($employee->token_version ?? 0) + 1;
        $employee->save();

        $this->staffJwtService->revokeAllTokens($employee->id);

        return $employee;
    }

    // =========================================================================
    // TOGGLE ACTIVE
    // =========================================================================

    /**
     * Flip is_active on an employee account.
     * Deactivating immediately revokes all tokens so in-flight requests fail.
     *
     * @throws \DomainException
     */
    public function toggleActive(int $id, Employee $requester): Employee
    {
        $employee = $this->getById($id, $requester);

        // Restricted accounts cannot be deactivated via the API
        if ($employee->role->isRestricted()) {
            throw new \DomainException(
                "The '{$employee->role->label()}' account cannot be deactivated via the API."
            );
        }

        $employee->is_active = !$employee->is_active;
        $employee->save();

        if (!$employee->is_active) {
            $this->staffJwtService->revokeAllTokens($employee->id);
        }

        return $employee;
    }

    // =========================================================================
    // DELETE
    // =========================================================================

    /**
     * Permanently delete an employee account.
     * Tokens are revoked first so any in-flight requests are rejected cleanly.
     *
     * @throws \DomainException
     */
    public function delete(int $id, Employee $requester): void
    {
        $employee = $this->getById($id, $requester);

        // Restricted accounts cannot be deleted via the API
        if ($employee->role->isRestricted()) {
            throw new \DomainException(
                "The '{$employee->role->label()}' account cannot be deleted via the API."
            );
        }

        if (!$requester->role->canManage($employee->role)) {
            throw new \DomainException(
                "Your role ({$requester->role->label()}) cannot delete " .
                "a '{$employee->role->label()}' account."
            );
        }

        $this->staffJwtService->revokeAllTokens($employee->id);
        $employee->delete();
    }
}
