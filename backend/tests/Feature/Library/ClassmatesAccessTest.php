<?php

/**
 * D-1: GET /api/library/sections/{id}/classmates
 *
 * Confirmed access rule:
 *   enrolled Student   -> 200 (their own section only)
 *   owning Faculty     -> 200
 *   other Faculty      -> 403
 *   unrelated Student  -> 403
 *   Admin / Super Admin-> 200
 *
 * This must NOT become a second route to the whole student directory, which
 * SEC-05 deliberately restricted.
 */

use App\Models\CourseSection;
use App\Models\CourseSectionStudent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cmAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function cmUser(string $username, string $dbRole, bool $isSuperAdmin = false): User
{
    $user = User::create([
        'username' => $username,
        'password' => 'password',
        'role' => $dbRole,
        'status' => 'active',
        'total_fines' => 0,
    ]);

    // Not mass-assignable: forced here because this is trusted test setup.
    $user->forceFill(['is_super_admin' => $isSuperAdmin])->save();

    return $user;
}

beforeEach(function () {
    $this->teacher = cmUser('cm_teacher', 'faculty');
    $this->otherTeacher = cmUser('cm_teacher2', 'faculty');
    $this->student = cmUser('cm_student', 'student');
    $this->classmate = cmUser('cm_classmate', 'student');
    $this->outsider = cmUser('cm_outsider', 'student');
    $this->admin = cmUser('cm_admin', 'administrator');
    $this->superAdmin = cmUser('cm_superadmin', 'administrator', true);

    $this->section = CourseSection::create([
        'teacher_id' => $this->teacher->user_id,
        'name' => 'Data Structures',
    ]);

    foreach ([$this->student, $this->classmate] as $enrolled) {
        CourseSectionStudent::create([
            'section_id' => $this->section->section_id,
            'student_id' => $enrolled->user_id,
        ]);
    }

    $this->route = "/api/library/sections/{$this->section->section_id}/classmates";
});

/*
|--------------------------------------------------------------------------
| Allowed
|--------------------------------------------------------------------------
*/

test('CM1: an enrolled student sees their own section roster', function () {
    $this->withHeaders(cmAs('Student', 'cm_student'))
        ->getJson($this->route)
        ->assertStatus(200)
        ->assertJsonPath('section_name', 'Data Structures')
        ->assertJsonCount(2, 'classmates')
        ->assertJsonFragment(['username' => 'cm_classmate']);
});

test('CM2: the owning teacher sees the roster', function () {
    $this->withHeaders(cmAs('Teacher', 'cm_teacher'))
        ->getJson($this->route)
        ->assertStatus(200)
        ->assertJsonCount(2, 'classmates');
});

test('CM3: an admin sees the roster', function () {
    $this->withHeaders(cmAs('Admin', 'cm_admin'))
        ->getJson($this->route)
        ->assertStatus(200);
});

test('CM4: a super admin sees the roster', function () {
    $this->withHeaders(cmAs('Super Admin', 'cm_superadmin'))
        ->getJson($this->route)
        ->assertStatus(200);
});

/*
|--------------------------------------------------------------------------
| Denied
|--------------------------------------------------------------------------
*/

test('CM5: a student from another section is refused', function () {
    $this->withHeaders(cmAs('Student', 'cm_outsider'))
        ->getJson($this->route)
        ->assertStatus(403)
        ->assertJsonPath('message', 'You are not enrolled in this course section.');
});

test('CM6: a teacher who does not own the section is refused', function () {
    $this->withHeaders(cmAs('Teacher', 'cm_teacher2'))
        ->getJson($this->route)
        ->assertStatus(403);
});

test('CM7: the roster is not leaked in the rejection body', function () {
    $response = $this->withHeaders(cmAs('Student', 'cm_outsider'))
        ->getJson($this->route)
        ->assertStatus(403);

    expect($response->getContent())->not->toContain('cm_classmate');
    expect($response->getContent())->not->toContain('cm_student');
});

test('CM8: an unknown section is a 404, not a roster', function () {
    $this->withHeaders(cmAs('Admin', 'cm_admin'))
        ->getJson('/api/library/sections/999999/classmates')
        ->assertStatus(404);
});

test('CM9: identity is still required', function () {
    $this->getJson($this->route)->assertStatus(401);

    $this->withHeaders(cmAs('Student', 'nobody_xyz'))
        ->getJson($this->route)
        ->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| Shape and scope
|--------------------------------------------------------------------------
*/

test('CM10: only user_id and username are returned', function () {
    $first = $this->withHeaders(cmAs('Student', 'cm_student'))
        ->getJson($this->route)
        ->assertStatus(200)
        ->json('classmates.0');

    expect(array_keys($first))->toBe(['user_id', 'username']);
});

test('CM11: only this sections students are returned, not every student', function () {
    $response = $this->withHeaders(cmAs('Student', 'cm_student'))
        ->getJson($this->route)
        ->assertStatus(200);

    // cm_outsider exists but is in no section here.
    expect($response->getContent())->not->toContain('cm_outsider');
    expect($response->json('classmates'))->toHaveCount(2);
});

test('CM12: this route does not reopen the directory SEC-05 closed', function () {
    // The restricted directory is still refused for a student...
    $this->withHeaders(cmAs('Student', 'cm_student'))
        ->getJson('/api/library/students')
        ->assertStatus(403);

    // ...while the section-scoped roster is allowed.
    $this->withHeaders(cmAs('Student', 'cm_student'))
        ->getJson($this->route)
        ->assertStatus(200);
});
