<?php

/**
 * General vs course-reserve availability.
 *
 * Confirmed rule — a copy is generally available only when it is
 *   - not allocated to a course reserve
 *   - and physically free (not checked_out / on_hold / lost / damaged)
 *
 * Course-reserve availability counts only that reserve's own allocated copies.
 */

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\CourseReserve;
use App\Models\CourseSection;
use App\Models\CourseSectionStudent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function avAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function avUser(string $username, string $dbRole): User
{
    return User::create([
        'username' => $username,
        'password' => 'password',
        'role' => $dbRole,
        'status' => 'active',
        'total_fines' => 0,
    ]);
}

function avBook(): Book
{
    return Book::create([
        'book_title' => 'Availability Title',
        'author' => 'Author',
        'category' => 'Computer Science',
        'isbn' => 'ISBN-'.uniqid(),
        'physical_location' => 'Shelf A9',
        'total_copies' => 0,
    ]);
}

function avCopy(Book $book, string $status = 'available', ?int $reserveId = null): BookCopy
{
    return BookCopy::create([
        'book_id' => $book->book_id,
        'condition' => 'good',
        'availability_status' => $status,
        'reserve_id' => $reserveId,
    ]);
}

beforeEach(function () {
    $this->teacher = avUser('av_teacher', 'faculty');
    $this->student = avUser('av_student', 'student');
    $this->admin = avUser('av_admin', 'administrator');

    $this->book = avBook();
});

/*
|--------------------------------------------------------------------------
| General availability
|--------------------------------------------------------------------------
*/

test('AV1: a course-reserved copy is excluded from general availability', function () {
    $section = CourseSection::create(['teacher_id' => $this->teacher->user_id, 'name' => 'Sec A']);
    $reserve = CourseReserve::create([
        'section_id' => $section->section_id,
        'book_id' => $this->book->book_id,
        'user_id' => $this->teacher->user_id,
        'copies_requested' => 1,
        'status' => 'approved',
    ]);

    avCopy($this->book);                                  // general, free
    avCopy($this->book, 'available', $reserve->reserve_id); // reserved, free

    $row = $this->withHeaders(avAs('Student', 'av_student'))
        ->getJson('/api/library/books')
        ->assertStatus(200)
        ->json('data.0');

    // Two copies exist, but only one may be borrowed from general stock.
    expect($row['available_copies_count'])->toBe(1);
    expect($row['reserved_copies_count'])->toBe(1);
});

test('AV2: every non-free status is excluded from general availability', function () {
    avCopy($this->book, 'available');
    avCopy($this->book, 'checked_out');
    avCopy($this->book, 'on_hold');
    avCopy($this->book, 'lost');
    avCopy($this->book, 'damaged');

    $row = $this->withHeaders(avAs('Student', 'av_student'))
        ->getJson('/api/library/books')
        ->json('data.0');

    expect($row['available_copies_count'])->toBe(1);
});

test('AV3: the book detail view uses the same rule', function () {
    $section = CourseSection::create(['teacher_id' => $this->teacher->user_id, 'name' => 'Sec B']);
    $reserve = CourseReserve::create([
        'section_id' => $section->section_id,
        'book_id' => $this->book->book_id,
        'user_id' => $this->teacher->user_id,
        'copies_requested' => 1,
        'status' => 'approved',
    ]);

    avCopy($this->book);
    avCopy($this->book, 'available', $reserve->reserve_id);

    $this->withHeaders(avAs('Student', 'av_student'))
        ->getJson("/api/library/books/{$this->book->book_id}")
        ->assertStatus(200)
        ->assertJsonPath('available_copies_count', 1)
        ->assertJsonPath('reserved_copies_count', 1);
});

test('AV4: a title whose only free copy is reserved reads as unavailable', function () {
    $section = CourseSection::create(['teacher_id' => $this->teacher->user_id, 'name' => 'Sec C']);
    $reserve = CourseReserve::create([
        'section_id' => $section->section_id,
        'book_id' => $this->book->book_id,
        'user_id' => $this->teacher->user_id,
        'copies_requested' => 1,
        'status' => 'approved',
    ]);

    avCopy($this->book, 'available', $reserve->reserve_id);

    $row = $this->withHeaders(avAs('Student', 'av_student'))
        ->getJson('/api/library/books')
        ->json('data.0');

    expect($row['available_copies_count'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Course-reserve availability
|--------------------------------------------------------------------------
*/

test('AV5: reserve availability counts only that reserves own copies', function () {
    $section = CourseSection::create(['teacher_id' => $this->teacher->user_id, 'name' => 'Sec D']);
    CourseSectionStudent::create([
        'section_id' => $section->section_id,
        'student_id' => $this->student->user_id,
    ]);

    $reserve = CourseReserve::create([
        'section_id' => $section->section_id,
        'book_id' => $this->book->book_id,
        'user_id' => $this->teacher->user_id,
        'copies_requested' => 2,
        'status' => 'approved',
    ]);

    // Two allocated (one free, one out) plus three general copies that must
    // not be counted towards this section at all.
    avCopy($this->book, 'available', $reserve->reserve_id);
    avCopy($this->book, 'checked_out', $reserve->reserve_id);
    avCopy($this->book);
    avCopy($this->book);
    avCopy($this->book);

    $row = $this->withHeaders(avAs('Student', 'av_student'))
        ->getJson('/api/library/sections/me')
        ->assertStatus(200)
        ->json('0.reserves.0');

    expect($row['allocated_copies'])->toBe(2);
    expect($row['available_for_section'])->toBe(1);
});

test('AV6: a copy held for someone else is not available to the section', function () {
    $section = CourseSection::create(['teacher_id' => $this->teacher->user_id, 'name' => 'Sec E']);
    CourseSectionStudent::create([
        'section_id' => $section->section_id,
        'student_id' => $this->student->user_id,
    ]);

    $reserve = CourseReserve::create([
        'section_id' => $section->section_id,
        'book_id' => $this->book->book_id,
        'user_id' => $this->teacher->user_id,
        'copies_requested' => 1,
        'status' => 'approved',
    ]);

    avCopy($this->book, 'on_hold', $reserve->reserve_id);

    $row = $this->withHeaders(avAs('Student', 'av_student'))
        ->getJson('/api/library/sections/me')
        ->json('0.reserves.0');

    expect($row['allocated_copies'])->toBe(1);
    expect($row['available_for_section'])->toBe(0);
    expect($row['student_request_status'])->toBe('unavailable');
});

/*
|--------------------------------------------------------------------------
| Checkout eligibility follows the same boundary
|--------------------------------------------------------------------------
*/

test('AV7: a reserved copy still cannot be checked out to an unenrolled borrower', function () {
    $section = CourseSection::create(['teacher_id' => $this->teacher->user_id, 'name' => 'Sec F']);
    $reserve = CourseReserve::create([
        'section_id' => $section->section_id,
        'book_id' => $this->book->book_id,
        'user_id' => $this->teacher->user_id,
        'copies_requested' => 1,
        'status' => 'approved',
    ]);

    $copy = avCopy($this->book, 'available', $reserve->reserve_id);

    $this->withHeaders(avAs('Admin', 'av_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'This copy is reserved for a course section the borrower is not enrolled in.');
});
