<?php

namespace App\Policies;

use App\Models\User;
use App\Models\YearSection;

/**
 * Academic year and section catalogue.
 *
 * Everyone can read the catalogue — students need it to see their own section,
 * and staff need it to populate every dropdown. Only staff may change it.
 */
class YearSectionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, YearSection $yearSection): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canManageLab();
    }

    public function update(User $user, YearSection $yearSection): bool
    {
        return $user->canManageLab();
    }

    /**
     * With `year_section_id` as a nullable foreign key, deleting a section sets
     * it to null on its students rather than cascading. Callers should confirm
     * the section is empty first, or expect the students to become unassigned.
     */
    public function delete(User $user, YearSection $yearSection): bool
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
}
