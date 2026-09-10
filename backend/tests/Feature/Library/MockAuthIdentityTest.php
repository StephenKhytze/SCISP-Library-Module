<?php

/**
 * LIBRARY_TASK_IDENTITY — mock authentication identity hardening.
 *
 * Verifies that mock auth RESOLVES a pre-existing, active user whose stored role
 * matches the supplied role, or rejects the request. It must never fall back to
 * '2012-00000-SYS' and must never create a user during a request.
 *
 * Expected behavior:
 *   A. missing X-Mock-Role                  -> 401 (pre-existing)
 *   B. missing X-Mock-Username              -> 401, no fallback, no user created
 *   C. unknown X-Mock-Username              -> 401, no user created
 *   D. known active user + matching role    -> proceeds, correct user_id attached
 *   E. known user + mismatched role         -> 403 (no privilege escalation)
 *   F. known user with status = disabled    -> 403
 */

use App\Models\CourseSection;
use App\Models\User;
use Database\Seeders\MockPersonaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

// tests/Pest.php has RefreshDatabase commented out, so apply it explicitly.
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
| Built inline. DatabaseSeeder is deliberately NOT used: it truncates users,
| books and book_copies and issues MySQL-only SET FOREIGN_KEY_CHECKS.
*/

function identityAs(string $role, ?string $username = null): array
{
    $headers = ['X-Mock-Role' => $role];

    if ($username !== null) {
        $headers['X-Mock-Username'] = $username;
    }

    return $headers;
}

function identityUser(string $username, string $dbRole, string $status = 'active'): User
{
    return User::create([
        'username' => $username,
        'password' => 'password',
        'role' => $dbRole,
        'status' => $status,
    ]);
}

// A route behind the bare middleware (any authenticated role may reach it).
const IDENTITY_OPEN_ROUTE = '/api/library/books';

// A route behind MockAuthMiddleware:Super Admin,Admin.
const IDENTITY_ADMIN_ROUTE = '/api/library/circulation';

beforeEach(function () {
    $this->student = identityUser('DelaCruz_Juan_C1234', 'student');
    $this->teacher = identityUser('Santos_Maria_F12', 'faculty');
    $this->admin = identityUser('Admin_User_00001', 'administrator');
    $this->superAdmin = identityUser('SysAdmin_001', 'administrator');
});

/*
|--------------------------------------------------------------------------
| A / B / C — rejection cases
|--------------------------------------------------------------------------
*/

test('I1: a request with no X-Mock-Role is rejected with 401', function () {
    $this->getJson(IDENTITY_OPEN_ROUTE)
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthorized. Missing X-Mock-Role header.');
});

test('I2: a request with a role but no X-Mock-Username is rejected with 401', function () {
    $this->withHeaders(identityAs('Student'))
        ->getJson(IDENTITY_OPEN_ROUTE)
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthorized. Missing X-Mock-Username header.');
});

test('I3: an empty X-Mock-Username is rejected with 401', function () {
    $this->withHeaders(identityAs('Student', ''))
        ->getJson(IDENTITY_OPEN_ROUTE)
        ->assertStatus(401);
});

test('I4: an unknown X-Mock-Username is rejected with 401', function () {
    $this->withHeaders(identityAs('Student', 'nobody_xyz'))
        ->getJson(IDENTITY_OPEN_ROUTE)
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthorized. Unknown user.');
});

/*
|--------------------------------------------------------------------------
| No user is ever created during a request
|--------------------------------------------------------------------------
*/

test('I5: an unknown username creates no user', function () {
    $before = User::count();

    $this->withHeaders(identityAs('Student', 'nobody_xyz'))
        ->getJson(IDENTITY_OPEN_ROUTE)
        ->assertStatus(401);

    expect(User::count())->toBe($before);
    expect(User::where('username', 'nobody_xyz')->exists())->toBeFalse();
});

test('I6: a missing username creates no user', function () {
    $before = User::count();

    $this->withHeaders(identityAs('Teacher'))
        ->getJson(IDENTITY_OPEN_ROUTE)
        ->assertStatus(401);

    expect(User::count())->toBe($before);
});

