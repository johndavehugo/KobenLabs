<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the stock Laravel users table into the lab domain user.
 *
 * Changes from the default schema:
 *   - drops `name` in favour of `first_name` + `last_name` (legacy fields)
 *   - adds `username`, which is the login identifier (legacy `username`)
 *   - replaces the free-text `year` / `section` pair with a `year_section_id`
 *     foreign key, so referential integrity is enforced by the database rather
 *     than by a correlated subquery in a legacy API script
 *   - adds `role`, `status` and the storage quota pair
 *
 * `username`, `first_name` and `last_name` are added nullable so this migration
 * is safe against a table that already holds rows; the next migration backfills
 * them and tightens the constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->after('id');
            $table->string('first_name', 50)->nullable()->after('email');
            $table->string('last_name', 50)->nullable()->after('first_name');
            $table->string('student_id', 20)->nullable()->after('last_name');
            $table->foreignId('year_section_id')
                ->nullable()
                ->after('student_id')
                ->constrained('year_sections')
                ->nullOnDelete();
            $table->string('role', 20)->default('student')->after('year_section_id');
            $table->string('status', 20)->default('active')->after('role');
            $table->unsignedInteger('storage_quota_mb')->default(500)->after('status');
            $table->decimal('storage_used_mb', 10, 2)->default(0)->after('storage_quota_mb');
            $table->timestamp('last_login_at')->nullable()->after('storage_used_mb');

            $table->index('role');
            $table->index('status');
            $table->index('year_section_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['status']);
            $table->dropIndex(['year_section_id']);
            $table->dropForeign(['year_section_id']);
            $table->dropColumn([
                'username',
                'first_name',
                'last_name',
                'student_id',
                'year_section_id',
                'role',
                'status',
                'storage_quota_mb',
                'storage_used_mb',
                'last_login_at',
            ]);
        });
    }
};
