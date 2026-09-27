<?php

/**
 * Simplified Library completion — Batch 3 + 5.
 *
 *   - transaction history, scoped to the caller
 *   - admin sees all transactions
 *   - hold queue positions renumber after promotion/cancellation
 *   - a librarian can free a copy stuck behind an abandoned hold
 *   - reserve copy allocation works without an explicit selection
 *   - reserve copies expose their active loan
 */

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\CourseReserve;
use App\Models\CourseSection;
use App\Models\Hold;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function hhAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function hhUser(string $username, string $dbRole): User
{
    return User::create([
        'username' => $username, 'password' => 'password',
        'role' => $dbRole, 'status' => 'active', 'total_fines' => 0,
    ]);
}

function hhBook(): Book
{
    return Book::create([
        'book_title' => 'T', 'author' => 'A', 'category' => 'CS',
        'isbn' => 'ISBN-'.uniqid(), 'physical_location' => 'S1', 'total_copies' => 0,
    ]);
}

function hhCopy(Book $book, string $status = 'available'): BookCopy
{
    return BookCopy::create([
        'book_id' => $book->book_id, 'condition' => 'good', 'availability_status' => $status,
    ]);
}

function hhTransaction(User $user, BookCopy $copy, string $status): Transaction
{
    return Transaction::create([
        'user_id' => $user->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDays(9),
        'due_date' => now()->subDays(2),
        'actual_return_date' => $status === 'returned' ? now()->subDay() : null,
        'status' => $status,
    ]);
}

beforeEach(function () {
    $this->student = hhUser('hh_student', 'student');
    $this->other = hhUser('hh_other', 'student');
    $this->faculty = hhUser('hh_faculty', 'faculty');
    $this->admin = hhUser('hh_admin', 'administrator');
});

/*
|--------------------------------------------------------------------------
| Transaction history
|--------------------------------------------------------------------------
*/

test('H1: own history includes active and returned loans', function () {
    $book = hhBook();
    hhTransaction($this->student, hhCopy($book, 'checked_out'), 'active');
    hhTransaction($this->student, hhCopy($book), 'returned');

    $this->withHeaders(hhAs('Student', 'hh_student'))
        ->getJson('/api/library/loans/me/history')
        ->assertStatus(200)
        ->assertJsonCount(2);
});

test('H2: own history never exposes another users records', function () {
    $book = hhBook();
    hhTransaction($this->student, hhCopy($book), 'returned');
    hhTransaction($this->other, hhCopy($book), 'returned');

    $response = $this->withHeaders(hhAs('Student', 'hh_student'))
        ->getJson('/api/library/loans/me/history')
        ->assertStatus(200)
        ->assertJsonCount(1);

    expect((int) $response->json('0.user_id'))->toBe((int) $this->student->user_id);
});

test('H3: active loans endpoint still returns only active loans', function () {
    $book = hhBook();
    hhTransaction($this->student, hhCopy($book, 'checked_out'), 'active');
    hhTransaction($this->student, hhCopy($book), 'returned');

    $this->withHeaders(hhAs('Student', 'hh_student'))
        ->getJson('/api/library/loans/me')
        ->assertStatus(200)
        ->assertJsonCount(1);
});

test('H4: admin sees all transactions with status=all', function () {
    $book = hhBook();
    hhTransaction($this->student, hhCopy($book, 'checked_out'), 'active');
    hhTransaction($this->other, hhCopy($book), 'returned');

    $this->withHeaders(hhAs('Admin', 'hh_admin'))
        ->getJson('/api/library/circulation?status=all')
        ->assertStatus(200)
        ->assertJsonCount(2);
});

test('H5: admin circulation defaults to active only', function () {
    $book = hhBook();
    hhTransaction($this->student, hhCopy($book, 'checked_out'), 'active');
    hhTransaction($this->other, hhCopy($book), 'returned');

    $this->withHeaders(hhAs('Admin', 'hh_admin'))
        ->getJson('/api/library/circulation')
        ->assertStatus(200)
        ->assertJsonCount(1);
});

