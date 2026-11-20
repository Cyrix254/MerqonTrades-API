<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'owner@merqontrades.test'],
            [
                'name' => 'Shop Owner',
                'password' => 'password',
                'role' => 'admin',
                'is_active' => true,
            ]
        );
    }
}