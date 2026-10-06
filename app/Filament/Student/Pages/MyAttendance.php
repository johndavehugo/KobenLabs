<?php

namespace App\Filament\Student\Pages;

use App\Services\AttendanceSummaryService;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * A student's own attendance record.
 *
 * The legacy student portal had a complete attendance summary view that nothing
 * linked to — no route, no nav item, unreachable. This replaces it rather than
 * porting it.
 *
 * Every figure comes from `AttendanceSummaryService`, which is also what keeps the
 * "attended / held" arithmetic in one place. The page adds no arithmetic of its own.
 */
class MyAttendance extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'My Attendance';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'My Attendance';

    protected static ?string $slug = 'my-attendance';

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Summary')
                    ->description('Attendance is recorded when you join a running class. "Classes held" counts completed sessions for your year and section.')
                    ->columns(3)
                    ->schema($this->summaryFields()),

                Section::make('By subject')
                    ->description('Classes held for your year and section, and how many you attended.')
                    ->schema($this->subjectFields()),

                Section::make('Sessions you attended')->schema([
                    EmbeddedTable::make(),
                ]),
            ]);
    }

    /**
     * @return array<TextEntry>
     */
    private function summaryFields(): array
    {
        $service = app(AttendanceSummaryService::class);
        $user = auth()->user();

        return [
            TextEntry::make('attended')
                ->label('Attended')
                ->state(fn (): int => $service->attendedCount($user)),

            TextEntry::make('held')
                ->label('Classes held')
                ->state(function (): string {
                    $user = auth()->user();

                    // A student with no year section has no denominator, and
                    // saying "0" would be a worse lie than saying nothing.
                    if ($user->year_section_id === null) {
                        return 'Not available';
                    }

                    return (string) app(AttendanceSummaryService::class)->heldCount($user);
                }),

            TextEntry::make('rate')
                ->label('Attendance rate')
                ->state(fn (): string => $this->rateLabel()),
        ];
    }

    /**
     * @return array<TextEntry>
     */
    private function subjectFields(): array
    {
        $service = app(AttendanceSummaryService::class);
        $user = auth()->user();

        $rows = $service->bySubject($user);

        return [
            TextEntry::make('subjects')
                ->hiddenLabel()
                ->state(fn (): string => $rows->isEmpty()
                    ? 'No classes have been held for your year and section yet.'
                    : $rows
                        ->map(fn (object $row): string => sprintf(
                            '%s — %d of %d%s',
                            $row->subject,
                            $row->attended,
                            $row->held,
                            $row->rate === null ? '' : ' ('.$row->rate.'%)',
                        ))
                        ->implode("\n"))
                ->columnSpanFull(),
        ];
    }

    private function rateLabel(): string
    {
        if (auth()->user()->year_section_id === null) {
            return 'Not available — you are not assigned to a year and section';
        }

        $rate = app(AttendanceSummaryService::class)->rate(auth()->user());

        return $rate === null ? 'Not available' : $rate.'%';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => app(AttendanceSummaryService::class)
                ->recentSessions(auth()->user()))
            ->columns([
                TextColumn::make('schedule.title')
                    ->label('Subject')
                    ->weight('bold'),

                TextColumn::make('schedule.teacher.full_name')
                    ->label('Teacher')
                    ->placeholder('A deleted account'),

                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
            ])
            ->defaultSort('started_at', 'desc')
            ->emptyStateHeading('No sessions yet')
            ->emptyStateDescription('Attendance appears here once you join a running class.');
    }

    /**
     * A student must not reach another student's record by id. The page only ever
     * shows the signed-in user's own attendance, and this keeps it off staff panels
     * entirely.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->isStudent() ?? false;
    }
}
