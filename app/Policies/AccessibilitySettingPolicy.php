<?php

namespace App\Policies;

use App\Models\AccessibilitySetting;
use App\Models\User;

/**
 * Per-user display preferences.
 *
 * Owner-only, with no staff override. Accessibility settings describe how a
 * specific person needs the interface to render; an administrator managing
 * somebody else's text size or screen-reader preference is not a support
 * action, it is a way to disable accessibility for them.
 *
 * This closes a legacy hole: `/api/users/{id}/accessibility` had no ownership
 * check, so any authenticated user could read or write anyone else's settings.
 */
class AccessibilitySettingPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, AccessibilitySetting $settings): bool
    {
        return $settings->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, AccessibilitySetting $settings): bool
    {
        return $settings->user_id === $user->id;
    }

    public function delete(User $user, AccessibilitySetting $settings): bool
    {
        return $settings->user_id === $user->id;
    }
}
