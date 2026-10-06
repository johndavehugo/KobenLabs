<?php

namespace App\Filament\Resources\StoredFiles\Pages;

use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\StoredFiles\StoredFileResource;

/**
 * Read-only. There is no create action here on purpose: files enter a drive
 * through the owner's own My Drive page, where the quota and the upload rules
 * apply. Staff can look, download and free space — they do not write.
 */
class ListStoredFiles extends ListRecords
{
    protected static string $resource = StoredFileResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
