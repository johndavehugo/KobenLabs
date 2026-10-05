<?php

namespace App\Models;

use App\Enums\TextSize;
use Database\Factories\AccessibilitySettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user display preferences.
 *
 * The legacy API exposed this at `/api/users/{id}/accessibility` with no
 * ownership check, letting any authenticated user read or write anyone else's
 * settings. Scope access to the authenticated user.
 */
#[Fillable(['user_id', 'text_size', 'high_contrast', 'text_to_speech'])]
class AccessibilitySetting extends Model
{
    /** @use HasFactory<AccessibilitySettingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'text_size' => TextSize::class,
            'high_contrast' => 'boolean',
            'text_to_speech' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Return the user's settings, creating defaults on first access so callers
     * never have to handle a missing row.
     */
    public static function for(User $user): self
    {
        return $user->accessibilitySettings
            ?? self::create(['user_id' => $user->id])->refresh();
    }
}
