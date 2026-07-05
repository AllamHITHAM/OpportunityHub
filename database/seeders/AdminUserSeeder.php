<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@opportunityhub.com'],
            [
                'name' => 'Admin',
                'password' => 'Admin@12345',
            ]
        );

        if ($admin->wasRecentlyCreated) {
            $admin->role = 'admin';
            $admin->status = 'active';
            $admin->save();
        }
    }
}
