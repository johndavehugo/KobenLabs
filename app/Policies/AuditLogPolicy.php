<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Audit trail.
 *
 * Staff may read; administrators may read through the trait's bypass. There are
 * deliberately no create, update or delete abilities — an audit log that can be
 * edited or truncated is not an audit log. Rows are written by
 * AuditLog::record() and by whatever observer calls it.
 *
 * Reading is restricted to staff because the log captures IP addresses and user
 * agents.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageLab();
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $user->canManageLab();
    }
}
