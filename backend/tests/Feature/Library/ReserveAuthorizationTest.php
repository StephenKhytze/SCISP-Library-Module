<?php

/**
 * LIBRARY TASK 001 — SEC-02 + API-01
 *
 * Regression guard for course reserve authorization.
 *
 * Covers:
 *   PUT  /api/library/reserves/{id}/status   (approve / deny / release)
 *   POST /api/library/reserves/{id}/allocate (allocate physical copies)
 *
 * Authorization matrix under test:
 *   - approve / deny  : Admin + Super Admin only
 *   - release         : Admin + Super Admin, or the owning faculty member
 *   - allocate        : Admin + Super Admin only
 *   - Students        : denied on all of the above
 *
 * Ownership is defined as course_reserves.user_id == the caller's resolved user_id.
 */

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\CourseReserve;
use App\Models\CourseSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

// tests/Pest.php has RefreshDatabase commented out, so it is applied here explicitly.
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
| Built inline. DatabaseSeeder is deliberately NOT used: it issues
| `SET FOREIGN_KEY_CHECKS=0`, which is MySQL-only and fails on the
| in-memory SQLite connection that phpunit.xml forces for tests.
*/

/** Mirrors the headers frontend/src/api.js sends. */
function reserveAuthAs(string $role, string $username): array
{
    return [
        'X-Mock-Role' => $role,
        'X-Mock-Username' => $username,
    ];
}

function reserveAuthUser(string $username, string $dbRole): User
{
    return User::create([
        'username' => $username,
        'password' => 'password',
        'role' => $dbRole,
        'status' => 'active',
    ]);
}

function reserveAuthBook(): Book
{
    return Book::create([
        'book_title' => 'Test Title',
        'author' => 'Test Author',
        'category' => 'Computer Science',
        'isbn' => 'ISBN-'.uniqid(),
        'physical_location' => 'Shelf A1',
        'total_copies' => 0,
    ]);
}

/**
 * Creates a reserve owned by $owner, plus $copyCount copies of its book already
 * allocated to it (book_copies.reserve_id set), which is the state a release acts on.
 */
function reserveAuthReserve(User $owner, string $status = 'pending', int $copyCount = 2): CourseReserve
{
    $book = reserveAuthBook();

    $section = CourseSection::create([
        'teacher_id' => $owner->user_id,
        'name' => 'Section for '.$owner->username,
    ]);

    $reserve = CourseReserve::create([
        'section_id' => $section->section_id,
        'book_id' => $book->book_id,
        'user_id' => $owner->user_id,
        'copies_requested' => $copyCount,
        'status' => $status,
    ]);

    for ($i = 0; $i < $copyCount; $i++) {
        BookCopy::create([
            'book_id' => $book->book_id,
            'condition' => 'good',
            'availability_status' => 'available',
            'reserve_id' => $reserve->reserve_id,
        ]);
    }

    return $reserve;
}

beforeEach(function () {
    $this->student = reserveAuthUser('student', 'student');
    $this->teacherOwner = reserveAuthUser('faculty_owner', 'faculty');
    $this->teacherOther = reserveAuthUser('faculty_other', 'faculty');
    $this->admin = reserveAuthUser('admin', 'administrator');
    $this->superAdmin = reserveAuthUser('superadmin', 'administrator');
});

/*
|--------------------------------------------------------------------------
| Student — must be denied everything (T1, T2, T3)
|--------------------------------------------------------------------------
*/

test('T1: student cannot approve a reserve', function () {
    $reserve = reserveAuthReserve($this->teacherOwner);

    $this->withHeaders(reserveAuthAs('Student', 'student'))
        ->putJson("/api/library/reserves/{$reserve->reserve_id}/status", ['status' => 'approved'])
        ->assertStatus(403);

    expect($reserve->fresh()->status)->toBe('pending');
});

