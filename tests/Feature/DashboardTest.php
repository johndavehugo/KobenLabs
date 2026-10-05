<?php

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Dashboard
|--------------------------------------------------------------------------
|
| These exist because the dashboard had no coverage at all, and it broke in a way
| every other test missed: 141 tests passed while `GET /admin` returned a 500.
|
| FilamentManager::getUserName() reads a literal `name` column unless the user
| implements HasName. The migration dropped that column in favour of
| `first_name` + `last_name` and a `full_name` accessor, so the account widget
| received null for a `string` return type and threw.
|
| Widgets only render on the dashboard, which is why testing the resource pages
| did not surface it.
|
*/

it('renders the dashboard for an administrator', function () {
    actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel('admin');

    livewire(Dashboard::class)->assertOk();
});

it('renders the dashboard for a teacher', function () {
    actingAs(User::factory()->teacher()->create());
    Filament::setCurrentPanel('admin');

    livewire(Dashboard::class)->assertOk();
});

it('resolves a display name for filament', function () {
    $user = User::factory()->create([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);

    expect($user->getFilamentName())->toBe('Ada Lovelace')
        ->and(Filament::getUserName($user))->toBe('Ada Lovelace');
});

it('falls back to the username when both name fields are blank', function () {
    $user = User::factory()->create([
        'first_name' => '',
        'last_name' => '',
        'username' => 'ada',
    ]);

    // The return type must stay `string`; an empty name would still be valid but
    // renders as blank avatar initials.
    expect($user->getFilamentName())->toBe('ada')
        ->and($user->full_name)->toBe('');
});

it('does not read a name column that no longer exists', function () {
    $user = User::factory()->create();

    // The migration dropped `name`. If anything falls back to it, this is null
    // and a `string` return type throws.
    expect(Schema::hasColumn('users', 'name'))->toBeFalse()
        ->and(Filament::getUserName($user))->toBeString();
});

it('renders the account widget without a stored avatar', function () {
    actingAs(User::factory()->admin()->create([
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
    ]));
    Filament::setCurrentPanel('admin');

    $user = auth()->user();

    // getUserAvatarUrl() falls back to a UI Avatars URL built from the name, so
    // it exercises the same path the widget renders. UI Avatars reduces the name
    // to initials, which is enough to prove it arrived.
    expect($user->getAttributeValue('avatar_url'))->toBeNull()
        ->and(Filament::getUserAvatarUrl($user))->toBeString()
        ->and(Filament::getUserAvatarUrl($user))->toContain('name=G+H');
});
