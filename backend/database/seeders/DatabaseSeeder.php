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
        User::factory()->create([
            'username' => 'DelaCruz_Juan_C1234',
            'password' => \Illuminate\Support\Facades\Hash::make('secretpassword123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        User::factory()->create([
            'username' => 'Admin_User_00001',
            'password' => \Illuminate\Support\Facades\Hash::make('secretpassword123'),
            'role' => 'administrator',
            'status' => 'active',
        ]);
    }
}
