<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('OWNER_EMAIL');
        $password = env('OWNER_PASSWORD');

        if (! $email || ! $password) {
            $this->command->error('Set OWNER_EMAIL and OWNER_PASSWORD in your environment first.');

            return;
        }

        User::updateOrCreate(
            ['email' => strtolower(trim($email))],
            [
                'name' => env('OWNER_NAME', 'Shop Owner'),
                'password' => $password,
                'role' => 'admin',
                'is_active' => true,
            ]
        );
    }
}