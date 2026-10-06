<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Pc;
use App\Models\User;
use App\Models\YearSection;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->teacher = User::factory()->teacher()->create();
});

/**
 * Security must-fix item 24: legacy created `audit_logs` and never wrote to it.
 *
 * These assert the trail records the changes worth explaining afterwards, and just
 * as importantly that it does not leak the one field that must never be readable
 * by whoever can read the log.
 */
it('records who created a record', function () {
    $this->actingAs($this->admin);

    $pc = Pc::factory()->create(['name' => 'LAB-PC-01']);

    $entry = AuditLog::query()
        ->forModel($pc)
        ->forAction('created.Pc')
        ->firstOrFail();

    expect($entry->user_id)->toBe($this->admin->id)
        ->and($entry->auditable_id)->toBe($pc->id)
        ->and($entry->properties['attributes']['name'])->toBe('LAB-PC-01');
});

it('records only the attributes that actually changed', function () {
    $pc = Pc::factory()->create(['name' => 'LAB-PC-01']);

    $this->actingAs($this->admin);

    $pc->update(['name' => 'LAB-PC-02']);

    $entry = AuditLog::query()->forAction('updated.Pc')->latest('id')->firstOrFail();

    // getChanges() alone reports only the current value, which does not say what
    // happened; the observer records a from/to pair instead.
    expect(array_keys($entry->properties['changes']))->toContain('name')
        ->not->toContain('ip_address')
        ->and($entry->properties['changes']['name'])->toBe([
            'from' => 'LAB-PC-01',
            'to' => 'LAB-PC-02',
        ]);
});

it('records a delete, because a vanished row cannot explain itself later', function () {
    $pc = Pc::factory()->create(['name' => 'LAB-PC-01']);

    $this->actingAs($this->admin);
    $pc->delete();

    expect(AuditLog::query()->forAction('deleted.Pc')->exists())->toBeTrue();
});

it('never writes a password hash into the trail', function () {
    $this->actingAs($this->admin);

    $victim = User::factory()->student()->create();
    $victim->update(['role' => UserRole::Admin]);

    $entry = AuditLog::query()->forAction('updated.User')->latest('id')->firstOrFail();
    $created = AuditLog::query()->forAction('created.User')->latest('id')->firstOrFail();

    // An audit trail is readable by staff, so a password hash in it is a credential
    // store with a helpful index.
    expect($created->properties['attributes'])->toHaveKey('password')
        ->and($created->properties['attributes']['password'])->toBe('[redacted]')
        ->and($entry->properties['changes'])->not->toHaveKey('password');
});

it('captures the request metadata while the request still exists', function () {
    $this->actingAs($this->admin);

    Pc::factory()->create();

    expect(AuditLog::query()->latest('id')->firstOrFail()->ip_address)->not->toBeNull();
});

it('does not audit the high-volume models it was told to skip', function () {
    $this->actingAs($this->admin);

    YearSection::factory()->create();
    Attendance::factory()->create();

    // YearSection is in the list; Attendance is deliberately not.
    expect(AuditLog::query()->forAction('created.YearSection')->exists())->toBeTrue()
        ->and(AuditLog::query()->forAction('created.Attendance')->exists())->toBeFalse();
});

it('records a change made with nobody signed in, with a null user', function () {
    // Seeding and console commands run unauthenticated. Recording the change with
    // a null user is right; silently dropping it is not.
    Auth::logout();

    Pc::factory()->create();

    $entry = AuditLog::query()->forAction('created.Pc')->firstOrFail();

    expect($entry->user_id)->toBeNull();
});

it('does not audit itself', function () {
    $this->actingAs($this->admin);

    AuditLog::record('manual.note');

    // Writing an audit entry must not generate an audit entry, or the log grows
    // without bound. Scoped to AuditLog rows, since the fixtures above legitimately
    // produce their own.
    expect(AuditLog::query()->forAction('manual.note')->count())->toBe(1)
        ->and(AuditLog::query()->where('auditable_type', AuditLog::class)->count())->toBe(0);
});
