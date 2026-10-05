<?php

namespace App\Filament\Student\Pages;

use App\Models\StoredFile;
use App\Models\User;
use App\Services\FileService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * The user's own file drive.
 *
 * Folders live in the database and the disk path is derived from the tree, so
 * moving a node is a row update rather than a filesystem operation. All
 * validation, quota and naming decisions belong to FileService; this page only
 * chooses what to show and what to ask for.
 */
class MyDrive extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    use InteractsWithTable;

    /**
     * Filament does not add this trait to Pages or Resources, only to its own
     * authentication pages. Without it, Livewire's `_startUpload` and
     * `_finishUpload` accept uploads to any Livewire property path, so anyone who
     * can reach this page could upload a file to an arbitrary property name.
     */
    use RestrictsFileUploadsToSchemaComponents;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationLabel = 'My Drive';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'My Drive';

    protected static ?string $slug = 'my-drive';

    /**
     * Held in the URL so a folder can be linked to and survives a refresh.
     */
    #[Url(as: 'folder', except: null)]
    public ?int $folderId = null;

    /**
     * The picked-but-not-yet-committed files.
     *
     * Lives in the page schema on purpose. Restricting upload RPCs to declared
     * schema components only works for components in a *discovered* schema, and an
     * action's modal schema is not one. An upload field inside the upload action's
     * modal therefore looks legitimate to a test that passes a file straight into
     * the action data, while a real browser upload is refused with a 403. See
     * uploadAction().
     *
     * @var array<int, UploadedFile>
     */
    public array $uploadState = [];

    public function mount(): void
    {
        $this->ensureFolderBelongsToUser();
    }

    // -----------------------------------------------------------------
    // State
    // -----------------------------------------------------------------

    public function user(): User
    {
        return auth()->user();
    }

    /**
     * The folder being viewed, or null at the drive root.
     *
     * Re-queried on every call and never trusted from the request: Filament does
     * not restore or re-authorize Eloquent properties on later Livewire requests.
     */
    public function currentFolder(): ?StoredFile
    {
        if ($this->folderId === null) {
            return null;
        }

        $folder = StoredFile::query()
            ->whereKey($this->folderId)
            ->where('user_id', $this->user()->id)
            ->folders()
            ->first();

        if ($folder === null) {
            $this->folderId = null;

            return null;
        }

        return $folder;
    }

    /**
     * @return Collection<int, StoredFile>
     */
    public function breadcrumbs(): Collection
    {
        $folder = $this->currentFolder();

        return $folder === null
            ? collect()
            : $folder->ancestors()->prepend($folder)->values();
    }

    /**
     * @return Collection<int, StoredFile>
     */
    public function children(): Collection
    {
        return StoredFile::query()
            ->where('user_id', $this->user()->id)
            ->where('parent_id', $this->currentFolder()?->id)
            ->orderByDesc('is_folder')
            ->orderBy('name')
            ->get();
    }

    /**
     * Drop a folder id that is not this user's, so a tampered URL cannot browse
     * another account's tree.
     */
    private function ensureFolderBelongsToUser(): void
    {
        if ($this->folderId === null) {
            return;
        }

        $owned = StoredFile::query()
            ->whereKey($this->folderId)
            ->where('user_id', $this->user()->id)
            ->folders()
            ->exists();

        if (! $owned) {
            $this->folderId = null;
        }
    }

    // -----------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Storage')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('used')
                            ->label('Used')
                            ->state(fn () => $this->user()->human_storage_used),
                        TextEntry::make('quota')
                            ->label('Quota')
                            ->state(fn () => $this->user()->human_storage_quota),
                        TextEntry::make('location')
                            ->label('Location')
                            ->state(fn () => $this->currentFolder()?->name ?? 'My Drive'),
                    ]),

                Section::make($this->breadcrumbLabel())
                    ->description(fn () => $this->currentFolder() === null
                        ? 'Allowed types: '.$this->allowedExtensionList()
                        : 'Inside "'.$this->currentFolder()->name.'"')
                    ->schema([
                        $this->uploadField(),
                        EmbeddedTable::make(),
                    ]),
            ]);
    }

    private function uploadField(): FileUpload
    {
        return FileUpload::make('uploadState')
            ->label('Add files')
            ->multiple()
            ->maxFiles(10)
            // Keep the upload as a TemporaryUploadedFile. By default Filament
            // persists it and replaces the state with the stored path, which
            // would leave FileService nothing to read and silently skip the
            // write. Validation and placement stay in FileService.
            ->storeFiles(false)
            ->visibility('private')
            // No acceptedFileTypes(): the allowlist is enforced server-side
            // against the sniffed contents, and a client-side type list would
            // only ever be a second guess at the same rule.
            ->reorderable(false);
    }

    private function breadcrumbLabel(): string
    {
        $folder = $this->currentFolder();

        if ($folder === null) {
            return 'My Drive';
        }

        return $folder->ancestors()
            ->push($folder)
            ->pluck('name')
            ->implode(' / ');
    }

    /**
     * The listing is a table with its own record actions, resolved fresh on every
     * request rather than from a Livewire property.
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->children())
            ->columns([
                IconColumn::make('is_folder')
                    ->label('')
                    ->icon(fn (StoredFile $record) => $record->is_folder
                        ? Heroicon::Folder
                        : Heroicon::Document)
                    ->color(fn (StoredFile $record) => $record->is_folder ? 'warning' : 'gray'),

                TextColumn::make('name')
                    ->label('Name')
                    ->weight(fn (StoredFile $record) => $record->is_folder ? 'bold' : 'normal'),

                TextColumn::make('mime_type')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state) => $state === 'folder' ? 'Folder' : $state)
                    ->toggleable(),

                TextColumn::make('human_size')
                    ->label('Size')
                    ->alignEnd(),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->date()
                    ->alignEnd(),
            ])
            ->recordActions([
                $this->openAction(),
                $this->downloadAction(),
                $this->deleteAction(),
            ])
            ->emptyStateHeading('This folder is empty')
            ->emptyStateDescription('Add files above or create a folder to get started.');
    }

    // -----------------------------------------------------------------
    // Actions
    // -----------------------------------------------------------------

    private function openAction(): Action
    {
        return Action::make('open')
            ->label('Open')
            ->icon(Heroicon::ArrowRightCircle)
            ->visible(fn (StoredFile $record) => $record->is_folder)
            ->action(fn (StoredFile $record) => $this->folderId = $record->id);
    }

    /**
     * Storage::download() sets Content-Disposition itself.
     *
     * The legacy controller echoed the unsanitised original_filename into that
     * header, which let a stored filename inject response headers.
     */
    private function downloadAction(): Action
    {
        return Action::make('download')
            ->label('Download')
            ->icon(Heroicon::ArrowDownTray)
            ->visible(fn (StoredFile $record) => ! $record->is_folder)
            ->authorize(fn (StoredFile $record) => auth()->user()->can('view', $record))
            ->action(function (StoredFile $record) {
                if (! $record->existsOnDisk()) {
                    Notification::make()
                        ->danger()
                        ->title('That file is missing from storage')
                        ->send();

                    return;
                }

                return $record->disk()->download($record->path, $record->original_name);
            });
    }

    private function deleteAction(): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->icon(Heroicon::Trash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (StoredFile $record) => $record->is_folder
                ? "Delete the folder \"{$record->name}\"?"
                : "Delete \"{$record->name}\"?")
            ->modalDescription(fn (StoredFile $record) => $record->is_folder
                ? 'Everything inside it will be deleted too.'
                : null)
            ->authorize(fn (StoredFile $record) => auth()->user()->can('delete', $record))
            ->action(function (StoredFile $record) {
                $count = app(FileService::class)->deleteTree($record);

                Notification::make()
                    ->success()
                    ->title($count === 1 ? 'Deleted' : "Deleted {$count} items")
                    ->send();
            });
    }

    /**
     * Commits whatever is sitting in the page's upload field. Deliberately has no
     * modal: the field belongs to the page schema, not to this action's schema,
     * because only discovered schemas are valid upload targets under
     * RestrictsFileUploadsToSchemaComponents.
     */
    public function uploadAction(): Action
    {
        return Action::make('upload')
            ->label('Save files')
            ->icon(Heroicon::ArrowUpTray)
            ->disabled(fn (): bool => $this->uploadState === [])
            ->action(function () {
                $service = app(FileService::class);

                $parent = $this->currentFolder();

                foreach ($this->uploadState as $upload) {
                    if (! $upload instanceof UploadedFile) {
                        continue;
                    }

                    try {
                        $service->store($upload, $this->user(), $parent);
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Upload rejected')
                            ->body(collect($exception->errors())->flatten()->first())
                            ->send();

                        return;
                    }
                }

                $this->uploadState = [];

                Notification::make()
                    ->success()
                    ->title('Upload complete')
                    ->send();
            });
    }

    public function createFolderAction(): Action
    {
        return Action::make('createFolder')
            ->label('New folder')
            ->icon(Heroicon::FolderPlus)
            ->modalHeading('New folder')
            ->schema([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data) {
                try {
                    app(FileService::class)->createFolder($this->user(), (string) $data['name'], $this->currentFolder());
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Folder not created')
                        ->body(collect($exception->errors())->flatten()->first())
                        ->send();

                    return;
                }

                Notification::make()->success()->title('Folder created')->send();
            });
    }

    public function refreshAction(): Action
    {
        return Action::make('refresh')
            ->label('Refresh')
            ->icon(Heroicon::ArrowPath)
            ->action(fn () => null);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->uploadAction(),
            $this->createFolderAction(),
            $this->refreshAction(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allowedExtensions(): array
    {
        return (array) config('lab.uploads.allowed_extensions');
    }

    private function allowedExtensionList(): string
    {
        return implode(', ', $this->allowedExtensions());
    }
}