test('T2: student cannot release a reserve', function () {
    $reserve = reserveAuthReserve($this->teacherOwner, 'approved');

    $this->withHeaders(reserveAuthAs('Student', 'student'))
        ->putJson("/api/library/reserves/{$reserve->reserve_id}/status", ['status' => 'released'])
        ->assertStatus(403);

    expect($reserve->fresh()->status)->toBe('approved');
    // Copies must still be attached to the reserve.
    expect(BookCopy::where('reserve_id', $reserve->reserve_id)->count())->toBe(2);
});

test('T3: student cannot allocate copies', function () {
    $reserve = reserveAuthReserve($this->teacherOwner, 'approved', 0);
    $book = reserveAuthBook();
    $freeCopy = BookCopy::create([
        'book_id' => $book->book_id,
        'condition' => 'good',
        'availability_status' => 'available',
    ]);

    $this->withHeaders(reserveAuthAs('Student', 'student'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate", ['copy_ids' => [$freeCopy->copy_id]])
        ->assertStatus(403);

    expect($freeCopy->fresh()->reserve_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Teacher (owner) — release only (T4, T5)
|--------------------------------------------------------------------------
*/

test('T4: owning teacher cannot approve their own reserve', function () {
    $reserve = reserveAuthReserve($this->teacherOwner);

    $this->withHeaders(reserveAuthAs('Teacher', 'faculty_owner'))
        ->putJson("/api/library/reserves/{$reserve->reserve_id}/status", ['status' => 'approved'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Forbidden. Only an administrator can approve or deny a course reserve.');

    expect($reserve->fresh()->status)->toBe('pending');
});

test('T5: owning teacher can release their own reserve', function () {
    $reserve = reserveAuthReserve($this->teacherOwner, 'approved');

    $this->withHeaders(reserveAuthAs('Teacher', 'faculty_owner'))
        ->putJson("/api/library/reserves/{$reserve->reserve_id}/status", ['status' => 'released'])
        ->assertStatus(200);

    expect($reserve->fresh()->status)->toBe('released');
    expect(BookCopy::where('reserve_id', $reserve->reserve_id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Teacher (non-owner) — denied (T6, T7)
|--------------------------------------------------------------------------
*/

test('T6: non-owning teacher cannot release another teachers reserve', function () {
    $reserve = reserveAuthReserve($this->teacherOwner, 'approved');

    $this->withHeaders(reserveAuthAs('Teacher', 'faculty_other'))
        ->putJson("/api/library/reserves/{$reserve->reserve_id}/status", ['status' => 'released'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Forbidden. You are not the owner of this course reserve.');

    expect($reserve->fresh()->status)->toBe('approved');
    expect(BookCopy::where('reserve_id', $reserve->reserve_id)->count())->toBe(2);
});

test('T7: teacher cannot allocate copies', function () {
    $reserve = reserveAuthReserve($this->teacherOwner, 'approved', 0);
    $book = reserveAuthBook();
    $freeCopy = BookCopy::create([
        'book_id' => $book->book_id,
        'condition' => 'good',
        'availability_status' => 'available',
    ]);

    $this->withHeaders(reserveAuthAs('Teacher', 'faculty_owner'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate", ['copy_ids' => [$freeCopy->copy_id]])
        ->assertStatus(403);

    expect($freeCopy->fresh()->reserve_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Admin (T8, T9) and Super Admin (T10, T11)
|--------------------------------------------------------------------------
*/

test('T8: admin can approve a reserve', function () {
    $reserve = reserveAuthReserve($this->teacherOwner);

    $this->withHeaders(reserveAuthAs('Admin', 'admin'))
        ->putJson("/api/library/reserves/{$reserve->reserve_id}/status", ['status' => 'approved'])
        ->assertStatus(200);

    expect($reserve->fresh()->status)->toBe('approved');
});

test('T9: admin can release a reserve owned by a different teacher', function () {
    $reserve = reserveAuthReserve($this->teacherOwner, 'approved');

    $this->withHeaders(reserveAuthAs('Admin', 'admin'))
        ->putJson("/api/library/reserves/{$reserve->reserve_id}/status", ['status' => 'released'])
        ->assertStatus(200);

    expect($reserve->fresh()->status)->toBe('released');
    expect(BookCopy::where('reserve_id', $reserve->reserve_id)->count())->toBe(0);
});

test('T10: super admin can deny a reserve', function () {
    $reserve = reserveAuthReserve($this->teacherOwner);

    $this->withHeaders(reserveAuthAs('Super Admin', 'superadmin'))
        ->putJson("/api/library/reserves/{$reserve->reserve_id}/status", ['status' => 'denied'])
        ->assertStatus(200);

    expect($reserve->fresh()->status)->toBe('denied');
});

test('T11: super admin can allocate copies', function () {
    $reserve = reserveAuthReserve($this->teacherOwner, 'approved', 0);

    // The copy must belong to the reserve's own title. Allocation now rejects
    // copies of an unrelated book (simplified-scope sanity check), so this
    // fixture uses $reserve->book_id rather than a freshly created book.
    $freeCopy = BookCopy::create([
        'book_id' => $reserve->book_id,
        'condition' => 'good',
        'availability_status' => 'available',
    ]);

    $this->withHeaders(reserveAuthAs('Super Admin', 'superadmin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate", ['copy_ids' => [$freeCopy->copy_id]])
        ->assertStatus(200);

    expect((int) $freeCopy->fresh()->reserve_id)->toBe((int) $reserve->reserve_id);
});

/*
|--------------------------------------------------------------------------
| Edge cases (T12 – T15)
|--------------------------------------------------------------------------
*/

test('T12: admin gets 404 for a nonexistent reserve', function () {
    $this->withHeaders(reserveAuthAs('Admin', 'admin'))
        ->putJson('/api/library/reserves/999999/status', ['status' => 'approved'])
        ->assertStatus(404);
});

test('T13: student gets 403 not 404 for a nonexistent reserve', function () {
    // Proves the role gate runs upstream of the model lookup and leaks no existence info.
    $this->withHeaders(reserveAuthAs('Student', 'student'))
        ->putJson('/api/library/reserves/999999/status', ['status' => 'approved'])
        ->assertStatus(403);
});

test('T14: a request with no role header is rejected with 401', function () {
    $reserve = reserveAuthReserve($this->teacherOwner);

    $this->putJson("/api/library/reserves/{$reserve->reserve_id}/status", ['status' => 'approved'])
        ->assertStatus(401);
});

test('T15: admin gets 422 for a malformed status value', function () {
    $reserve = reserveAuthReserve($this->teacherOwner);

    $this->withHeaders(reserveAuthAs('Admin', 'admin'))
        ->putJson("/api/library/reserves/{$reserve->reserve_id}/status", ['status' => 'bogus'])
        ->assertStatus(422);

    expect($reserve->fresh()->status)->toBe('pending');
});

/*
|==========================================================================
| LIBRARY_TASK_002 — SEC-04 + SEC-03
|==========================================================================
| SEC-04: GET /reserves is Admin/Super Admin only (it exposes every reserve
|         system-wide, including teacher_to_admin_note).
| SEC-03: POST /reserves is faculty/admin only, and faculty may only file
|         against a section they own.
|
| Appended below T1-T15. Reuses the fixtures and helpers defined above.
*/

/*
|--------------------------------------------------------------------------
| SEC-04 — reading all reserves (S1-S5)
|--------------------------------------------------------------------------
*/

test('S1: student cannot list all reserves', function () {
    reserveAuthReserve($this->teacherOwner);

    $this->withHeaders(reserveAuthAs('Student', 'student'))
        ->getJson('/api/library/reserves')
        ->assertStatus(403);
});

test('S2: teacher cannot list all reserves', function () {
    reserveAuthReserve($this->teacherOwner);

    $this->withHeaders(reserveAuthAs('Teacher', 'faculty_owner'))
        ->getJson('/api/library/reserves')
        ->assertStatus(403);
});

test('S3: admin can list all reserves', function () {
    reserveAuthReserve($this->teacherOwner);

    $this->withHeaders(reserveAuthAs('Admin', 'admin'))
        ->getJson('/api/library/reserves')
        ->assertStatus(200)
        ->assertJsonCount(1);
});

test('S4: super admin can list all reserves', function () {
    reserveAuthReserve($this->teacherOwner);

    $this->withHeaders(reserveAuthAs('Super Admin', 'superadmin'))
        ->getJson('/api/library/reserves')
        ->assertStatus(200);
});

test('S5: listing reserves without a role header is rejected with 401', function () {
    $this->getJson('/api/library/reserves')
        ->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| SEC-03 — creating a reserve (S6-S12)
|--------------------------------------------------------------------------
*/

test('S6: teacher can request a reserve for their own section', function () {
    $existing = reserveAuthReserve($this->teacherOwner);
    $before = CourseReserve::count();

    $this->withHeaders(reserveAuthAs('Teacher', 'faculty_owner'))
        ->postJson('/api/library/reserves', [
            'section_id' => $existing->section_id,
            'book_id' => $existing->book_id,
            'copies_requested' => 2,
        ])
        ->assertStatus(201);

    expect(CourseReserve::count())->toBe($before + 1);

    $created = CourseReserve::latest('reserve_id')->first();
    expect((int) $created->user_id)->toBe((int) $this->teacherOwner->user_id);
    expect($created->status)->toBe('pending');
});

test('S7: teacher cannot request a reserve for another teachers section', function () {
    $existing = reserveAuthReserve($this->teacherOwner);
    $before = CourseReserve::count();

    $this->withHeaders(reserveAuthAs('Teacher', 'faculty_other'))
        ->postJson('/api/library/reserves', [
            'section_id' => $existing->section_id,
            'book_id' => $existing->book_id,
            'copies_requested' => 1,
        ])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Forbidden. You can only request a course reserve for your own section.');

    // The security property: no row was created.
    expect(CourseReserve::count())->toBe($before);
});

test('S8: student cannot request a reserve', function () {
    $existing = reserveAuthReserve($this->teacherOwner);
    $before = CourseReserve::count();

    $this->withHeaders(reserveAuthAs('Student', 'student'))
        ->postJson('/api/library/reserves', [
            'section_id' => $existing->section_id,
            'book_id' => $existing->book_id,
            'copies_requested' => 1,
        ])
        ->assertStatus(403);

    expect(CourseReserve::count())->toBe($before);
});

test('S9: admin can request a reserve for any section (ownership bypass)', function () {
    $existing = reserveAuthReserve($this->teacherOwner);
    $before = CourseReserve::count();

    $this->withHeaders(reserveAuthAs('Admin', 'admin'))
        ->postJson('/api/library/reserves', [
            'section_id' => $existing->section_id,
            'book_id' => $existing->book_id,
            'copies_requested' => 1,
        ])
        ->assertStatus(201);

    expect(CourseReserve::count())->toBe($before + 1);
});

test('S10: a nonexistent section_id is rejected with 422 before the ownership check', function () {
    $existing = reserveAuthReserve($this->teacherOwner);

    $this->withHeaders(reserveAuthAs('Teacher', 'faculty_owner'))
        ->postJson('/api/library/reserves', [
            'section_id' => 999999,
            'book_id' => $existing->book_id,
            'copies_requested' => 1,
        ])
        ->assertStatus(422);
});

test('S11: creating a reserve without a role header is rejected with 401', function () {
    $existing = reserveAuthReserve($this->teacherOwner);

    $this->postJson('/api/library/reserves', [
        'section_id' => $existing->section_id,
        'book_id' => $existing->book_id,
        'copies_requested' => 1,
    ])->assertStatus(401);
});

test('S12: Faculty role behaves as Teacher for section ownership', function () {
    $existing = reserveAuthReserve($this->teacherOwner);
    $before = CourseReserve::count();

    $this->withHeaders(reserveAuthAs('Faculty', 'faculty_other'))
        ->postJson('/api/library/reserves', [
            'section_id' => $existing->section_id,
            'book_id' => $existing->book_id,
            'copies_requested' => 1,
        ])
        ->assertStatus(403);

    expect(CourseReserve::count())->toBe($before);
});
