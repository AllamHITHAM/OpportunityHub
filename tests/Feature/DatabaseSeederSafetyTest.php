<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\LocationAlias;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backend Configuration & Safety Pass: `DatabaseSeeder` previously mixed
 * REQUIRED baseline data with a non-idempotent demo "Test User" and (via
 * the now-removed `AdminUserSeeder`) a fixed, source-visible Admin
 * password -- meaning `php artisan db:seed --force` against
 * staging/production would have silently created both. These tests
 * verify the safe split: `DatabaseSeeder::run()` seeds only required,
 * idempotent reference data, and never touches demo/Admin accounts.
 */
class DatabaseSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_the_default_seeder_creates_no_demo_or_admin_user(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'admin@opportunityhub.com']);
        $this->assertSame(0, User::count());
    }

    public function test_running_the_default_seeder_still_creates_the_required_baseline_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('skills', ['name' => 'Flutter']);
        $this->assertGreaterThan(0, Location::count());
        $this->assertGreaterThan(0, LocationAlias::count());
    }

    public function test_running_the_default_seeder_twice_is_safe_and_does_not_duplicate_rows(): void
    {
        $this->seed(DatabaseSeeder::class);
        $firstSkillCount = Skill::count();
        $firstLocationCount = Location::count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($firstSkillCount, Skill::count());
        $this->assertSame($firstLocationCount, Location::count());
    }

    public function test_demo_data_seeder_creates_the_test_user_only_when_run_explicitly(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }

    public function test_demo_data_seeder_is_idempotent(): void
    {
        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(
            1,
            User::where('email', 'test@example.com')->count(),
        );
    }
}
