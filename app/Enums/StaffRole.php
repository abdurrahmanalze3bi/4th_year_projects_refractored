<?php

namespace App\Enums;

enum StaffRole: string
{
    case SYSTEM_ADMIN   = 'system_admin';
    case ADMIN          = 'admin';
    case SUPPORT_AGENT  = 'support_agent';

    public function label(): string
    {
        return match ($this) {
            self::SYSTEM_ADMIN  => 'System Administrator',
            self::ADMIN         => 'Administrator',
            self::SUPPORT_AGENT => 'Support Agent',
        };
    }

    /**
     * Numeric hierarchy level — higher means more privileged.
     */
    public function level(): int
    {
        return match ($this) {
            self::SYSTEM_ADMIN  => 3,
            self::ADMIN         => 2,
            self::SUPPORT_AGENT => 1,
        };
    }

    /**
     * Whether this role can create/manage the given target role.
     */
    public function canManage(self $target): bool
    {
        // A role can only manage roles strictly below its own level.
        // system_admin manages admin + support_agent.
        // admin manages support_agent only.
        // support_agent manages nobody.
        return $this->level() > $target->level();
    }

    /**
     * Returns the roles this role is allowed to create.
     *
     * @return StaffRole[]
     */
    public function creatableRoles(): array
    {
        return array_filter(
            self::cases(),
            fn(self $role) => $this->canManage($role)
        );
    }
}
