<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the first administrator.
 *
 * There is no way to sign in before this runs, which makes it the one piece of setup that
 * genuinely cannot be skipped - and it was previously seeded as "Test User" with a `name`
 * attribute. That column does not exist on `users` (it is `first_name` / `last_name`), so
 * the insert failed outright, and the factory default is a *student* anyway, which would
 * have locked the person following the setup guide straight out of the admin panel.
 *
 * Credentials come from the environment rather than being hardcoded, because a seeder that
 * always creates the same password is a seeder that ends up on the internet. They default
 * to something obvious purely so a first run on a laptop works without ceremony - the guide
 * says to change them, and says so before anything else is done with the system.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $email = env('SEED_ADMIN_EMAIL', 'admin@example.com');

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->warn("An account for {$email} already exists; leaving it alone.");

            return;
        }

        User::factory()->create([
            'first_name' => env('SEED_ADMIN_FIRST_NAME', 'Lab'),
            'last_name' => env('SEED_ADMIN_LAST_NAME', 'Administrator'),
            'email' => $email,
            'password' => Hash::make(env('SEED_ADMIN_PASSWORD', 'password')),
            'role' => UserRole::Admin,
            'student_id' => null,
        ]);

        $this->command?->info("Created administrator {$email}.");
        $this->command?->warn('Change this password before using the system for real.');
    }
}
