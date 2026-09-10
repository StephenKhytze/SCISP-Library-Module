<?php

/**
 * SEC-05 — GET /api/library/students must not be readable by ordinary students.
 *
 * The endpoint returns every student in the system. It exists to feed the
 * course-section roster UI (TeacherReservesView), which only renders for
 * Faculty/Teacher, so restricting it to faculty + librarians breaks nothing.
 *
 * Confirmed policy:
 *   Student                 -> 403
 *   Faculty / Teacher       -> 200
 *   Admin / Super Admin     -> 200
 *   missing / unknown identity -> existing MockAuthMiddleware behaviour (401)
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dirAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function dirUser(string $username, string $dbRole): User
{
    return User::create([
        'username' => $username,
        'password' => 'password',
        'role' => $dbRole,
        'status' => 'active',
        'total_fines' => 0,
    ]);
}

const DIRECTORY_ROUTE = '/api/library/students';

beforeEach(function () {
    $this->student = dirUser('dir_student', 'student');
    $this->faculty = dirUser('dir_faculty', 'faculty');
    $this->admin = dirUser('dir_admin', 'administrator');
    $this->superAdmin = dirUser('dir_superadmin', 'administrator');
});

/*
|--------------------------------------------------------------------------
| Denied
|--------------------------------------------------------------------------
*/

test('D1: a student cannot read the student directory', function () {
    $this->withHeaders(dirAs('Student', 'dir_student'))
        ->getJson(DIRECTORY_ROUTE)
        ->assertStatus(403)
        ->assertJsonPath('message', 'Forbidden. Insufficient role privileges.');
});

test('D2: the directory contents are not leaked in the rejection body', function () {
    $response = $this->withHeaders(dirAs('Student', 'dir_student'))
        ->getJson(DIRECTORY_ROUTE)
        ->assertStatus(403);

    expect($response->getContent())->not->toContain('dir_student');
    expect($response->getContent())->not->toContain('dir_faculty');
});

/*
|--------------------------------------------------------------------------
| Allowed
|--------------------------------------------------------------------------
*/

test('D3: a teacher can read the student directory', function () {
    $this->withHeaders(dirAs('Teacher', 'dir_faculty'))
        ->getJson(DIRECTORY_ROUTE)
        ->assertStatus(200)
        ->assertJsonFragment(['username' => 'dir_student']);
});

test('D4: the Faculty role spelling is also accepted', function () {
    $this->withHeaders(dirAs('Faculty', 'dir_faculty'))
        ->getJson(DIRECTORY_ROUTE)
        ->assertStatus(200);
});

test('D5: an admin can read the student directory', function () {
    $this->withHeaders(dirAs('Admin', 'dir_admin'))
        ->getJson(DIRECTORY_ROUTE)
        ->assertStatus(200);
});

test('D6: a super admin can read the student directory', function () {
    $this->withHeaders(dirAs('Super Admin', 'dir_superadmin'))
        ->getJson(DIRECTORY_ROUTE)
        ->assertStatus(200);
});

test('D7: the directory returns only user_id and username', function () {
    $response = $this->withHeaders(dirAs('Teacher', 'dir_faculty'))
        ->getJson(DIRECTORY_ROUTE)
        ->assertStatus(200);

    $first = $response->json('0');

    expect(array_keys($first))->toBe(['user_id', 'username']);
});

/*
|--------------------------------------------------------------------------
| Identity behaviour preserved
|--------------------------------------------------------------------------
*/

test('D8: a missing role header still returns 401', function () {
    $this->getJson(DIRECTORY_ROUTE)
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthorized. Missing X-Mock-Role header.');
});

test('D9: an unknown username still returns 401', function () {
    $this->withHeaders(dirAs('Teacher', 'nobody_xyz'))
        ->getJson(DIRECTORY_ROUTE)
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthorized. Unknown user.');
});

test('D10: a missing username still returns 401', function () {
    $this->withHeaders(['X-Mock-Role' => 'Teacher'])
        ->getJson(DIRECTORY_ROUTE)
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthorized. Missing X-Mock-Username header.');
});

test('D11: no user is created by a rejected directory request', function () {
    $before = User::count();

    $this->withHeaders(dirAs('Student', 'dir_student'))->getJson(DIRECTORY_ROUTE)->assertStatus(403);
    $this->withHeaders(dirAs('Teacher', 'nobody_xyz'))->getJson(DIRECTORY_ROUTE)->assertStatus(401);

    expect(User::count())->toBe($before);
});

/*
|--------------------------------------------------------------------------
| Adjacent roster routes are unchanged
|--------------------------------------------------------------------------
*/

test('D12: the roster add/remove routes still reach their ownership checks', function () {
    // Not 403 from the route gate — a student reaches the controller and is
    // stopped by the existing section-ownership check (404), as before.
    $this->withHeaders(dirAs('Student', 'dir_student'))
        ->postJson('/api/library/sections/999999/students', ['student_id' => $this->student->user_id])
        ->assertStatus(404);
});
