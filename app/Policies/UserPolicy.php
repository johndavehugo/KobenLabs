<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * User account management.
 *
 * Written explicitly, with no blanket administrator bypass, because
 * administrators must still be prevented from deleting their own account —
 * and `before()` has no access to the target model, so that check has to live
 * in the ability method.
 */
class UserPolicy
{
    /**
     * Administrators bypass every ability except those that would let them
     * lock themselves out: self-deletion, self-suspension and self password
     * reset. Returning null lets Laravel fall through to the ability methods.
     */
    public function before(User $user, string $ability): ?bool
    {
        $selfService = ['delete', 'changeStatus', 'resetPassword'];

        return $user->role === UserRole::Admin && ! in_array($ability, $selfService, true)
            ? true
            : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->canManageLab();
    }

    /**
     * Staff may view any account; everyone may view their own.
     */
    public function view(User $user, User $account): bool
    {
        return $user->canManageLab() || $user->is($account);
    }

    public function create(User $user): bool
    {
        return $user->canManageLab();
    }

    public function update(User $user, User $account): bool
    {
        return $user->canManageLab();
    }

    /**
     * Staff may delete other accounts. Nobody may delete their own — locking
     * yourself out of the only admin account is unrecoverable.
     */
    public function delete(User $user, User $account): bool
    {
        return $user->canManageLab() && ! $user->is($account);
    }

    /**
     * Suspending, reactivating or changing a role.
     */
    public function changeStatus(User $user, User $account): bool
    {
        return $user->canManageLab() && ! $user->is($account);
    }

    /**
     * Resetting another account's password.
     */
    public function resetPassword(User $user, User $account): bool
    {
        return $user->canManageLab() && ! $user->is($account);
    }

    /**
     * Gates the bulk delete action on the table. Mirrors `delete`, because a
     * bulk delete that skipped the self-check could remove the signed-in
     * administrator along with everyone else.
     */
    public function deleteAny(User $user): bool
    {
        return $user->canManageLab();
    }
}
