<?php

/**
 * H-3 — course section authorization and roster data exposure.
 *
 *   Faculty       create and manage their OWN sections only
 *   Admin / SA    manage every section
 *   Student       never create or manage a section; classmates only for
 *                 sections they are enrolled in
 *
 * Only student accounts can be enrolled, and people inside any section
 * payload are serialised as user_id + username — never fine balances,
 * account status or role flags.
 */

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\CourseReserve;
use App\Models\CourseSection;
use App\Models\CourseSectionStudent;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sxAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function sxUser(string $username, string $dbRole, bool $isSuperAdmin = false, float $fines = 0): User
{
    $user = User::create([
        'username' => $username, 'password' => 'password', 'role' => $dbRole,
        'status' => 'active', 'total_fines' => $fines,
    ]);
    $user->forceFill(['is_super_admin' => $isSuperAdmin])->save();

    return $user;
}

function sxSection(User $teacher, array $students = []): CourseSection
{
    $section = CourseSection::create(['teacher_id' => $teacher->user_id, 'name' => 'Section '.uniqid()]);

    foreach ($students as $student) {
        CourseSectionStudent::create(['section_id' => $section->section_id, 'student_id' => $student->user_id]);
    }

    return $section;
}

/** Keys that must never appear on any person embedded in a section payload. */
const SX_SENSITIVE = ['total_fines', 'is_super_admin', 'status', 'password', 'role', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'remember_token'];

beforeEach(function () {
    $this->admin = sxUser('sx_admin', 'administrator');
    $this->super = sxUser('sx_super', 'administrator', true);
    $this->faculty = sxUser('sx_faculty', 'faculty');
    $this->otherFaculty = sxUser('sx_faculty2', 'faculty');
    // Balances make any leak visible in the payload.
    $this->student = sxUser('sx_student', 'student', false, 55.5);
    $this->other = sxUser('sx_student2', 'student', false, 12.0);

    $this->section = sxSection($this->faculty, [$this->student]);
});

/* ------------------------------------------------------------------ STUDENT */

test('SX1: a student cannot create a section', function () {
    $this->withHeaders(sxAs('Student', 'sx_student'))
        ->postJson('/api/library/sections', ['name' => 'Mine'])
        ->assertStatus(403);

    expect(CourseSection::where('name', 'Mine')->exists())->toBeFalse();
});

test('SX2: a student cannot add anyone to a section', function () {
    $this->withHeaders(sxAs('Student', 'sx_student'))
        ->postJson("/api/library/sections/{$this->section->section_id}/students", ['student_id' => $this->other->user_id])
        ->assertStatus(403);

    expect(CourseSectionStudent::where('student_id', $this->other->user_id)->exists())->toBeFalse();
});

test('SX3: a student cannot remove anyone from a section', function () {
    $this->withHeaders(sxAs('Student', 'sx_student'))
        ->deleteJson("/api/library/sections/{$this->section->section_id}/students/{$this->student->user_id}")
        ->assertStatus(403);

    expect(CourseSectionStudent::where('student_id', $this->student->user_id)->exists())->toBeTrue();
});

test('SX4: a student cannot list managed sections', function () {
    $this->withHeaders(sxAs('Student', 'sx_student'))
        ->getJson('/api/library/sections')
        ->assertStatus(403);
});

/* ------------------------------------------------------------------ FACULTY */

test('SX5: a faculty member creates a section they own', function () {
    $this->withHeaders(sxAs('Teacher', 'sx_faculty'))
        ->postJson('/api/library/sections', ['name' => 'Algorithms 1'])
        ->assertStatus(201);

    expect((int) CourseSection::where('name', 'Algorithms 1')->value('teacher_id'))->toBe($this->faculty->user_id);
});

test('SX6: a faculty member manages the roster of their own section', function () {
    $this->withHeaders(sxAs('Teacher', 'sx_faculty'))
        ->postJson("/api/library/sections/{$this->section->section_id}/students", ['student_id' => $this->other->user_id])
        ->assertStatus(201);

    $this->withHeaders(sxAs('Teacher', 'sx_faculty'))
        ->deleteJson("/api/library/sections/{$this->section->section_id}/students/{$this->other->user_id}")
        ->assertOk();

    expect(CourseSectionStudent::where('student_id', $this->other->user_id)->exists())->toBeFalse();
});

