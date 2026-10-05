<?php

namespace App\Filament\Student\Pages;

use App\Models\ClassSession;
use App\Services\ClassSessionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;

/**
 * Join the class that is currently running.
 *
 * This is the student half of the academic loop. The legacy student portal had a
 * "Join Class" button that wrote an attendance row and showed "✓ Joined"
 * afterwards; without a surface like this, a session's roster on the staff side
 * could only ever be populated from a test.
 *
 * A Page rather than a resource: a student must not be able to browse every
 * session, only join the one that is running.
 */
class JoinClass extends Page
{
    /**
     * This page has no upload field, yet Filament does not add this trait to Pages
     * or Resources - only to its own authentication pages. Without it, Livewire's
     * _startUpload and _finishUpload accept uploads to any property path on
     * this page. Adding it makes uploads here fail with a 403, which is the point:
     * there is nothing on this page that should be able to accept one.
     */
    use RestrictsFileUploadsToSchemaComponents;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Class';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Class';

    /**
     * Resolved on every call so the page reflects a class that started or ended
     * since it was loaded.
     */
    public function activeSession(): ?ClassSession
    {
        return app(ClassSessionService::class)->active();
    }

    public function hasJoined(): bool
    {
        $session = $this->activeSession();

        return $session !== null
            && $session->attendance()->where('user_id', auth()->id())->exists();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    // Every field and action reads from activeSession() rather
                    // than from a record path: a Page has no $record, so
                    // TextEntry::make('schedule.title') would silently resolve to
                    // nothing.
                    ->heading(fn (): string => match (true) {
                        $this->activeSession() === null => 'No class running',
                        $this->hasJoined() => 'You have joined this class',
                        default => 'A class is running',
                    })
                    ->description(fn (): ?string => $this->activeSession()?->startedBy?->full_name
                        ? 'Started by '.$this->activeSession()->startedBy->full_name
                        : null)
                    ->icon(fn (): string => match (true) {
                        $this->activeSession() === null => 'heroicon-o-pause-circle',
                        $this->hasJoined() => 'heroicon-o-check-circle',
                        default => 'heroicon-o-play-circle',
                    })
                    ->columns(2)
                    ->schema($this->details())
                    ->footerActions($this->actions()),
            ]);
    }

    /**
     * @return array<Component>
     */
    private function details(): array
    {
        if ($this->activeSession() === null) {
            return [
                TextEntry::make('idle')
                    ->hiddenLabel()
                    ->state('Nothing to join right now. When a teacher starts a class it will appear here.'),
            ];
        }

        return [
            TextEntry::make('subject')
                ->label('Subject')
                ->weight('bold')
                ->state(fn (): ?string => $this->activeSession()?->schedule?->title),

            TextEntry::make('section')
                ->label('Year and section')
                ->placeholder('Whole year')
                ->state(fn (): ?string => $this->activeSession()?->schedule?->yearSection?->label),

            TextEntry::make('starts_at')
                ->label('Started at')
                ->state(fn (): ?string => $this->activeSession()?->schedule?->start_time),

            TextEntry::make('ends_at')
                ->label('Ends at')
                ->state(fn (): ?string => $this->activeSession()?->schedule?->end_time),
        ];
    }

    /**
     * @return array<Action>
     */
    private function actions(): array
    {
        return [
            Action::make('join')
                ->label('Join class')
                ->icon('heroicon-m-check')
                ->button()
                ->visible(fn (): bool => $this->activeSession() !== null && ! $this->hasJoined())
                // The service re-checks that the session is still active, so a
                // class that ends between render and click cannot record
                // attendance.
                ->authorize(fn (): bool => $this->activeSession() !== null
                    && (auth()->user()?->can('join', $this->activeSession()) ?? false))
                ->action(function (): void {
                    $recorded = app(ClassSessionService::class)
                        ->join($this->activeSession(), auth()->user());

                    Notification::make()
                        ->title($recorded ? 'Attendance recorded' : 'You have already joined')
                        ->success()
                        ->send();
                }),

            Action::make('leave')
                ->label('Attendance recorded')
                ->icon('heroicon-m-check-badge')
                ->disabled()
                ->visible(fn (): bool => $this->hasJoined()),

            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-m-arrow-path')
                ->outlined()
                // Polling is enough until Phase 5 wires the class-session
                // broadcast to a subscribed channel.
                ->action(fn () => null),
        ];
    }
}
