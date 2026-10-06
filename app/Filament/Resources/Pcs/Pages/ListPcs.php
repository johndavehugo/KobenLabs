<?php

namespace App\Filament\Resources\Pcs\Pages;

use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Pcs\PcResource;
use Filament\Actions\CreateAction;
use Livewire\Attributes\On;

class ListPcs extends ListRecords
{
    protected static string $resource = PcResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * The status column is the only thing on this page that changes on its own, so a
     * status broadcast re-renders the table rather than dispatching anything wider.
     */
    #[On('koben:pc-status-updated')]
    public function refreshPcStatuses(): void
    {
        $this->dispatch('$refresh');
    }
}
