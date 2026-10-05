<?php

namespace App\Policies;

use App\Models\Pc;
use App\Models\User;

/**
 * Lab PC inventory.
 *
 * Everyone can read the inventory — the class-session monitor shows students
 * which machine is free. Only staff may change it.
 *
 * This closes a legacy hole: `PCController::store/update/destroy` contained no
 * role check whatsoever, so any authenticated student could create, edit or
 * delete PCs.
 */
class PcPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Pc $pc): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canManageLab();
    }

    public function update(User $user, Pc $pc): bool
    {
        return $user->canManageLab();
    }

    public function delete(User $user, Pc $pc): bool
    {
        return $user->canManageLab();
    }

    /**
     * Gates the bulk delete action on the table.
     */
    public function deleteAny(User $user): bool
    {
        return $user->canManageLab();
    }

    /**
     * Taking a machine out of service, or returning it.
     */
    public function changeStatus(User $user, Pc $pc): bool
    {
        return $user->canManageLab();
    }
}
