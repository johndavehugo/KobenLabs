<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Backfill and tighten the user columns added by the previous migration.
 *
 * A stock Laravel install can already hold a row here (the seeded user), so the
 * identifying columns cannot be added as NOT NULL up front. Derive values from
 * whatever is available, then enforce the constraints the domain requires.
 *
 * On a fresh install this is a no-op — there are no rows to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfillNames();

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable(false)->change();
            $table->string('first_name', 50)->nullable(false)->change();
            $table->string('last_name', 50)->nullable(false)->change();

            $table->unique('username');
            $table->unique('student_id');
        });

        // `name` is superseded by first_name + last_name.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->after('email');
        });

        foreach (DB::table('users')->select('id', 'first_name', 'last_name')->get() as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'name' => trim("{$user->first_name} {$user->last_name}"),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropUnique(['student_id']);
            $table->string('username', 50)->nullable()->change();
            $table->string('first_name', 50)->nullable()->change();
            $table->string('last_name', 50)->nullable()->change();
        });
    }

    /**
     * Populate username and the name pair for any pre-existing row.
     */
    private function backfillNames(): void
    {
        $users = DB::table('users')
            ->select('id', 'name', 'email')
            ->whereNull('username')
            ->get();

        foreach ($users as $user) {
            $parts = preg_split('/\s+/', trim((string) $user->name), 2) ?: [];

            DB::table('users')->where('id', $user->id)->update([
                'username' => $this->uniqueUsername((string) $user->email, (int) $user->id),
                'first_name' => $parts[0] ?? 'User',
                'last_name' => $parts[1] ?? 'Unknown',
            ]);
        }
    }

    /**
     * Derive a username from the local part of the email address, falling back
     * to a generated value, and guarantee uniqueness against existing rows.
     */
    private function uniqueUsername(string $email, int $id): string
    {
        $local = Str::before($email, '@');
        $base = Str::lower(preg_replace('/[^A-Za-z0-9_.-]/', '', $local) ?: '');
        $base = $base !== '' ? Str::limit($base, 40, '') : "user{$id}";

        $candidate = $base;
        $suffix = 1;

        while (DB::table('users')->where('username', $candidate)->where('id', '!=', $id)->exists()) {
            $candidate = Str::limit($base, 40, '').$suffix++;
        }

        return $candidate;
    }
};
