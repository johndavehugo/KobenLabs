<?php

namespace App\Filament\Resources\Pages;

use Filament\Resources\Pages\CreateRecord as BaseCreateRecord;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;

/**
 * Base for every resource create page. See ListRecords for why.
 *
 * This is the page class where an upload field is most likely to be added, so the
 * restriction is on by default rather than opt-in.
 */
class CreateRecord extends BaseCreateRecord
{
    use RestrictsFileUploadsToSchemaComponents;
}
