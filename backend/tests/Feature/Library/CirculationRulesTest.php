<?php

/**
 * Simplified Library completion — Batch 1 core circulation rules.
 *
 * Covers the confirmed product rules:
 *   - borrowing limits (Student 3, Faculty 10, librarian unlimited)
 *   - outstanding fines block checkout
 *   - course-reserve eligibility judged on the BORROWER, not the operator
 *   - fines: PHP 10 per overdue calendar day, partial day rounds UP
 *   - renew: own loan only, librarians may renew any
 *   - a copy with an active loan cannot be hand-set back to available
 */

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\CourseReserve;
use App\Models\CourseSection;
use App\Models\CourseSectionStudent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinesCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function circAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function circUser(string $username, string $dbRole, float $fines = 0): User
{
    return User::create([
        'username' => $username,
        'password' => 'password',
        'role' => $dbRole,
        'status' => 'active',
        'total_fines' => $fines,
    ]);
}

function circBook(): Book
{
    return Book::create([
        'book_title' => 'Test Title',
        'author' => 'Author',
        'category' => 'Computer Science',
        'isbn' => 'ISBN-'.uniqid(),
        'physical_location' => 'Shelf A1',
        'total_copies' => 0,
    ]);
}

function circCopy(?Book $book = null, string $status = 'available'): BookCopy
{
    $book = $book ?: circBook();

    return BookCopy::create([
        'book_id' => $book->book_id,
        'condition' => 'good',
        'availability_status' => $status,
    ]);
}

/** Gives $user $count active loans on throwaway copies. */
function circGiveLoans(User $user, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $copy = circCopy();
        $copy->update(['availability_status' => 'checked_out']);

        Transaction::create([
            'user_id' => $user->user_id,
            'copy_id' => $copy->copy_id,
            'date_borrowed' => now()->subDays(1),
            'due_date' => now()->addDays(6),
            'status' => 'active',
        ]);
    }
}