test('H6: a student cannot read the admin circulation list', function () {
    $this->withHeaders(hhAs('Student', 'hh_student'))
        ->getJson('/api/library/circulation')
        ->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| Hold queue renumbering
|--------------------------------------------------------------------------
*/

function hhQueuedHold(User $user, Book $book, int $position): Hold
{
    return Hold::create([
        'user_id' => $user->user_id,
        'book_id' => $book->book_id,
        'request_date' => now(),
        'status' => 'pending',
        'queue_position' => $position,
    ]);
}

test('H7: cancelling a waitlisted hold renumbers the queue with no gaps', function () {
    $book = hhBook();
    $first = hhQueuedHold($this->student, $book, 1);
    hhQueuedHold($this->other, $book, 2);
    hhQueuedHold($this->faculty, $book, 3);

    $this->withHeaders(hhAs('Student', 'hh_student'))
        ->deleteJson("/api/library/holds/{$first->hold_id}")
        ->assertStatus(200);

    $positions = Hold::where('book_id', $book->book_id)
        ->where('status', 'pending')
        ->orderBy('queue_position')
        ->pluck('queue_position')
        ->all();

    expect($positions)->toBe([1, 2]);
});

test('H8: promoting the head of the queue renumbers the rest', function () {
    $book = hhBook();
    $copy = hhCopy($book, 'checked_out');

    hhQueuedHold($this->student, $book, 1);
    hhQueuedHold($this->other, $book, 2);
    hhQueuedHold($this->faculty, $book, 3);

    $loan = Transaction::create([
        'user_id' => $this->admin->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDay(),
        'due_date' => now()->addDays(5),
        'status' => 'active',
    ]);

    // Check-in promotes the first waiting hold and frees the queue behind it.
    $this->withHeaders(hhAs('Admin', 'hh_admin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(200);

    $positions = Hold::where('book_id', $book->book_id)
        ->where('status', 'pending')
        ->orderBy('queue_position')
        ->pluck('queue_position')
        ->all();

    expect($positions)->toBe([1, 2]);
});

test('H9: an admin can cancel a stuck hold and free the copy', function () {
    $book = hhBook();
    $copy = hhCopy($book, 'on_hold');

    $stuck = Hold::create([
        'user_id' => $this->student->user_id,
        'book_id' => $book->book_id,
        'copy_id' => $copy->copy_id,
        'request_date' => now()->subDays(5),
        'status' => 'pending_approval',
        'queue_position' => 0,
    ]);

    $this->withHeaders(hhAs('Admin', 'hh_admin'))
        ->deleteJson("/api/library/holds/{$stuck->hold_id}")
        ->assertStatus(200);

    expect($copy->fresh()->availability_status)->toBe('available');
    expect($stuck->fresh()->status)->toBe('cancelled');
});

/*
|--------------------------------------------------------------------------
| Course reserve allocation
|--------------------------------------------------------------------------
*/

function hhApprovedReserve(User $teacher, Book $book, int $requested = 2): CourseReserve
{
    $section = CourseSection::create(['teacher_id' => $teacher->user_id, 'name' => 'Sec']);

    return CourseReserve::create([
        'section_id' => $section->section_id,
        'book_id' => $book->book_id,
        'user_id' => $teacher->user_id,
        'copies_requested' => $requested,
        'status' => 'approved',
    ]);
}

test('H10: allocation with no body auto-selects available copies', function () {
    $book = hhBook();
    hhCopy($book);
    hhCopy($book);
    hhCopy($book);
    $reserve = hhApprovedReserve($this->faculty, $book, 2);

    $this->withHeaders(hhAs('Admin', 'hh_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate")
        ->assertStatus(200);

    expect(BookCopy::where('reserve_id', $reserve->reserve_id)->count())->toBe(2);
});

test('H11: allocation accepts an explicit copy selection', function () {
    $book = hhBook();
    $copy = hhCopy($book);
    $reserve = hhApprovedReserve($this->faculty, $book, 2);

    $this->withHeaders(hhAs('Admin', 'hh_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate", [
            'copy_ids' => [$copy->copy_id],
        ])
        ->assertStatus(200);

    expect((int) $copy->fresh()->reserve_id)->toBe((int) $reserve->reserve_id);
});

test('H12: a copy from a different title cannot be allocated', function () {
    $book = hhBook();
    hhCopy($book);
    $otherCopy = hhCopy(hhBook());
    $reserve = hhApprovedReserve($this->faculty, $book, 1);

    $this->withHeaders(hhAs('Admin', 'hh_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate", [
            'copy_ids' => [$otherCopy->copy_id],
        ])
        ->assertStatus(422);

    expect($otherCopy->fresh()->reserve_id)->toBeNull();
});

test('H13: a reserve that is not approved cannot receive copies', function () {
    $book = hhBook();
    hhCopy($book);
    $reserve = hhApprovedReserve($this->faculty, $book, 1);
    $reserve->update(['status' => 'pending']);

    $this->withHeaders(hhAs('Admin', 'hh_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate")
        ->assertStatus(422);
});

test('H14: a reserve copy exposes its active loan to the teacher view', function () {
    $book = hhBook();
    $copy = hhCopy($book, 'checked_out');
    $reserve = hhApprovedReserve($this->faculty, $book, 1);
    $copy->update(['reserve_id' => $reserve->reserve_id]);

    Transaction::create([
        'user_id' => $this->student->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDay(),
        'due_date' => now()->addDays(6),
        'status' => 'active',
    ]);

    $response = $this->withHeaders(hhAs('Teacher', 'hh_faculty'))
        ->getJson('/api/library/sections')
        ->assertStatus(200);

    $active = $response->json('0.reserves.0.copies.0.active_transaction');

    expect($active)->not->toBeNull();
    expect((int) $active['user_id'])->toBe((int) $this->student->user_id);
});
