<?php

/**
 * Automated pre-UAT end-to-end flows.
 *
 * Existing suites cover the individual rules. This file walks the multi-step
 * workflows a human tester will follow, so the manual UAT session starts from a
 * known-good backend:
 *
 *   A. hold -> admin accepts -> checkout to holder
 *   D. renew is blocked while another user waits via a hold
 *   G. faculty requests -> admin approves -> admin allocates -> eligible student borrows
 *
 * Runs against sqlite :memory:; the live MySQL database is never touched.
 */

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\CourseReserve;
use App\Models\CourseSection;
use App\Models\CourseSectionStudent;
use App\Models\Hold;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function uatAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function uatUser(string $username, string $dbRole): User
{
    return User::create([
        'username' => $username, 'password' => 'password',
        'role' => $dbRole, 'status' => 'active', 'total_fines' => 0,
    ]);
}

function uatBook(): Book
{
    return Book::create([
        'book_title' => 'UAT Title', 'author' => 'Author', 'category' => 'Computer Science',
        'isbn' => 'ISBN-'.uniqid(), 'physical_location' => 'Shelf U1', 'total_copies' => 0,
    ]);
}

function uatCopy(Book $book, string $status = 'available'): BookCopy
{
    return BookCopy::create([
        'book_id' => $book->book_id, 'condition' => 'good', 'availability_status' => $status,
    ]);
}

beforeEach(function () {
    $this->student = uatUser('uat_student', 'student');
    $this->student2 = uatUser('uat_student2', 'student');
    $this->faculty = uatUser('uat_faculty', 'faculty');
    $this->admin = uatUser('uat_admin', 'administrator');
});

/*
|--------------------------------------------------------------------------
| Flow A — hold -> admin accepts -> checkout to holder
|--------------------------------------------------------------------------
*/

test('A1: full hold lifecycle — place, admin sees it, accept, checkout to holder', function () {
    $book = uatBook();
    $copy = uatCopy($book);

    // 1. Student places a hold. A free copy exists, so it is reserved for them
    //    and parked awaiting librarian approval.
    $this->withHeaders(uatAs('Student', 'uat_student'))
        ->postJson("/api/library/books/{$book->book_id}/holds")
        ->assertStatus(201);

    $hold = Hold::where('user_id', $this->student->user_id)->firstOrFail();
    expect($hold->status)->toBe('pending_approval');
    expect((int) $hold->copy_id)->toBe((int) $copy->copy_id);
    expect($copy->fresh()->availability_status)->toBe('on_hold');

    // 2. The hold appears in the librarian's queue.
    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->getJson('/api/library/holds')
        ->assertStatus(200)
        ->assertJsonFragment(['hold_id' => $hold->hold_id]);

    // 3. Librarian accepts it -> Ready for Pickup.
    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->putJson("/api/library/holds/{$hold->hold_id}/accept")
        ->assertStatus(200);

    expect($hold->fresh()->status)->toBe('fulfilled');

    // 4. Librarian checks the copy out to the holder.
    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(201);

    // 5. Resulting state: copy on loan, transaction active and owned by the holder,
    //    and the consumed hold is gone.
    expect($copy->fresh()->availability_status)->toBe('checked_out');

    $loan = Transaction::where('copy_id', $copy->copy_id)->where('status', 'active')->first();
    expect($loan)->not->toBeNull();
    expect((int) $loan->user_id)->toBe((int) $this->student->user_id);
    expect(Hold::find($hold->hold_id))->toBeNull();
});

test('A2: a held copy cannot be checked out to a different borrower', function () {
    $book = uatBook();
    $copy = uatCopy($book);

    $this->withHeaders(uatAs('Student', 'uat_student'))
        ->postJson("/api/library/books/{$book->book_id}/holds")
        ->assertStatus(201);

    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student2->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(422);

    expect($copy->fresh()->availability_status)->toBe('on_hold');
});

