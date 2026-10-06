<?php

namespace App\Filament\Resources\ClassSessions\Pages;

use App\Filament\Resources\ClassSessions\ClassSessionResource;
use App\Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

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

    /**
     * The roster grows as students join. `StudentJoinedClass` is broadcast to this
     * channel, so the attendance relation manager re-queries without the teacher
     * reloading - which is what the legacy page did by polling.
     */
    #[On('koben:class-session-started')]
    public function refreshRoster(): void
    {
        $this->dispatch('$refresh');
    }
}
