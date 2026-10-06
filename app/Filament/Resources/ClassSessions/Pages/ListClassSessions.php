<?php

namespace App\Filament\Resources\ClassSessions\Pages;

use App\Filament\Resources\ClassSessions\ClassSessionResource;
use App\Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;
use Livewire\Attributes\On;

class ListClassSessions extends ListRecords
{
    protected static string $resource = ClassSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Starting or ending a session changes which row carries the Running badge, and a
     * student joining changes the attendance count in the same table. Both arrive on
     * the shared class-sessions channel, so one re-render covers them.
     */
    #[On('koben:class-session-started')]
    #[On('koben:class-session-ended')]
    public function refreshClassSessions(): void
    {
        $this->dispatch('$refresh');
    }
}
