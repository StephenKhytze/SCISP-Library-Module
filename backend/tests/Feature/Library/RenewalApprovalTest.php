<?php

/**
 * Renewal now needs a librarian's decision.
 *
 * Confirmed rule:
 *   Student / Faculty  -> may REQUEST, never extend their own due date
 *   Admin / Super Admin -> approve or deny
 *   approved  -> due date extended by the BORROWER's role
 *   denied    -> due date unchanged
 *   blocked   -> duplicate pending, returned loan, someone else's loan,
 *                inactive loan at approval time, another borrower waiting
 */

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\RenewalRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function renAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function renUser(string $username, string $dbRole, bool $isSuperAdmin = false): User
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

function renBook(): Book
{
    return Book::create([
        'book_title' => 'Renewal Title',
        'author' => 'Author',
        'category' => 'Computer Science',
        'isbn' => 'ISBN-'.uniqid(),
        'physical_location' => 'Shelf R1',
        'total_copies' => 0,
    ]);
}

function renCopy(?Book $book = null, string $status = 'checked_out'): BookCopy
{
    return BookCopy::create([
        'book_id' => ($book ?: renBook())->book_id,
        'condition' => 'good',
        'availability_status' => $status,
    ]);
}

function renLoan(User $borrower, ?BookCopy $copy = null): Transaction
{
    return Transaction::create([
        'user_id' => $borrower->user_id,
        'copy_id' => ($copy ?: renCopy())->copy_id,
        'date_borrowed' => now()->subDays(3),
        'due_date' => now()->addDays(4),
        'status' => 'active',
    ]);
}

