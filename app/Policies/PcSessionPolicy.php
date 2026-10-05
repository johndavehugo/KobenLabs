<?php

namespace App\Policies;

use App\Models\PcSession;
use App\Models\User;

/**
 * PC occupancy history.
 *
 * Users may see and close their own session; staff see everything, which is
 * what the monitoring view needs in order to map occupancy back to a person.
 */
class PcSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageLab();
    }

    public function view(User $user, PcSession $session): bool
    {
        return $user->canManageLab() || $session->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Closing a session. Users may end their own; staff may end anyone's,
     * recording themselves as the terminating party.
     */
    public function update(User $user, PcSession $session): bool
    {
        return $user->canManageLab() || $session->user_id === $user->id;
    }

    public function delete(User $user, PcSession $session): bool
    {
        return $user->canManageLab();
    }
}
