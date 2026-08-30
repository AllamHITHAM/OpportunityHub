<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Backend Configuration & Safety Pass: the "Test User" account previously
 * lived inline in `DatabaseSeeder::run()` as a bare, non-idempotent
 * `User::factory()->create(...)` call -- unconditionally run on every
 * `db:seed`, including against staging/production, and guaranteed to
 * hard-fail (unique email violation) on any second run anywhere it had
 * already run once.
 *
 * Extracted here, made idempotent (`firstOrCreate`, matching every other
 * seeder in this app), and deliberately NOT called by
 * `DatabaseSeeder::run()` in any real environment -- see that class's own
 * doc comment for the guard. Run explicitly for local development only:
 *
 *     php artisan db:seed --class=Database\\Seeders\\DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Matches UserFactory's own defaults for the fields that matter
        // (password/verification) -- role/status are intentionally left
        // to the same column defaults the original inline factory call
        // also relied on, unchanged by this extraction.
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
            ],
        );
    }
}