/*
|--------------------------------------------------------------------------
| The 2012-00000-SYS fallback is gone
|--------------------------------------------------------------------------
*/

test('I7: a missing username does not create the 2012-00000-SYS fallback identity', function () {
    $this->withHeaders(identityAs('Teacher'))
        ->getJson(IDENTITY_OPEN_ROUTE)
        ->assertStatus(401);

    expect(User::where('username', '2012-00000-SYS')->exists())->toBeFalse();
});

test('I8: a missing username is rejected even when 2012-00000-SYS exists', function () {
    // The sharpest test of the defect: this passes against the old code only
    // because the fallback silently resolved. It must now be rejected.
    identityUser('2012-00000-SYS', 'faculty');

    $this->withHeaders(identityAs('Teacher'))
        ->getJson(IDENTITY_OPEN_ROUTE)
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthorized. Missing X-Mock-Username header.');
});

/*
|--------------------------------------------------------------------------
| D — valid personas are allowed
|--------------------------------------------------------------------------
*/

test('I9: a valid Student is allowed', function () {
    $this->withHeaders(identityAs('Student', 'DelaCruz_Juan_C1234'))
        ->getJson(IDENTITY_OPEN_ROUTE)
        ->assertStatus(200);
});

test('I10: a valid Teacher is allowed', function () {
    $this->withHeaders(identityAs('Teacher', 'Santos_Maria_F12'))
        ->getJson(IDENTITY_OPEN_ROUTE)
        ->assertStatus(200);
});

test('I11: a valid Admin is allowed on an admin route', function () {
    $this->withHeaders(identityAs('Admin', 'Admin_User_00001'))
        ->getJson(IDENTITY_ADMIN_ROUTE)
        ->assertStatus(200);
});

test('I12: a valid Super Admin is allowed on an admin route', function () {
    $this->withHeaders(identityAs('Super Admin', 'SysAdmin_001'))
        ->getJson(IDENTITY_ADMIN_ROUTE)
        ->assertStatus(200);
});

test('I13: a Student is refused an admin route by the route gate', function () {
    $this->withHeaders(identityAs('Student', 'DelaCruz_Juan_C1234'))
        ->getJson(IDENTITY_ADMIN_ROUTE)
        ->assertStatus(403)
        ->assertJsonPath('message', 'Forbidden. Insufficient role privileges.');
});

/*
|--------------------------------------------------------------------------
| E — role mismatch is rejected (no privilege escalation)
|--------------------------------------------------------------------------
*/

test('I14: Admin role with a student username is rejected with 403', function () {
    $this->withHeaders(identityAs('Admin', 'DelaCruz_Juan_C1234'))
        ->getJson(IDENTITY_ADMIN_ROUTE)
        ->assertStatus(403)
        ->assertJsonPath('message', 'Forbidden. Supplied role does not match the user account.');
});

test('I15: Teacher role with an administrator username is rejected with 403', function () {
    $this->withHeaders(identityAs('Teacher', 'Admin_User_00001'))
        ->getJson(IDENTITY_OPEN_ROUTE)
        ->assertStatus(403)
        ->assertJsonPath('message', 'Forbidden. Supplied role does not match the user account.');
});

test('I16: Student role with a faculty username is rejected with 403', function () {
    $this->withHeaders(identityAs('Student', 'Santos_Maria_F12'))
        ->getJson(IDENTITY_OPEN_ROUTE)
        ->assertStatus(403);
});

test('I17: Admin and Super Admin both satisfy the administrator stored role', function () {
    // Both map to 'administrator', so neither is a mismatch.
    $this->withHeaders(identityAs('Admin', 'SysAdmin_001'))
        ->getJson(IDENTITY_ADMIN_ROUTE)
        ->assertStatus(200);

    $this->withHeaders(identityAs('Super Admin', 'Admin_User_00001'))
        ->getJson(IDENTITY_ADMIN_ROUTE)
        ->assertStatus(200);
});

/*
|--------------------------------------------------------------------------
| F — disabled users are rejected
|--------------------------------------------------------------------------
*/