test('SX7: a faculty member cannot add to another faculty member\'s section', function () {
    $this->withHeaders(sxAs('Teacher', 'sx_faculty2'))
        ->postJson("/api/library/sections/{$this->section->section_id}/students", ['student_id' => $this->other->user_id])
        ->assertStatus(404);

    expect(CourseSectionStudent::where('student_id', $this->other->user_id)->exists())->toBeFalse();
});

test('SX8: a faculty member cannot remove from another faculty member\'s section', function () {
    $this->withHeaders(sxAs('Teacher', 'sx_faculty2'))
        ->deleteJson("/api/library/sections/{$this->section->section_id}/students/{$this->student->user_id}")
        ->assertStatus(404);

    expect(CourseSectionStudent::where('student_id', $this->student->user_id)->exists())->toBeTrue();
});

test('SX9: a faculty member lists only their own sections', function () {
    sxSection($this->otherFaculty);

    $ids = collect($this->withHeaders(sxAs('Teacher', 'sx_faculty'))->getJson('/api/library/sections')->assertOk()->json())
        ->pluck('section_id')->all();

    expect($ids)->toBe([$this->section->section_id]);
});

/* ------------------------------------------------------- ADMIN / SUPER ADMIN */

test('SX10: an Admin manages any section', function () {
    $this->withHeaders(sxAs('Admin', 'sx_admin'))
        ->postJson("/api/library/sections/{$this->section->section_id}/students", ['student_id' => $this->other->user_id])
        ->assertStatus(201);

    $this->withHeaders(sxAs('Admin', 'sx_admin'))
        ->deleteJson("/api/library/sections/{$this->section->section_id}/students/{$this->student->user_id}")
        ->assertOk();

    expect(CourseSectionStudent::where('student_id', $this->other->user_id)->exists())->toBeTrue()
        ->and(CourseSectionStudent::where('student_id', $this->student->user_id)->exists())->toBeFalse();
});

test('SX11: a Super Admin manages any section', function () {
    $this->withHeaders(sxAs('Super Admin', 'sx_super'))
        ->postJson("/api/library/sections/{$this->section->section_id}/students", ['student_id' => $this->other->user_id])
        ->assertStatus(201);

    $this->withHeaders(sxAs('Super Admin', 'sx_super'))
        ->deleteJson("/api/library/sections/{$this->section->section_id}/students/{$this->other->user_id}")
        ->assertOk();
});

test('SX12: Admin and Super Admin see every section', function () {
    $second = sxSection($this->otherFaculty);

    foreach ([['Admin', 'sx_admin'], ['Super Admin', 'sx_super']] as [$role, $user]) {
        $ids = collect($this->withHeaders(sxAs($role, $user))->getJson('/api/library/sections')->assertOk()->json())
            ->pluck('section_id')->sort()->values()->all();

        expect($ids)->toBe([$this->section->section_id, $second->section_id]);
    }
});

test('SX13: an Admin can create a section', function () {
    $this->withHeaders(sxAs('Admin', 'sx_admin'))
        ->postJson('/api/library/sections', ['name' => 'Library orientation'])
        ->assertStatus(201);
});

/* -------------------------------------------------------------- TARGET ROLE */

test('SX14: a student account can be enrolled', function () {
    $this->withHeaders(sxAs('Teacher', 'sx_faculty'))
        ->postJson("/api/library/sections/{$this->section->section_id}/students", ['student_id' => $this->other->user_id])
        ->assertStatus(201);
});

