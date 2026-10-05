<?php

namespace App\Filament\Resources\StoredFiles\Pages;

use App\Filament\Resources\StoredFiles\StoredFileResource;
use App\Services\FileService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * Staff-facing view of one node in somebody else's drive.
 *
 * Read-only by design. Staff reach a student's files to help them locate work
 * and to free space when a student is stuck; they do not get an editor, so a
 * support visit cannot rewrite a student's folder structure.
 */
class ViewStoredFile extends ViewRecord
{
    protected static string $resource = StoredFileResource::class;

    protected static ?string $title = 'File';

    public function getHeaderActions(): array
    {
        return [
            $this->downloadAction(),
            $this->deleteAction(),
        ];
    }

    /**
     * Storage::download() writes Content-Disposition itself.
     *
     * The legacy download endpoint echoed the unsanitised original_filename into
     * that header, which let a stored name inject response headers.
     */
    private function downloadAction(): Action
    {
        return Action::make('download')
            ->label('Download')
            ->icon(Heroicon::ArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => ! $this->getRecord()->is_folder)
            ->action(function () {
                $file = $this->getRecord();

                if (! $file->existsOnDisk()) {
                    Notification::make()
                        ->danger()
                        ->title('That file is missing from storage')
                        ->send();

                    return null;
                }

                return $file->disk()->download($file->path, $file->original_name);
            });
    }

    private function deleteAction(): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->icon(Heroicon::Trash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn () => $this->getRecord()->is_folder
                ? "Delete the folder \"{$this->getRecord()->name}\" and everything inside it?"
                : "Delete \"{$this->getRecord()->name}\"?")
            ->authorize(fn (): bool => auth()->user()?->can('delete', $this->getRecord()) ?? false)
            ->action(function () {
                $count = app(FileService::class)->deleteTree($this->getRecord());

                Notification::make()
                    ->success()
                    ->title($count === 1 ? 'Deleted' : "Deleted {$count} items")
                    ->send();

                $this->redirect(StoredFileResource::getUrl('index'));
            });
    }
}
