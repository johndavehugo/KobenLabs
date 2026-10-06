<?php

namespace App\Filament\Resources\Pages;

use Filament\Resources\Pages\ListRecords as BaseListRecords;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;

/**
 * Base for every resource list page in this app.
 *
 * Filament adds `RestrictsFileUploadsToSchemaComponents` only to its own
 * authentication pages, not to Resources. Without it, Livewire's `_startUpload`
 * and `_finishUpload` accept uploads to *any* Livewire property path on the page —
 * so an authenticated user who can reach any admin page could aim a file upload at
 * an arbitrary property.
 *
 * No resource form currently hosts a FileUpload, so this is defence in depth
 * rather than a fix for a live exploit. It is here so that the first person to add
 * a file field to a resource gets the restriction for free, instead of shipping a
 * new upload surface that quietly accepts uploads to every property.
 *
 * Pages that legitimately need an upload declare the field in their own schema, and
 * that field remains a valid target.
 */
class ListRecords extends BaseListRecords
{
    use RestrictsFileUploadsToSchemaComponents;
}
