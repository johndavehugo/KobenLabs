<?php

namespace App\Filament\Resources\ClassSessions\Pages;

use App\Enums\ClassSessionStatus;
use App\Filament\Resources\ClassSessions\ClassSessionResource;
use App\Filament\Resources\Pages\CreateRecord;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Services\ClassSessionService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class CreateClassSession extends CreateRecord
{
    protected static string $resource = ClassSessionResource::class;

    protected static ?string $title = 'Start class';

    protected static ?string $navigationLabel = 'Start class';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-m-play';

    protected static ?int $navigationSort = 1;

    /**
     * A session is started from a booking rather than composed from scratch, so
     * the only field is which schedule to run.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('schedule_id')
                    ->label('Booking')
                    ->options(fn (): array => Schedule::query()
                        ->bookable()
                        ->with(['teacher', 'yearSection'])
                        // Exclude a booking that already has a session running
                        // against it. The global single-active-session rule is
                        // still enforced by ClassSessionObserver — this only
                        // keeps the obvious choice out of the dropdown.
                        ->whereDoesntHave(
                            'classSessions',
                            fn (Builder $query) => $query->where('status', ClassSessionStatus::Active),
                        )
                        ->orderBy('starts_at')
                        ->get()
                        ->mapWithKeys(fn (Schedule $schedule) => [
                            $schedule->id => sprintf(
                                '%s — %s (%s)',
                                $schedule->title,
                                $schedule->starts_at->format('D j M H:i'),
                                $schedule->yearSection?->label ?? 'Whole year',
                            ),
                        ])
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('Bookings with a class already running are not listed.'),
            ]);
    }

    /**
     * Starts the class through the service rather than writing the row directly,
     * so the single-active-session rule and the `class-session.started` broadcast
     * both apply.
     *
     * The active-session check is repeated here so its refusal surfaces as a form
     * error under the booking picker rather than an unhandled 422. The rule itself
     * still lives in ClassSessionObserver — this is the same condition, evaluated
     * early enough to be shown.
     */
    protected function handleRecordCreation(array $data): ClassSession
    {
        $service = app(ClassSessionService::class);

        if ($service->active() !== null) {
            $this->addError(
                'data.schedule_id',
                'Another class is already running. End it before starting another.',
            );

            $this->halt();
        }

        return $service->start(
            Schedule::query()->findOrFail($data['schedule_id']),
            auth()->user(),
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->title('Class started')
            ->body('Students in the section can now join.')
            ->success()
            ->send();
    }
}
