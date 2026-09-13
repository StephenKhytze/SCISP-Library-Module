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
        // Admin and Super Admin both store role = 'administrator'; the
        // is_super_admin flag is what separates the management-only persona
        // from the ordinary librarian.
        $personas = [
            // username           => [DB role,        is_super_admin]  (source)
            'DelaCruz_Juan_C1234' => ['student',       false], // Topbar.jsx — Juan Dela Cruz (Student)
            'Santos_Maria_F12'    => ['faculty',       false], // Topbar.jsx — Prof. Maria Santos (Teacher)
            'Admin_User_00001'    => ['administrator', false], // Topbar.jsx — Admin User (Admin)
            'SysAdmin_001'        => ['administrator', true],  // Topbar.jsx — System Admin (Super Admin)
            'guest'               => ['student',       false], // App.jsx auto-injected guest
        ];

        foreach ($personas as $username => [$role, $isSuperAdmin]) {
            $user = User::firstOrCreate(
                ['username' => $username],
                [
                    'role' => $role,
                    'password' => bcrypt('password'),
                    'status' => 'active',
                ]
            );

            // Not mass-assignable by design, so it is always forced here —
            // this seeder is trusted code, a request body is not.
            if ($user->is_super_admin !== $isSuperAdmin) {
                $user->forceFill(['is_super_admin' => $isSuperAdmin])->save();
            }
        }
    }
}
