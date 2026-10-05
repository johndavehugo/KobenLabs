<?php

namespace App\Filament\Resources\ClassSessions\Pages;

use App\Filament\Resources\ClassSessions\ClassSessionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewClassSession extends ViewRecord
{
    protected static string $resource = ClassSessionResource::class;

    protected static ?string $title = 'Class session';

    /**
     * Intentionally empty. A session is read-only once started: ending it
     * happens from the list page, and letting anyone edit a running session
     * would allow changing the schedule out from under the roster.
     */
    public function getHeaderActions(): array
    {
        return [];
    }

    /**
     * This page exists to show the roster, which is more sensitive than the
     * session header. Guarded here as well as on the relation manager, so the
     * refusal does not depend on a relation manager being rendered at all.
     */
    public function mount(string|int $record): void
    {
        parent::mount($record);

        abort_unless(
            auth()->user()?->can('viewAttendance', $this->getRecord()) ?? false,
            403,
        );
    }
}