beforeEach(function () {
    // Freeze the clock. Without this, two separate now() calls differ by
    // microseconds, so an "exactly N days overdue" fixture measures as
    // N.000001 days and correctly rounds up to N+1 — making the rule's own
    // worked examples untestable.
    Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00'));

    $this->student = circUser('student_a', 'student');
    $this->faculty = circUser('faculty_a', 'faculty');
    $this->admin = circUser('admin_a', 'administrator');
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Fine calculation — PHP 10/day, partial day rounds UP
|--------------------------------------------------------------------------
*/

test('C1: returning on time incurs no fine', function () {
    $calc = new FinesCalculator();
    expect($calc->calculateFine(now()->addDay(), now()))->toBe(0.00);
});

test('C2: exactly two days overdue is PHP 20', function () {
    $calc = new FinesCalculator();
    $due = now()->subDays(2);
    expect($calc->overdueDays($due, now()))->toBe(2);
    expect($calc->calculateFine($due, now()))->toBe(20.00);
});

test('C3: two days and one hour overdue rounds up to three days = PHP 30', function () {
    $calc = new FinesCalculator();
    $due = now()->subDays(2)->subHour();
    expect($calc->overdueDays($due, now()))->toBe(3);
    expect($calc->calculateFine($due, now()))->toBe(30.00);
});

test('C4: one minute overdue is charged a full day', function () {
    $calc = new FinesCalculator();
    expect($calc->calculateFine(now()->subMinute(), now()))->toBe(10.00);
});

/*
|--------------------------------------------------------------------------
| Borrowing limits
|--------------------------------------------------------------------------
*/

test('C5: a student is blocked at 3 active loans', function () {
    circGiveLoans($this->student, 3);
    $copy = circCopy();

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(422)
        ->assertJsonFragment(['error' => 'Borrowing limit reached: 3 of 3 active loans.']);

    expect($copy->fresh()->availability_status)->toBe('available');
});

test('C6: a student with 2 active loans may still borrow', function () {
    circGiveLoans($this->student, 2);
    $copy = circCopy();

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(201);
});

test('C7: faculty are limited at 10, not 3', function () {
    circGiveLoans($this->faculty, 3);
    $copy = circCopy();

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->faculty->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(201);
});

test('C8: faculty are blocked at 10 active loans', function () {
    circGiveLoans($this->faculty, 10);
    $copy = circCopy();

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->faculty->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(422);
});

test('C9: librarians have no borrowing limit', function () {
    circGiveLoans($this->admin, 12);
    $copy = circCopy();

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->admin->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(201);
});

/*
|--------------------------------------------------------------------------
| Outstanding fines block checkout
|--------------------------------------------------------------------------
*/

test('C10: a borrower with an outstanding balance cannot check out', function () {
    $debtor = circUser('debtor', 'student', 50.00);
    $copy = circCopy();

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/checkout', [
            'user_id' => $debtor->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(422);

    expect($copy->fresh()->availability_status)->toBe('available');
    expect(Transaction::where('user_id', $debtor->user_id)->count())->toBe(0);
});

test('C11: clearing the balance re-enables checkout', function () {
    $debtor = circUser('debtor2', 'student', 50.00);
    $debtor->update(['total_fines' => 0]);
    $copy = circCopy();

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/checkout', [
            'user_id' => $debtor->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(201);
});

/*
|--------------------------------------------------------------------------
| Course-reserve eligibility follows the BORROWER
|--------------------------------------------------------------------------
*/

function circReservedCopy(User $teacher): array
{
    $book = circBook();
    $copy = circCopy($book);
    $section = CourseSection::create(['teacher_id' => $teacher->user_id, 'name' => 'Sec 1']);

    $reserve = CourseReserve::create([
        'section_id' => $section->section_id,
        'book_id' => $book->book_id,
        'user_id' => $teacher->user_id,
        'copies_requested' => 1,
        'status' => 'approved',
    ]);

    $copy->update(['reserve_id' => $reserve->reserve_id]);

    return [$copy, $section];
}

test('C12: an enrolled student may borrow a reserved copy', function () {
    [$copy, $section] = circReservedCopy($this->faculty);

    CourseSectionStudent::create([
        'section_id' => $section->section_id,
        'student_id' => $this->student->user_id,
    ]);

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(201);
});

test('C13: an unrelated student is denied a reserved copy even when an admin operates', function () {
    [$copy] = circReservedCopy($this->faculty);

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(422);

    // The operator's admin role must not bypass the borrower's ineligibility.
    expect($copy->fresh()->availability_status)->toBe('available');
});

test('C14: a faculty borrower may take a reserved copy', function () {
    [$copy] = circReservedCopy($this->faculty);

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->faculty->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(201);
});

/*
|--------------------------------------------------------------------------
| Renew authorization
|--------------------------------------------------------------------------
*/

function circActiveLoan(User $borrower): Transaction
{
    $copy = circCopy();
    $copy->update(['availability_status' => 'checked_out']);

    return Transaction::create([
        'user_id' => $borrower->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDays(3),
        'due_date' => now()->addDays(4),
        'status' => 'active',
    ]);
}

test('C15: a student may renew their own loan', function () {
    $loan = circActiveLoan($this->student);

    $this->withHeaders(circAs('Student', 'student_a'))
        ->postJson('/api/library/loans/renew', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(200);
});

test('C16: a student cannot renew someone elses loan', function () {
    $loan = circActiveLoan($this->faculty);

    $this->withHeaders(circAs('Student', 'student_a'))
        ->postJson('/api/library/loans/renew', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(422)
        ->assertJsonFragment(['error' => 'You are not authorized to renew this book.']);
});

test('C17: an admin may renew any loan', function () {
    $loan = circActiveLoan($this->student);
    $original = $loan->due_date;

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/loans/renew', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(200);

    expect($loan->fresh()->due_date->gt($original))->toBeTrue();
});

test('C18: a super admin may renew any loan', function () {
    $superAdmin = circUser('superadmin_a', 'administrator');
    $loan = circActiveLoan($this->student);

    $this->withHeaders(circAs('Super Admin', 'superadmin_a'))
        ->postJson('/api/library/loans/renew', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(200);
});

test('C19: a closed transaction cannot be renewed', function () {
    $loan = circActiveLoan($this->student);
    $loan->update(['status' => 'returned']);

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/loans/renew', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Copy status consistency
|--------------------------------------------------------------------------
*/

test('C20: a copy with an active loan cannot be hand-set to available', function () {
    $loan = circActiveLoan($this->student);

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->putJson("/api/library/copies/{$loan->copy_id}", ['availability_status' => 'available'])
        ->assertStatus(422);

    expect(BookCopy::find($loan->copy_id)->availability_status)->toBe('checked_out');
});

test('C21: a damaged copy cannot be borrowed', function () {
    $copy = circCopy(null, 'damaged');

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(422);
});

test('C22: a lost copy cannot be borrowed', function () {
    $copy = circCopy(null, 'lost');

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(422);
});

test('C23: a free copy may still be marked damaged', function () {
    $copy = circCopy();

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->putJson("/api/library/copies/{$copy->copy_id}", ['availability_status' => 'damaged'])
        ->assertStatus(200);

    expect($copy->fresh()->availability_status)->toBe('damaged');
});

/*
|--------------------------------------------------------------------------
| Check-in applies the fine to users.total_fines
|--------------------------------------------------------------------------
*/

test('C24: checking in an overdue loan adds the fine to the balance', function () {
    $copy = circCopy();
    $copy->update(['availability_status' => 'checked_out']);

    $loan = Transaction::create([
        'user_id' => $this->student->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDays(10),
        'due_date' => now()->subDays(3),
        'status' => 'active',
    ]);

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(200);

    // 3 days overdue x PHP 10
    expect((float) $this->student->fresh()->total_fines)->toBe(30.00);
    expect($loan->fresh()->status)->toBe('returned');
    expect($copy->fresh()->availability_status)->toBe('available');
});

test('C25: checking in on time adds no fine', function () {
    $copy = circCopy();
    $copy->update(['availability_status' => 'checked_out']);

    $loan = Transaction::create([
        'user_id' => $this->student->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDays(2),
        'due_date' => now()->addDays(5),
        'status' => 'active',
    ]);

    $this->withHeaders(circAs('Admin', 'admin_a'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(200);

    expect((float) $this->student->fresh()->total_fines)->toBe(0.00);
});
