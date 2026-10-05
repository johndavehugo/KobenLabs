<?php

use App\Enums\PcStatus;
use App\Filament\Resources\Pcs\Pages\CreatePc;
use App\Filament\Resources\Pcs\Pages\EditPc;
use App\Filament\Resources\Pcs\Pages\ListPcs;
use App\Models\Pc;
use App\Models\PcSession;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;

it('loads the pc inventory for staff', function () {
    actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel('admin');

    $pcs = Pc::factory()->count(3)->create();

    livewire(ListPcs::class)
        ->assertOk()
        ->assertCanSeeTableRecords($pcs);
});

it('lets a student read the inventory but not change it', function () {
    actingAs(User::factory()->student()->create());
    Filament::setCurrentPanel('admin');

    livewire(ListPcs::class)
        ->assertOk()
        ->assertActionHidden('create')
        ->assertTableActionHidden('changeStatus', Pc::factory()->create());
});

it('lets staff take a pc out of service', function () {
    $admin = User::factory()->admin()->create();
    actingAs($admin);
    Filament::setCurrentPanel('admin');

    $pc = Pc::factory()->create(['status' => PcStatus::Online]);

    livewire(ListPcs::class)
        ->callTableAction('changeStatus', $pc, [
            'status' => PcStatus::Maintenance->value,
        ])
        ->assertHasNoActionErrors();

    expect($pc->refresh()->status)->toBe(PcStatus::Maintenance);
});

it('stamps last seen when a pc is brought back online by hand', function () {
    actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel('admin');

    $pc = Pc::factory()->create(['status' => PcStatus::Maintenance]);

    expect($pc->last_seen_at)->toBeNull();

    livewire(ListPcs::class)
        ->callTableAction('changeStatus', $pc, [
            'status' => PcStatus::Online->value,
        ])
        ->assertHasNoActionErrors();

    expect($pc->refresh()->last_seen_at)->not->toBeNull();
});

it('collects idle pcs from the toolbar action', function () {
    actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel('admin');

    $stale = Pc::factory()->stale()->create();
    $healthy = Pc::factory()->online()->create();

    livewire(ListPcs::class)
        ->callAction(TestAction::make('collectStale')->table())
        ->assertNotified();

    expect($stale->refresh()->status)->toBe(PcStatus::Offline)
        ->and($healthy->refresh()->status)->toBe(PcStatus::Online);
});

it('filters the inventory by status', function () {
    actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel('admin');

    $online = Pc::factory()->online()->create();
    $offline = Pc::factory()->create();

    livewire(ListPcs::class)
        ->assertCanSeeTableRecords([$online, $offline])
        ->filterTable('status', PcStatus::Online->value)
        ->assertCanSeeTableRecords([$online])
        ->assertCanNotSeeTableRecords([$offline]);
});

it('creates a pc', function () {
    actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel('admin');

    livewire(CreatePc::class)
        ->assertOk()
        ->fillForm([
            'name' => 'Lab Computer 12',
            'ip_address' => '192.168.1.112',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'status' => PcStatus::Offline->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('pcs', [
        'name' => 'Lab Computer 12',
        'ip_address' => '192.168.1.112',
        'status' => 'offline',
    ]);
});

it('rejects a duplicate ip address', function () {
    actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel('admin');

    Pc::factory()->create(['ip_address' => '192.168.1.101']);

    livewire(CreatePc::class)
        ->fillForm([
            'name' => 'Clashing PC',
            'ip_address' => '192.168.1.101',
            'status' => PcStatus::Offline->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['ip_address']);
});

it('rejects a malformed ip address', function () {
    actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel('admin');

    livewire(CreatePc::class)
        ->fillForm([
            'name' => 'Bad PC',
            'ip_address' => 'not-an-ip',
            'status' => PcStatus::Offline->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['ip_address']);
});

it('edits a pc', function () {
    actingAs(User::factory()->teacher()->create());
    Filament::setCurrentPanel('admin');

    $pc = Pc::factory()->create(['name' => 'Old name']);

    livewire(EditPc::class, ['record' => $pc->getKey()])
        ->assertOk()
        ->fillForm(['name' => 'New name'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($pc->refresh()->name)->toBe('New name');
});

it('shows who is occupying a pc', function () {
    $pc = Pc::factory()->online()->create();
    $occupant = User::factory()->student()->create();

    PcSession::factory()->for($pc)->for($occupant)->create();

    actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel('admin');

    livewire(ListPcs::class)
        ->assertOk()
        ->assertSee($occupant->full_name);
});
