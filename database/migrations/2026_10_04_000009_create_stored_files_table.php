<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal file drive.
 *
 * A single self-referencing table holds both files and folders. Folders are rows
 * with `is_folder = true`, an empty `path` and a zero `size` — the legacy
 * representation. Splitting into two tables would complicate the breadcrumb
 * walk, the recursive ZIP export and the single upload field in the UI.
 *
 * Renamed `files` -> `stored_files` to match the StoredFile model, avoiding a
 * collision with the Illuminate\Support\Facades\File facade that this app uses
 * throughout.
 *
 * Column renames from the legacy schema:
 *   filename          -> name
 *   original_filename -> original_name
 *   file_path         -> path
 *   file_size_bytes   -> size
 *   parent_folder_id  -> parent_id
 *
 * Deleting a folder cascades in the database. Physical blobs are a filesystem
 * concern and are removed by the caller — the legacy implementation recursed in
 * the DB but unlinked only the top-level file, leaking every child.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('original_name', 255);
            $table->string('path', 500);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('is_folder')->default(false);
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('stored_files')->cascadeOnDelete();

            $table->index('parent_id');
            $table->index('is_folder');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_files');
    }
};
