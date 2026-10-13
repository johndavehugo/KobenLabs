<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

/**
 * A loud failure for a silent misconfiguration.
 *
 * Blank REVERB_APP_KEY/HOST means every broadcast silently goes nowhere:
 * typing indicators, roster updates, screen signals. Nothing errors, so
 * without this banner a fresh clone looks working while live updates are
 * dead. Shown only to staff, only while the values are missing.
 */
class ReverbHealth extends Widget
{
    /**
     * @var view-string
     */
    protected string $view = 'filament.widgets.reverb-health';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        if (! (auth()->user()?->canManageLab() ?? false)) {
            return false;
        }

        return blank(config('broadcasting.connections.reverb.key'))
            || blank(config('broadcasting.connections.reverb.options.host'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [];
    }
}
