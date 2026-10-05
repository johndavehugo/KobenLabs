<?php

namespace App\Policies;

use App\Models\StoredFile;
use App\Models\User;

/**
 * Personal file drives.
 *
 * A student reaches their own drive and nothing else. Staff reach every drive,
 * which preserves the legacy behaviour where `checkOwnership()` granted
 * teachers and administrators blanket access — needed for support and for
 * locating a student's work when they cannot find it.
 *
 * Ownership is anchored on `user_id`, which is why the legacy design let
 * teachers browse any drive via a `?user_id=` query parameter. Keep that
 * behind this policy rather than behind a query string.
 */
class StoredFilePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StoredFile $file): bool
    {
        return $user->canManageLab() || $file->user_id === $user->id;
    }

    /**
     * Rows are always created for the authenticated user — never for somebody
     * else, regardless of role.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, StoredFile $file): bool
    {
        return $user->canManageLab() || $file->user_id === $user->id;
    }

    public function delete(User $user, StoredFile $file): bool
    {
        return $user->canManageLab() || $file->user_id === $user->id;
    }

    /**
     * Renaming or moving within somebody else's drive.
     */
    public function reorganise(User $user, StoredFile $file): bool
    {
        return $file->user_id === $user->id;
    }
}