/** Asks for a renewal and hands back the new request id. */
function renRequestAs(object $test, string $role, string $username, Transaction $loan): int
{
    return $test->withHeaders(renAs($role, $username))
        ->postJson('/api/library/renewals', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(201)
        ->json('renewal_request.renewal_request_id');
}

beforeEach(function () {
    $this->student = renUser('ren_student', 'student');
    $this->otherStudent = renUser('ren_student2', 'student');
    $this->faculty = renUser('ren_faculty', 'faculty');
    $this->admin = renUser('ren_admin', 'administrator');
    $this->superAdmin = renUser('ren_superadmin', 'administrator', true);
});

/*
|--------------------------------------------------------------------------
| Requesting
|--------------------------------------------------------------------------
*/

test('R1: a student can request a renewal of their own loan', function () {
    $loan = renLoan($this->student);

    $this->withHeaders(renAs('Student', 'ren_student'))
        ->postJson('/api/library/renewals', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(201)
        ->assertJsonPath('renewal_request.status', 'pending');
});

test('R2: a faculty member can request a renewal of their own loan', function () {
    $loan = renLoan($this->faculty);

    $this->withHeaders(renAs('Faculty', 'ren_faculty'))
        ->postJson('/api/library/renewals', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(201);
});

test('R3: requesting does NOT move the due date', function () {
    $loan = renLoan($this->student);
    $original = $loan->due_date;

    renRequestAs($this, 'Student', 'ren_student', $loan);

    expect($loan->fresh()->due_date->eq($original))->toBeTrue();
});

test('R4: a second pending request for the same loan is refused', function () {
    $loan = renLoan($this->student);
    renRequestAs($this, 'Student', 'ren_student', $loan);

    $this->withHeaders(renAs('Student', 'ren_student'))
        ->postJson('/api/library/renewals', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(422)
        ->assertJsonPath('error', 'You already have a renewal request awaiting a decision for this loan.');

    expect(RenewalRequest::where('transaction_id', $loan->transaction_id)->count())->toBe(1);
});

test('R5: a returned loan cannot be renewed', function () {
    $loan = renLoan($this->student);
    $loan->update(['status' => 'returned']);

    $this->withHeaders(renAs('Student', 'ren_student'))
        ->postJson('/api/library/renewals', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(422)
        ->assertJsonPath('error', 'This loan has already been returned and cannot be renewed.');
});

test('R6: a borrower cannot request a renewal on someone elses loan', function () {
    $loan = renLoan($this->otherStudent);

    $this->withHeaders(renAs('Student', 'ren_student'))
        ->postJson('/api/library/renewals', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(422)
        ->assertJsonPath('error', 'You can only request a renewal for your own loan.');
});

test('R7: a super admin cannot request a renewal', function () {
    // Route gate stops them before the service even runs.
    $loan = renLoan($this->student);

    $this->withHeaders(renAs('Super Admin', 'ren_superadmin'))
        ->postJson('/api/library/renewals', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| Approving
|--------------------------------------------------------------------------
*/

test('R8: an admin approving extends the due date by the borrowers role', function () {
    $loan = renLoan($this->student);
    $id = renRequestAs($this, 'Student', 'ren_student', $loan);

    $this->withHeaders(renAs('Admin', 'ren_admin'))
        ->putJson("/api/library/renewals/{$id}/approve")
        ->assertStatus(200)
        ->assertJsonPath('renewal_request.status', 'approved');

    // Student rule is 7 days from the approval, not from the old due date.
    expect($loan->fresh()->due_date->startOfDay()->eq(now()->addDays(7)->startOfDay()))->toBeTrue();
});

test('R9: a faculty loan is extended by the faculty rule, not the approvers', function () {
    $loan = renLoan($this->faculty);
    $id = renRequestAs($this, 'Faculty', 'ren_faculty', $loan);

    $this->withHeaders(renAs('Admin', 'ren_admin'))
        ->putJson("/api/library/renewals/{$id}/approve")
        ->assertStatus(200);

    expect($loan->fresh()->due_date->startOfDay()->eq(now()->addDays(14)->startOfDay()))->toBeTrue();
});

test('R10: a super admin may approve', function () {
    $loan = renLoan($this->student);
    $original = $loan->due_date;
    $id = renRequestAs($this, 'Student', 'ren_student', $loan);

    $this->withHeaders(renAs('Super Admin', 'ren_superadmin'))
        ->putJson("/api/library/renewals/{$id}/approve")
        ->assertStatus(200);

    expect($loan->fresh()->due_date->gt($original))->toBeTrue();
});

test('R11: a student cannot approve their own request', function () {
    $loan = renLoan($this->student);
    $id = renRequestAs($this, 'Student', 'ren_student', $loan);

    $this->withHeaders(renAs('Student', 'ren_student'))
        ->putJson("/api/library/renewals/{$id}/approve")
        ->assertStatus(403);
});

test('R12: a faculty member cannot approve a renewal', function () {
    $loan = renLoan($this->student);
    $id = renRequestAs($this, 'Student', 'ren_student', $loan);

    $this->withHeaders(renAs('Teacher', 'ren_faculty'))
        ->putJson("/api/library/renewals/{$id}/approve")
        ->assertStatus(403);
});

test('R13: approval is refused once the loan is no longer active', function () {
    $loan = renLoan($this->student);
    $id = renRequestAs($this, 'Student', 'ren_student', $loan);

    // Returned between the asking and the deciding.
    $loan->update(['status' => 'returned']);

    $this->withHeaders(renAs('Admin', 'ren_admin'))
        ->putJson("/api/library/renewals/{$id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('error', 'This loan is no longer active, so it cannot be renewed.');
});

test('R14: a request cannot be decided twice', function () {
    $loan = renLoan($this->student);
    $id = renRequestAs($this, 'Student', 'ren_student', $loan);

    $this->withHeaders(renAs('Admin', 'ren_admin'))
        ->putJson("/api/library/renewals/{$id}/approve")->assertStatus(200);

    $this->withHeaders(renAs('Admin', 'ren_admin'))
        ->putJson("/api/library/renewals/{$id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('error', 'This renewal request has already been decided.');
});

/*
|--------------------------------------------------------------------------
| Denying
|--------------------------------------------------------------------------
*/

test('R15: denying leaves the due date untouched', function () {
    $loan = renLoan($this->student);
    $original = $loan->due_date;
    $id = renRequestAs($this, 'Student', 'ren_student', $loan);

    $this->withHeaders(renAs('Admin', 'ren_admin'))
        ->putJson("/api/library/renewals/{$id}/deny", ['note' => 'Needed by another class'])
        ->assertStatus(200)
        ->assertJsonPath('renewal_request.status', 'denied');

    expect($loan->fresh()->due_date->eq($original))->toBeTrue();
});

test('R16: after a denial the borrower may ask again', function () {
    $loan = renLoan($this->student);
    $id = renRequestAs($this, 'Student', 'ren_student', $loan);

    $this->withHeaders(renAs('Admin', 'ren_admin'))
        ->putJson("/api/library/renewals/{$id}/deny")->assertStatus(200);

    // The first request is no longer pending, so this is not a duplicate.
    $this->withHeaders(renAs('Student', 'ren_student'))
        ->postJson('/api/library/renewals', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(201);
});

/*
|--------------------------------------------------------------------------
| Waiting borrowers outrank an extension
|--------------------------------------------------------------------------
*/

test('R17: approval is blocked while another borrower waits for the title', function () {
    $book = renBook();
    $copy = renCopy($book);
    $loan = renLoan($this->student, $copy);
    $id = renRequestAs($this, 'Student', 'ren_student', $loan);

    // Someone else joins the general waitlist for this title.
    \App\Models\Hold::create([
        'user_id' => $this->otherStudent->user_id,
        'book_id' => $book->book_id,
        'request_date' => now(),
        'status' => 'pending',
        'queue_position' => 1,
    ]);

    $this->withHeaders(renAs('Admin', 'ren_admin'))
        ->putJson("/api/library/renewals/{$id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('error', 'This loan cannot be renewed because another borrower is waiting for this title.');
});

test('R18: the borrowers own hold does not block their renewal', function () {
    $book = renBook();
    $copy = renCopy($book);
    $loan = renLoan($this->student, $copy);
    $id = renRequestAs($this, 'Student', 'ren_student', $loan);

    \App\Models\Hold::create([
        'user_id' => $this->student->user_id,
        'book_id' => $book->book_id,
        'request_date' => now(),
        'status' => 'pending',
        'queue_position' => 1,
    ]);

    $this->withHeaders(renAs('Admin', 'ren_admin'))
        ->putJson("/api/library/renewals/{$id}/approve")
        ->assertStatus(200);
});

/*
|--------------------------------------------------------------------------
| Listing
|--------------------------------------------------------------------------
*/

test('R19: a librarian sees pending requests; a student does not', function () {
    $loan = renLoan($this->student);
    renRequestAs($this, 'Student', 'ren_student', $loan);

    $this->withHeaders(renAs('Admin', 'ren_admin'))
        ->getJson('/api/library/renewals')
        ->assertStatus(200)
        ->assertJsonCount(1);

    $this->withHeaders(renAs('Student', 'ren_student'))
        ->getJson('/api/library/renewals')
        ->assertStatus(403);
});

test('R20: a borrower sees only their own requests', function () {
    renRequestAs($this, 'Student', 'ren_student', renLoan($this->student));
    renRequestAs($this, 'Student', 'ren_student2', renLoan($this->otherStudent));

    $this->withHeaders(renAs('Student', 'ren_student'))
        ->getJson('/api/library/renewals/me')
        ->assertStatus(200)
        ->assertJsonCount(1);
});

test('R21: the loan carries its renewal state for the borrowing screen', function () {
    $loan = renLoan($this->student);

    $this->withHeaders(renAs('Student', 'ren_student'))
        ->getJson('/api/library/loans/me')
        ->assertStatus(200)
        ->assertJsonPath('0.renewal_status', null);

    renRequestAs($this, 'Student', 'ren_student', $loan);

    $this->withHeaders(renAs('Student', 'ren_student'))
        ->getJson('/api/library/loans/me')
        ->assertStatus(200)
        ->assertJsonPath('0.renewal_status', 'pending');
});
