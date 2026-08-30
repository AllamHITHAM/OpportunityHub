<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Backend Configuration & Safety Pass: this class previously mixed
 * REQUIRED baseline/reference data with two things unsafe to run against
 * a real environment -- a non-idempotent demo "Test User" factory call,
 * and (via `AdminUserSeeder`) a fixed, source-visible plaintext Admin
 * password. `php artisan db:seed --force` against staging/production
 * would have silently created both a predictable demo account and a
 * publicly-known Admin credential.
 *
 * `run()` now calls ONLY safe, idempotent, REQUIRED reference-data
 * seeders -- every one of them is `firstOrCreate`-style and genuinely
 * safe to re-run any number of times, against a fresh database or one
 * that's already been seeded. `php artisan db:seed --force` is
 * sufficient and safe on a fresh staging database exactly as-is.
 *
 * Demo data lives in `DemoDataSeeder`, run explicitly for local
 * development only:
 *
 *     php artisan db:seed --class=Database\\Seeders\\DemoDataSeeder
 *
 * The first Admin account is no longer seeded at all -- see
 * `App\Console\Commands\CreateAdminUserCommand`
 * (`php artisan admin:create {email}`), which never accepts or stores a
 * hardcoded password. See docs/DEPLOYMENT.md for the exact staging
 * bootstrap steps.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Phase 8A-6.1: idempotent -- never duplicates or destroys a
        // Skill row on a repeat run.
        $this->call(BaselineSkillSeeder::class);

        // Phase O8.2: same idempotency guarantee as BaselineSkillSeeder.
        $this->call(BaselineLocationSeeder::class);

        // Recommendation Accuracy Patch: real alternate names for the
        // cities BaselineLocationSeeder just seeded -- must run after it.
        $this->call(BaselineLocationAliasSeeder::class);
    }
}
