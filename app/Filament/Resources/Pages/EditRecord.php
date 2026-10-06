<?php

namespace App\Filament\Resources\Pages;

use Filament\Resources\Pages\EditRecord as BaseEditRecord;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;

/**
 * Base for every resource edit page. See ListRecords for why.
 */
class EditRecord extends BaseEditRecord
{
    use RestrictsFileUploadsToSchemaComponents;
}
