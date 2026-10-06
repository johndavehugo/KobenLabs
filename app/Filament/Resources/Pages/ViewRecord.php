<?php

namespace App\Filament\Resources\Pages;

use Filament\Resources\Pages\ViewRecord as BaseViewRecord;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;

/**
 * Base for every resource view page. See ListRecords for why.
 */
class ViewRecord extends BaseViewRecord
{
    use RestrictsFileUploadsToSchemaComponents;
}
