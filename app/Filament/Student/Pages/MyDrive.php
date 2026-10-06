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
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
     * The contents of the current folder, as a query.
     *
     * A query rather than a loaded collection so Filament's own search, sorting and
     * pagination apply. `records()` with a Collection ignores all three, which would
     * have made the search and sort controls decorative.
     */
    public function childrenQuery(): Builder
    {
        return StoredFile::query()
            ->where('user_id', $this->user()->id)
            ->where('parent_id', $this->currentFolder()?->id)
            ->select(['stored_files.*']);
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
                    // write. Validation and placement stays in FileService.
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
            ->query(fn () => $this->childrenQuery())
            // Folders first, then alphabetical, as one default ordering rather than
            // two stacked defaultSort() calls. A stacked default is re-applied as a
            // multi-column sort and then fights whatever the user clicks, so an
            // explicit sort on size appeared to do nothing.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('is_folder')
                ->orderBy('name'))
            ->columns($this->listColumns())
            ->recordActions([
                $this->openAction(),
                $this->downloadAction(),
                $this->downloadFolderAction(),
                $this->renameAction(),
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

    private function renameAction(): Action
    {
        return Action::make('rename')
            ->label('Rename')
            ->icon(Heroicon::Pencil)
            ->modalHeading('Rename')
            ->schema([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255)
                    ->default(fn (StoredFile $record): string => $record->name),
            ])
            ->authorize(fn (StoredFile $record): bool => auth()->user()->can('reorganise', $record))
            ->action(function (array $data, StoredFile $record) {
                try {
                    app(FileService::class)->rename($record, (string) $data['name']);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Not renamed')
                        ->body(collect($exception->errors())->flatten()->first())
                        ->send();

                    return;
                }

                Notification::make()->success()->title('Renamed')->send();
            });
    }

    /**
     * A folder as a ZIP.
     *
     * Built by the service rather than streamed from the client, so the archive is
     * assembled from rows the caller is already authorised to see rather than from
     * a list the browser supplies.
     */
    private function downloadFolderAction(): Action
    {
        return Action::make('downloadFolder')
            ->label('Download as ZIP')
            ->icon(Heroicon::ArchiveBox)
            ->visible(fn (StoredFile $record): bool => $record->is_folder)
            ->authorize(fn (StoredFile $record): bool => auth()->user()->can('view', $record))
            ->action(fn (StoredFile $record) => app(FileService::class)->zipFolderContents($record));
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
    /**
     * Whether the drive is shown as a list of rows or a grid of tiles.
     *
     * A Livewire property rather than a query parameter, so the choice survives
     * navigating into a folder and back.
     */
    #[Url(as: 'view', except: 'list')]
    public string $viewMode = 'list';

    private function isGrid(): bool
    {
        return $this->viewMode === 'grid';
    }

    public function toggleViewModeAction(): Action
    {
        return Action::make('toggleViewMode')
            ->label(fn (): string => $this->isGrid() ? 'List view' : 'Grid view')
            ->icon(fn () => $this->isGrid() ? Heroicon::Bars3 : Heroicon::Squares2x2)
            ->action(function (): void {
                $this->viewMode = $this->isGrid() ? 'list' : 'grid';
            });
    }

    /**
     * @return array<Filament\Schemas\Components\Component>
     */
    /**
     * One column set for both views, with visibility deciding what shows.
     *
     * Returning a *different* array per view looked simpler but does not work:
     * Filament builds the table once per request and does not re-invoke the column
     * factory when a plain Livewire property changes, so the view toggle silently
     * did nothing. Per-column ->visible() is re-evaluated on every render.
     *
     * @return array<Filament\Tables\Columns\Column>
     */
    private function listColumns(): array
    {
        return [
            // Only rendered in grid. A file with no preview resolves to an empty state
            // rather than a broken image.
            ImageColumn::make('preview')
                ->label('Preview')
                        // Describes which file the thumbnail belongs to, rather than leaving
                        // the image with no accessible name.
                ->alt(fn (StoredFile $record): string => 'Preview of '.$record->name)
                ->disk(fn (): string => (string) config('lab.storage.default_disk'))
                ->state(fn (StoredFile $record): ?string => $record->isImage() && $record->existsOnDisk()
                    ? $record->path
                    : null)
                ->checkFileExistence(false)
                ->defaultImageUrl('')
                ->size(40)
                ->visible(fn (): bool => $this->isGrid()),

            IconColumn::make('is_folder')
                ->label('Kind')
                ->tooltip(fn (StoredFile $record): string => $record->is_folder ? 'Folder' : 'File')
                ->icon(fn (StoredFile $record) => $record->is_folder
                    ? Heroicon::Folder
                    : Heroicon::Document)
                ->color(fn (StoredFile $record) => $record->is_folder ? 'warning' : 'gray')
                ->visible(fn (): bool => ! $this->isGrid()),

            TextColumn::make('name')
                ->label('Name')
                ->searchable(['name', 'original_name'])
                ->weight(fn (StoredFile $record) => $record->is_folder || $this->isGrid() ? 'bold' : 'normal')
                ->wrap(),

            // Only meaningful in the list view; in the grid a tile's type is implied
            // by its icon or preview.
            TextColumn::make('mime_type')
                ->label('Type')
                ->formatStateUsing(fn (?string $state) => $state === 'folder' ? 'Folder' : $state)
                ->visible(fn (): bool => ! $this->isGrid())
                ->toggleable(),

            TextColumn::make('human_size')
                ->label('Size')
                ->alignEnd()
                ->sortable(['size']),

            TextColumn::make('created_at')
                ->label('Added')
                ->date()
                ->sortable()
                ->alignEnd()
                ->visible(fn (): bool => ! $this->isGrid()),
        ];
    }

    public function uploadAction(): Action
    {
        return Action::make('upload')
            ->label('Save files')
            ->icon(Heroicon::ArrowUpTray)
            ->disabled(fn (): bool => $this->uploadState === [])
            ->action(function () {
                $service = app(FileService::class);

                $parent = $this->currentFolder();
                $saved = 0;
                $rejected = 0;
                $lastError = null;

                foreach ($this->uploadState as $upload) {
                    if (! $upload instanceof UploadedFile) {
                        continue;
                    }

                    try {
                        $service->store($upload, $this->user(), $parent);
                        $saved++;
                    } catch (ValidationException $exception) {
                        $rejected++;
                        $lastError = collect($exception->errors())->flatten()->first();
                    }
                }

                $this->uploadState = [];

                // A mixed batch should not discard the good files, so the outcome
                // is reported as a count rather than failing on the first rejection.
                Notification::make()
                    ->when(
                        $saved === 0 && $rejected > 0,
                        fn (Notification $n) => $n->danger()->title('Nothing was uploaded'),
                        fn (Notification $n) => $n->success()->title(
                            $rejected > 0
                                ? "{$saved} saved, {$rejected} rejected"
                                : "{$saved} file(s) uploaded"
                        ),
                    )
                    ->when($lastError !== null, fn (Notification $n) => $n->body((string) $lastError))
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
            $this->toggleViewModeAction(),
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