test('I18: a disabled user is rejected with 403', function () {
    identityUser('disabled_student', 'student', 'disabled');

    $this->withHeaders(identityAs('Student', 'disabled_student'))
        ->getJson(IDENTITY_OPEN_ROUTE)
        ->assertStatus(403)
        ->assertJsonPath('message', 'Forbidden. User account is disabled.');
});

test('I19: a disabled user status is not modified by the rejected request', function () {
    $user = identityUser('disabled_teacher', 'faculty', 'disabled');

    $this->withHeaders(identityAs('Teacher', 'disabled_teacher'))
        ->getJson(IDENTITY_OPEN_ROUTE)
        ->assertStatus(403);

    expect($user->fresh()->status)->toBe('disabled');
});

/*
|--------------------------------------------------------------------------
| Ownership attribution
|--------------------------------------------------------------------------
*/

test('I20: a created record is attributed to the acting user, not a fallback', function () {
    identityUser('2012-00000-SYS', 'faculty');

    $this->withHeaders(identityAs('Teacher', 'Santos_Maria_F12'))
        ->postJson('/api/library/sections', ['name' => 'Attribution Check'])
        ->assertStatus(201);

    $section = CourseSection::where('name', 'Attribution Check')->first();

    expect($section)->not->toBeNull();
    expect((int) $section->teacher_id)->toBe((int) $this->teacher->user_id);
    expect((int) $section->teacher_id)->not->toBe((int) User::where('username', '2012-00000-SYS')->first()->user_id);
});

test('I21: two teachers remain separate identities', function () {
    $other = identityUser('another_teacher', 'faculty');

    $this->withHeaders(identityAs('Teacher', 'Santos_Maria_F12'))
        ->postJson('/api/library/sections', ['name' => 'Section A'])
        ->assertStatus(201);

    $this->withHeaders(identityAs('Teacher', 'another_teacher'))
        ->postJson('/api/library/sections', ['name' => 'Section B'])
        ->assertStatus(201);

    $a = CourseSection::where('name', 'Section A')->first();
    $b = CourseSection::where('name', 'Section B')->first();

    expect((int) $a->teacher_id)->toBe((int) $this->teacher->user_id);
    expect((int) $b->teacher_id)->toBe((int) $other->user_id);
    expect((int) $a->teacher_id)->not->toBe((int) $b->teacher_id);
});

/*
|--------------------------------------------------------------------------
| MockPersonaSeeder
|--------------------------------------------------------------------------
*/

test('I22: MockPersonaSeeder creates every required persona with the correct role', function () {
    User::query()->delete();

    $this->seed(MockPersonaSeeder::class);

    $expected = [
        'DelaCruz_Juan_C1234' => 'student',
        'Santos_Maria_F12' => 'faculty',
        'Admin_User_00001' => 'administrator',
        'SysAdmin_001' => 'administrator',
        'guest' => 'student',
    ];

    foreach ($expected as $username => $role) {
        $user = User::where('username', $username)->first();
        expect($user)->not->toBeNull("persona {$username} was not seeded");
        expect($user->role)->toBe($role);
        expect($user->status)->toBe('active');
    }
});

test('I23: MockPersonaSeeder is idempotent', function () {
    User::query()->delete();

    $this->seed(MockPersonaSeeder::class);
    $afterFirst = User::count();

    $this->seed(MockPersonaSeeder::class);

    expect(User::count())->toBe($afterFirst);
});

/*
|--------------------------------------------------------------------------
| Task 001 regression — reserve authorization still gated
|--------------------------------------------------------------------------
*/

test('I24: Task 001 - admin persona still reaches the reserve status route', function () {
    // 404 (no such reserve) proves the request passed both middleware layers;
    // it must not be 401 or 403.
    $this->withHeaders(identityAs('Admin', 'Admin_User_00001'))
        ->putJson('/api/library/reserves/999999/status', ['status' => 'approved'])
        ->assertStatus(404);
});

test('I25: Task 001 - student persona is still refused the reserve status route', function () {
    $this->withHeaders(identityAs('Student', 'DelaCruz_Juan_C1234'))
        ->putJson('/api/library/reserves/999999/status', ['status' => 'approved'])
        ->assertStatus(403);
});
