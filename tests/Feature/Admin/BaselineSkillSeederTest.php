<?php

namespace Tests\Feature\Admin;

use App\Models\Skill;
use Database\Seeders\BaselineSkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 8A-6.1: `BaselineSkillSeeder` -- deterministic, idempotent,
 * unique-name-safe, and never destructive toward rows it didn't create.
 */
class BaselineSkillSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_the_expected_baseline_skills(): void
    {
        $this->seed(BaselineSkillSeeder::class);

        $this->assertDatabaseHas('skills', ['name' => 'AutoCAD']);
        $this->assertDatabaseHas('skills', ['name' => 'Revit']);
        $this->assertDatabaseHas('skills', ['name' => 'Flutter']);
        $this->assertDatabaseHas('skills', ['name' => 'Laravel']);
        $this->assertDatabaseHas('skills', ['name' => 'MATLAB']);
        $this->assertDatabaseHas('skills', ['name' => 'SolidWorks']);
        $this->assertDatabaseHas('skills', ['name' => 'Digital Marketing']);
        $this->assertDatabaseHas('skills', ['name' => 'Clinical Pharmacy']);
        $this->assertDatabaseHas('skills', ['name' => 'Microsoft Excel']);

        $this->assertGreaterThan(50, Skill::count());
    }

    public function test_running_the_seeder_twice_creates_no_duplicates(): void
    {
        $this->seed(BaselineSkillSeeder::class);
        $countAfterFirstRun = Skill::count();

        $this->seed(BaselineSkillSeeder::class);
        $countAfterSecondRun = Skill::count();

        $this->assertSame($countAfterFirstRun, $countAfterSecondRun);
    }

    public function test_canonical_duplicate_names_across_disciplines_remain_a_single_row(): void
    {
        $this->seed(BaselineSkillSeeder::class);

        // AutoCAD is listed under Civil Engineering, Architecture, and
        // Mechanical Engineering in the source catalog; Microsoft Excel
        // under Civil Engineering, Business, and Healthcare -- both must
        // collapse to exactly one canonical row.
        $this->assertSame(1, Skill::where('name', 'AutoCAD')->count());
        $this->assertSame(1, Skill::where('name', 'Microsoft Excel')->count());
        $this->assertSame(1, Skill::where('name', 'MATLAB')->count());
    }

    public function test_existing_manually_created_skill_rows_are_not_destroyed(): void
    {
        $manual = Skill::create(['name' => 'Quantum Computing', 'category' => 'Custom']);

        $this->seed(BaselineSkillSeeder::class);

        $this->assertDatabaseHas('skills', [
            'id' => $manual->id,
            'name' => 'Quantum Computing',
            'category' => 'Custom',
        ]);
    }

    public function test_an_existing_row_matching_a_baseline_name_is_left_untouched(): void
    {
        // A pre-existing "AutoCAD" row, e.g. created manually before the
        // seeder ever ran, with a category an Admin chose themselves.
        $existing = Skill::create(['name' => 'AutoCAD', 'category' => 'Custom Category']);

        $this->seed(BaselineSkillSeeder::class);

        $this->assertSame(1, Skill::where('name', 'AutoCAD')->count());
        $this->assertDatabaseHas('skills', [
            'id' => $existing->id,
            'name' => 'AutoCAD',
            'category' => 'Custom Category',
        ]);
    }

    public function test_unrelated_skill_rows_remain_untouched(): void
    {
        $unrelated = Skill::create(['name' => 'Underwater Basket Weaving']);

        $this->seed(BaselineSkillSeeder::class);

        $this->assertDatabaseHas('skills', ['id' => $unrelated->id, 'name' => 'Underwater Basket Weaving']);
    }

    public function test_the_unique_name_constraint_is_respected_throughout(): void
    {
        $this->seed(BaselineSkillSeeder::class);

        $names = Skill::pluck('name');

        $this->assertSame($names->count(), $names->unique()->count());
    }
}
