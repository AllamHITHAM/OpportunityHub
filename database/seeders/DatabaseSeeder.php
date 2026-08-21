<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(AdminUserSeeder::class);

        // Phase 8A-6.1: safe to include here (unlike the Test User line
        // above) -- unlike that unconditional create(), this seeder is
        // fully idempotent and never duplicates or destroys a Skill row
        // on a repeat run.
        $this->call(BaselineSkillSeeder::class);
    }
}
