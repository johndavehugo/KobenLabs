<?php

namespace App\Models;

use Database\Factories\YearSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['year', 'section', 'is_active'])]
class YearSection extends Model
{
    /** @use HasFactory<YearSectionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function students(): HasMany
    {
        return $this->users()->students();
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    #[Scope]
    public function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    #[Scope]
    public function inactive(Builder $query): void
    {
        $query->where('is_active', false);
    }

    /**
     * `label` for display. An accessor rather than a plain method so that
     * Filament's `$recordTitleAttribute` and `TextColumn::make('label')` resolve
     * it — Eloquent treats a bare `label()` method as a relationship.
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => $this->section === null
            ? $this->year
            : "{$this->year} - {$this->section}");
    }

    /**
     * Active years and sections as an id => label map, for select fields.
     *
     * Provided here because `label` is an accessor rather than a column, so
     * Filament's `->relationship('yearSection', 'label')` cannot resolve it —
     * that tries to select and order by `year_sections.label` in SQL and fails.
     */
    public static function options(): Collection
    {
        return static::query()
            ->active()
            ->orderBy('year')
            ->orderBy('section')
            ->get()
            ->mapWithKeys(fn (self $yearSection) => [$yearSection->id => $yearSection->label]);
    }
}