test('SX15: faculty, Admin and Super Admin accounts cannot be enrolled', function () {
    foreach ([$this->otherFaculty, $this->admin, $this->super] as $target) {
        $this->withHeaders(sxAs('Teacher', 'sx_faculty'))
            ->postJson("/api/library/sections/{$this->section->section_id}/students", ['student_id' => $target->user_id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Only student accounts can be enrolled in a course section.');

        expect(CourseSectionStudent::where('student_id', $target->user_id)->exists())->toBeFalse();
    }
});

test('SX16: enrolling the same student twice still creates one row', function () {
    foreach ([1, 2] as $attempt) {
        $this->withHeaders(sxAs('Teacher', 'sx_faculty'))
            ->postJson("/api/library/sections/{$this->section->section_id}/students", ['student_id' => $this->other->user_id])
            ->assertStatus(201);
    }

    expect(CourseSectionStudent::where('section_id', $this->section->section_id)
        ->where('student_id', $this->other->user_id)->count())->toBe(1);
});

/* ----------------------------------------------------------- ROSTER PRIVACY */

test('SX17: the faculty roster carries only user_id and username', function () {
    $person = $this->withHeaders(sxAs('Teacher', 'sx_faculty'))
        ->getJson('/api/library/sections')->assertOk()
        ->json('0.students.0.student');

    expect(array_keys($person))->toEqualCanonicalizing(['user_id', 'username'])
        ->and($person['username'])->toBe('sx_student');

    foreach (SX_SENSITIVE as $key) {
        expect($person)->not->toHaveKey($key);
    }
});

test('SX18: the borrower shown on an allocated reserve copy carries only user_id and username', function () {
    $book = Book::create(['book_title' => 'T', 'author' => 'A', 'category' => 'CS', 'isbn' => 'I'.uniqid(), 'physical_location' => 'S', 'total_copies' => 0]);
    $reserve = CourseReserve::create(['section_id' => $this->section->section_id, 'book_id' => $book->book_id, 'user_id' => $this->faculty->user_id, 'copies_requested' => 1, 'status' => 'approved']);
    $copy = BookCopy::create(['book_id' => $book->book_id, 'condition' => 'good', 'availability_status' => 'checked_out', 'reserve_id' => $reserve->reserve_id]);
    Transaction::create(['user_id' => $this->student->user_id, 'copy_id' => $copy->copy_id, 'date_borrowed' => now(), 'due_date' => now()->addDays(3), 'status' => 'active']);

    $borrower = $this->withHeaders(sxAs('Teacher', 'sx_faculty'))
        ->getJson('/api/library/sections')->assertOk()
        ->json('0.reserves.0.copies.0.active_transaction.user');

    expect(array_keys($borrower))->toEqualCanonicalizing(['user_id', 'username']);
});

test('SX19: a student\'s own section view shows the teacher by name only, and no other borrower', function () {
    $book = Book::create(['book_title' => 'T', 'author' => 'A', 'category' => 'CS', 'isbn' => 'I'.uniqid(), 'physical_location' => 'S', 'total_copies' => 0]);
    $reserve = CourseReserve::create(['section_id' => $this->section->section_id, 'book_id' => $book->book_id, 'user_id' => $this->faculty->user_id, 'copies_requested' => 1, 'status' => 'approved']);
    $copy = BookCopy::create(['book_id' => $book->book_id, 'condition' => 'good', 'availability_status' => 'checked_out', 'reserve_id' => $reserve->reserve_id]);
    Transaction::create(['user_id' => $this->other->user_id, 'copy_id' => $copy->copy_id, 'date_borrowed' => now(), 'due_date' => now()->addDays(3), 'status' => 'active']);

    $section = $this->withHeaders(sxAs('Student', 'sx_student'))
        ->getJson('/api/library/sections/me')->assertOk()->json('0');

    expect(array_keys($section['teacher']))->toEqualCanonicalizing(['user_id', 'username'])
        ->and($section['reserves'][0]['copies'][0]['active_transaction'])->not->toHaveKey('user');
});

/* --------------------------------------------------------------- CLASSMATES */

test('SX20: an enrolled student sees classmates, by name only', function () {
    CourseSectionStudent::create(['section_id' => $this->section->section_id, 'student_id' => $this->other->user_id]);

    $classmates = $this->withHeaders(sxAs('Student', 'sx_student'))
        ->getJson("/api/library/sections/{$this->section->section_id}/classmates")
        ->assertOk()
        ->json('classmates');

    expect($classmates)->toHaveCount(2);

    foreach ($classmates as $person) {
        expect(array_keys($person))->toEqualCanonicalizing(['user_id', 'username']);
    }
});

test('SX21: an unrelated student cannot see a section\'s classmates', function () {
    $this->withHeaders(sxAs('Student', 'sx_student2'))
        ->getJson("/api/library/sections/{$this->section->section_id}/classmates")
        ->assertStatus(403);
});
