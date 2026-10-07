<?php

namespace App\Policies;

use App\Models\Pc;
use App\Models\User;
use App\Services\ScreenAccessService;

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

    /**
     * Issuing or withdrawing a Lab Client credential for this machine.
     *
     * Staff only. This is what gates the Enrol client and Revoke client buttons on the
     * PC list, and it is why enrolling a machine — which is what grants it the right to
     * send screenshots to staff — is not something a student can reach.
     *
     * Added as a separate ability rather than folded into an existing one, because
     * `changeStatus` means "take this machine out of service", which is unrelated to
     * "let this machine report a screen".
     */
    public function manageClient(User $user, Pc $pc): bool
    {
        return $user->canManageLab();
    }

    /**
     * Whether this user may see this PC's screen on Live Screens.
     *
     * A new ability rather than a narrowing of view(), because view() is true for
     * everyone — students need the inventory to find a free machine, and that is the
     * only reason it is open. Screen content is a different thing entirely, and
     * widening view() to control it would take the inventory away from students.
     *
     * Delegates to ScreenAccessService so that this and the Live Screens grid resolve
     * the same rule. When they were separate, the legacy app's grid showed one set of
     * machines while the socket sent another; the filter lived in the browser and the
     * frames had already been transmitted by the time it ran.
     */
    public function viewScreen(User $user, Pc $pc): bool
    {
        return app(ScreenAccessService::class)->mayView($user, $pc);
    }
}
