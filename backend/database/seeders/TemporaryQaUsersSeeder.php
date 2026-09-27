<?php

namespace Database\Seeders;

use App\Models\CourseSection;
use App\Models\CourseSectionStudent;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * TEMPORARY — QA identities for multi-user Library testing.
 *
 * These exist only until the real Accounts/Auth module is integrated. They
 * are plain rows in the existing users table, resolved by mock auth through
 * X-Mock-Username / X-Mock-Role exactly like the MockPersonaSeeder personas.
 * Nothing here changes auth, JWT, schema or any business rule.
 *
 * Also creates two QA-only course sections so relationship rules can be
 * exercised without touching real/UAT sections:
 *
 *   QA-A  owned by qa_faculty_01, enrolled: qa_student_01, qa_student_02
 *   QA-B  owned by qa_faculty_02, no students
 *
 * qa_student_03 is deliberately enrolled nowhere — the outsider for testing
 * classmates and reserve eligibility.
 *
 * Idempotent and additive: running it twice creates no duplicates, and it
 * never deletes or truncates. Re-running re-asserts role and status only;
 * it does not reset fines, loans, holds or enrolments added during testing.
 *
 * Run with:
 *   php artisan db:seed --class=TemporaryQaUsersSeeder
 *
 * NOTE: do NOT run `php artisan db:seed` — DatabaseSeeder truncates
 * users, books and book_copies.
 *
 * To remove later, delete this file and the qa_* rows. Nothing else
 * references them.
 */
class TemporaryQaUsersSeeder extends Seeder
{
    public function run(): void
    {
        // username => [DB role, is_super_admin]. Admin and Super Admin both
        // store 'administrator'; the flag is what separates them, as for the
        // existing personas.
        $users = [
            'qa_student_01'    => ['student',       false],
            'qa_student_02'    => ['student',       false],
            'qa_student_03'    => ['student',       false],
            'qa_faculty_01'    => ['faculty',       false],
            'qa_faculty_02'    => ['faculty',       false],
            'qa_admin_01'      => ['administrator', false],
            'qa_superadmin_01' => ['administrator', true],
        ];

        $ids = [];

        foreach ($users as $username => [$role, $isSuperAdmin]) {
            $user = User::firstOrNew(['username' => $username]);

            $user->role = $role;
            $user->status = 'active';

            if (! $user->exists) {
                // Mock auth never checks a password. A random one means these
                // rows can never be signed into with a guessable credential
                // once real authentication is switched on.
                $user->password = bcrypt(Str::random(40));
                $user->total_fines = 0;
            }

            $user->save();

            // Not mass-assignable by design, so it is set explicitly here —
            // this seeder is trusted code, a request body is not.
            if ((bool) $user->is_super_admin !== $isSuperAdmin) {
                $user->forceFill(['is_super_admin' => $isSuperAdmin])->save();
            }

            $ids[$username] = $user->user_id;
        }

        // Keyed on name AND owner, so an unrelated section that happens to be
        // called "QA-A" is never adopted or modified.
        $qaA = CourseSection::firstOrCreate(
            ['name' => 'QA-A', 'teacher_id' => $ids['qa_faculty_01']]
        );

        CourseSection::firstOrCreate(
            ['name' => 'QA-B', 'teacher_id' => $ids['qa_faculty_02']]
        );

        foreach (['qa_student_01', 'qa_student_02'] as $username) {
            CourseSectionStudent::firstOrCreate([
                'section_id' => $qaA->section_id,
                'student_id' => $ids[$username],
            ]);
        }
    }
}
