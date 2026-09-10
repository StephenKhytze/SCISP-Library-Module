<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds the mock-auth personas the frontend can send as X-Mock-Username.
 *
 * Additive and idempotent by design: it creates missing users and never
 * truncates, updates or deletes. Mock auth resolves users but no longer
 * creates them (LIBRARY_TASK_IDENTITY), so these rows must exist up front.
 *
 * Run with:
 *   php artisan db:seed --class=MockPersonaSeeder
 *
 * NOTE: do NOT run `php artisan db:seed` — DatabaseSeeder truncates
 * users, books and book_copies.
 */
class MockPersonaSeeder extends Seeder
{
    public function run(): void
    {
        $personas = [
            // username              => DB role      (source)
            'DelaCruz_Juan_C1234'    => 'student',       // Topbar.jsx — Juan Dela Cruz (Student)
            'Santos_Maria_F12'       => 'faculty',       // Topbar.jsx — Prof. Maria Santos (Teacher)
            'Admin_User_00001'       => 'administrator', // Topbar.jsx — Admin User (Admin)
            'SysAdmin_001'           => 'administrator', // Topbar.jsx — System Admin (Super Admin)
            'guest'                  => 'student',       // App.jsx auto-injected guest
        ];

        foreach ($personas as $username => $role) {
            User::firstOrCreate(
                ['username' => $username],
                [
                    'role' => $role,
                    'password' => bcrypt('password'),
                    'status' => 'active',
                ]
            );
        }
    }
}
