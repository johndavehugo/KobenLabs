<?php

use App\Filament\Student\Pages\JoinClass;
use App\Filament\Student\Pages\MyDrive;
use App\Models\User;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;

/**
 * Filament's `InteractsWithSchemas` composes Livewire's `WithFileUploads`, so every
 * schema-bearing component exposes `_startUpload` / `_finishUpload` / `_removeUpload`.
 * Those RPCs accept uploads to *any* Livewire property path unless the component
 * opts in to `RestrictsFileUploadsToSchemaComponents`, and Filament only adds that
 * trait to its own authentication pages - not to Pages or Resources.
 *
 * The guard Filament installs is:
 *
 *     abort_unless($component->isFileUploadForSchemaComponent($params[0] ?? ''), 403);
 *
 * so these tests assert that predicate directly. Driving the RPC through Livewire's
 * test harness does not dispatch the `call` event the guard hangs off, which is why
 * the behaviour is pinned one layer down.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->student()->create());
});

it('carries the restriction on both student pages', function () {
    expect(JoinClass::class)->toUseTrait(RestrictsFileUploadsToSchemaComponents::class)
        ->and(MyDrive::class)->toUseTrait(RestrictsFileUploadsToSchemaComponents::class);
});

it('treats no property on the class page as a valid upload target', function () {
    // JoinClass has no upload field at all, so every target the guard sees is
    // refused with a 403.
    $component = livewire(JoinClass::class)->instance();

    expect($component->isFileUploadForSchemaComponent('notAField'))->toBeFalse()
        ->and($component->isFileUploadForSchemaComponent('foo.bar'))->toBeFalse()
        ->and($component->isFileUploadForSchemaComponent(''))->toBeFalse();
});

it('accepts only the declared field on the drive page', function () {
    $component = livewire(MyDrive::class)->instance();

    expect($component->isFileUploadForSchemaComponent('uploadState'))->toBeTrue()
        ->and($component->isFileUploadForSchemaComponent('notAField'))->toBeFalse()
        ->and($component->isFileUploadForSchemaComponent('folderId'))->toBeFalse();
});

it('keeps the upload field in a discovered schema rather than an action modal', function () {
    // The trap: an upload field inside an action's modal schema looks fine to a
    // test that hands the action a file directly, but it is not a discovered
    // schema, so a real browser upload is refused with a 403. Asserting the
    // field is addressable here is what catches a move back into the modal.
    expect(livewire(MyDrive::class)->instance()->isFileUploadForSchemaComponent('uploadState'))
        ->toBeTrue();
});