test('A3: a second user joins the waitlist when no copy is free', function () {
    $book = uatBook();
    uatCopy($book);

    $this->withHeaders(uatAs('Student', 'uat_student'))
        ->postJson("/api/library/books/{$book->book_id}/holds")->assertStatus(201);

    $this->withHeaders(uatAs('Student', 'uat_student2'))
        ->postJson("/api/library/books/{$book->book_id}/holds")->assertStatus(201);

    $second = Hold::where('user_id', $this->student2->user_id)->firstOrFail();
    expect($second->status)->toBe('pending');
    expect((int) $second->queue_position)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Flow D — renew blocked while someone is waiting
|--------------------------------------------------------------------------
*/

test('D1: renew is blocked while another user waits via a hold', function () {
    $book = uatBook();
    $copy = uatCopy($book, 'checked_out');

    $loan = Transaction::create([
        'user_id' => $this->student->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDays(2),
        'due_date' => now()->addDays(5),
        'status' => 'active',
    ]);

    // Renew works while nobody is waiting.
    $this->withHeaders(uatAs('Student', 'uat_student'))
        ->postJson('/api/library/loans/renew', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(200);

    // Another borrower joins the queue.
    $this->withHeaders(uatAs('Student', 'uat_student2'))
        ->postJson("/api/library/books/{$book->book_id}/holds")->assertStatus(201);

    // Now renewal is refused, for the owner and for a librarian alike.
    $this->withHeaders(uatAs('Student', 'uat_student'))
        ->postJson('/api/library/loans/renew', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(422)
        ->assertJsonFragment(['error' => 'Cannot renew: This book has pending holds.']);

    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->postJson('/api/library/loans/renew', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Flow G — course reserve: request -> approve -> allocate -> borrow
|--------------------------------------------------------------------------
*/

test('G1: full course-reserve chain, with eligible and ineligible borrowers', function () {
    $book = uatBook();
    uatCopy($book);
    uatCopy($book);

    // 1. Faculty creates a section and enrols one student.
    $this->withHeaders(uatAs('Teacher', 'uat_faculty'))
        ->postJson('/api/library/sections', ['name' => 'UAT Section'])
        ->assertStatus(201);

    $section = CourseSection::where('teacher_id', $this->faculty->user_id)->firstOrFail();

    $this->withHeaders(uatAs('Teacher', 'uat_faculty'))
        ->postJson("/api/library/sections/{$section->section_id}/students", [
            'student_id' => $this->student->user_id,
        ])
        ->assertStatus(201);

    // 2. Faculty requests a course reserve for their own section.
    $this->withHeaders(uatAs('Teacher', 'uat_faculty'))
        ->postJson('/api/library/reserves', [
            'section_id' => $section->section_id,
            'book_id' => $book->book_id,
            'copies_requested' => 1,
        ])
        ->assertStatus(201);

    $reserve = CourseReserve::where('section_id', $section->section_id)->firstOrFail();
    expect($reserve->status)->toBe('pending');

    // 3. Librarian approves it.
    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->putJson("/api/library/reserves/{$reserve->reserve_id}/status", ['status' => 'approved'])
        ->assertStatus(200);

    expect($reserve->fresh()->status)->toBe('approved');

    // 4. Librarian allocates physical copies (no explicit selection needed).
    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate")
        ->assertStatus(200);

    $reservedCopy = BookCopy::where('reserve_id', $reserve->reserve_id)->firstOrFail();

    // 5. The ENROLLED student may borrow the reserved copy.
    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $reservedCopy->copy_id,
        ])
        ->assertStatus(201);

    // Reserve loans use the fixed 14-day period.
    // Carbon 3's diffInDays is signed, so measure from now() to the future due date.
    $loan = Transaction::where('copy_id', $reservedCopy->copy_id)->where('status', 'active')->firstOrFail();
    expect(abs(now()->diffInDays($loan->due_date)))->toBeGreaterThan(12);

    // 6. An UNRELATED student is refused a second reserved copy, even though an
    //    admin is operating the desk.
    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate", [
            'copy_ids' => [BookCopy::where('book_id', $book->book_id)->whereNull('reserve_id')->first()->copy_id],
        ])
        ->assertStatus(200);

    $secondReserved = BookCopy::where('reserve_id', $reserve->reserve_id)
        ->where('copy_id', '!=', $reservedCopy->copy_id)
        ->firstOrFail();

    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student2->user_id,
            'copy_id' => $secondReserved->copy_id,
        ])
        ->assertStatus(422);

    expect($secondReserved->fresh()->availability_status)->toBe('available');
});

test('G2: enrolling the second student then allows the same borrow', function () {
    $book = uatBook();
    $copy = uatCopy($book);

    $section = CourseSection::create(['teacher_id' => $this->faculty->user_id, 'name' => 'S']);
    $reserve = CourseReserve::create([
        'section_id' => $section->section_id, 'book_id' => $book->book_id,
        'user_id' => $this->faculty->user_id, 'copies_requested' => 1, 'status' => 'approved',
    ]);
    $copy->update(['reserve_id' => $reserve->reserve_id]);

    // Denied before enrolment.
    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->student2->user_id, 'copy_id' => $copy->copy_id])
        ->assertStatus(422);

    CourseSectionStudent::create([
        'section_id' => $section->section_id,
        'student_id' => $this->student2->user_id,
    ]);

    // Allowed after enrolment.
    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->student2->user_id, 'copy_id' => $copy->copy_id])
        ->assertStatus(201);
});

/*
|--------------------------------------------------------------------------
| Cross-flow: check-in settles the fine and frees the copy
|--------------------------------------------------------------------------
*/

test('X1: overdue return charges the fine, frees the copy, and blocks the next borrow until settled', function () {
    $book = uatBook();
    $copy = uatCopy($book, 'checked_out');

    $loan = Transaction::create([
        'user_id' => $this->student->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDays(12),
        'due_date' => now()->subDays(3),
        'status' => 'active',
    ]);

    // Overdue is visible before return, without writing anything.
    $this->withHeaders(uatAs('Student', 'uat_student'))
        ->getJson('/api/library/loans/me')
        ->assertStatus(200)
        ->assertJsonPath('0.is_overdue', true);

    expect((float) $this->student->fresh()->total_fines)->toBe(0.00);

    // Check-in charges the fine and returns the copy to the shelf.
    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(200);

    expect((float) $this->student->fresh()->total_fines)->toBeGreaterThan(0);
    expect($copy->fresh()->availability_status)->toBe('available');

    // The balance now blocks further borrowing.
    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->student->user_id, 'copy_id' => $copy->copy_id])
        ->assertStatus(422);

    // Settling in full unblocks it.
    $balance = (float) $this->student->fresh()->total_fines;

    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id, 'amount' => $balance, 'type' => 'paid',
        ])
        ->assertStatus(200);

    $this->withHeaders(uatAs('Admin', 'uat_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->student->user_id, 'copy_id' => $copy->copy_id])
        ->assertStatus(201);
});
