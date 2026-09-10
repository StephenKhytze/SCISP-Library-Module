<?php

/**
 * Simplified Library completion — Batch 2: overdue visibility and fine settlement.
 *
 * Confirmed rules under test:
 *   - overdue is DERIVED while a book is out; nothing is written to the DB
 *   - users.total_fines is the authoritative balance
 *   - Paid and Waived are distinct actions that both reduce the balance
 *   - partial settlement is allowed; the balance never goes below zero
 *   - borrowers see only their own balance
 */

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function finesAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function finesUser(string $username, string $dbRole, float $balance = 0): User
{
    return User::create([
        'username' => $username,
        'password' => 'password',
        'role' => $dbRole,
        'status' => 'active',
        'total_fines' => $balance,
    ]);
}

function finesLoan(User $user, int $dueInDays): Transaction
{
    $book = Book::create([
        'book_title' => 'T', 'author' => 'A', 'category' => 'CS',
        'isbn' => 'ISBN-'.uniqid(), 'physical_location' => 'S1', 'total_copies' => 0,
    ]);

    $copy = BookCopy::create([
        'book_id' => $book->book_id,
        'condition' => 'good',
        'availability_status' => 'checked_out',
    ]);

    return Transaction::create([
        'user_id' => $user->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDays(10),
        'due_date' => now()->addDays($dueInDays),
        'status' => 'active',
    ]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00'));

    $this->student = finesUser('fine_student', 'student');
    $this->admin = finesUser('fine_admin', 'administrator');
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Overdue is derived, not stored
|--------------------------------------------------------------------------
*/

test('F1: an overdue active loan is flagged with days and an estimated fine', function () {
    $loan = finesLoan($this->student, -3); // due 3 days ago

    $this->withHeaders(finesAs('Student', 'fine_student'))
        ->getJson('/api/library/loans/me')
        ->assertStatus(200)
        ->assertJsonPath('0.is_overdue', true)
        ->assertJsonPath('0.days_overdue', 3)
        ->assertJsonPath('0.estimated_fine', 30);

    // Nothing was written while the book is still out.
    expect((float) $this->student->fresh()->total_fines)->toBe(0.00);
    expect($loan->fresh()->status)->toBe('active');
});

test('F2: a loan that is not yet due is not flagged', function () {
    finesLoan($this->student, 4);

    $this->withHeaders(finesAs('Student', 'fine_student'))
        ->getJson('/api/library/loans/me')
        ->assertStatus(200)
        ->assertJsonPath('0.is_overdue', false)
        ->assertJsonPath('0.days_overdue', 0)
        ->assertJsonPath('0.estimated_fine', 0);
});

/*
|--------------------------------------------------------------------------
| Own balance
|--------------------------------------------------------------------------
*/

test('F3: a borrower can read their own balance', function () {
    $this->student->update(['total_fines' => 45.50]);

    $this->withHeaders(finesAs('Student', 'fine_student'))
        ->getJson('/api/library/fines/me')
        ->assertStatus(200)
        ->assertJsonPath('total_fines', 45.5)
        ->assertJsonPath('daily_rate', 10);
});

test('F4: the self summary reports balance, active loans and the role limit', function () {
    finesLoan($this->student, -1);
    $this->student->update(['total_fines' => 20]);

    $this->withHeaders(finesAs('Student', 'fine_student'))
        ->getJson('/api/library/me/summary')
        ->assertStatus(200)
        ->assertJsonPath('total_fines', 20)
        ->assertJsonPath('active_loans', 1)
        ->assertJsonPath('overdue_loans', 1)
        ->assertJsonPath('borrow_limit', 3)
        ->assertJsonPath('role', 'student');
});

test('F5: a librarian summary reports no borrowing limit', function () {
    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->getJson('/api/library/me/summary')
        ->assertStatus(200)
        ->assertJsonPath('borrow_limit', null);
});

/*
|--------------------------------------------------------------------------
| Admin fines list
|--------------------------------------------------------------------------
*/

test('F6: admin sees everyone who owes money', function () {
    $this->student->update(['total_fines' => 30]);
    finesUser('fine_clean', 'student', 0);

    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->getJson('/api/library/fines')
        ->assertStatus(200)
        ->assertJsonPath('total_outstanding', 30)
        ->assertJsonCount(1, 'debtors');
});

test('F7: a student cannot read the admin fines list', function () {
    $this->withHeaders(finesAs('Student', 'fine_student'))
        ->getJson('/api/library/fines')
        ->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| Settlement — Paid and Waived
|--------------------------------------------------------------------------
*/

test('F8: partial payment reduces the balance', function () {
    $this->student->update(['total_fines' => 100]);

    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id,
            'amount' => 40,
            'type' => 'paid',
        ])
        ->assertStatus(200)
        ->assertJsonPath('settlement.new_balance', 60);

    expect((float) $this->student->fresh()->total_fines)->toBe(60.00);
});

test('F9: waiving is a distinct action that also reduces the balance', function () {
    $this->student->update(['total_fines' => 100]);

    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id,
            'amount' => 100,
            'type' => 'waived',
        ])
        ->assertStatus(200)
        ->assertJsonPath('settlement.type', 'waived')
        ->assertJsonPath('settlement.new_balance', 0);

    expect((float) $this->student->fresh()->total_fines)->toBe(0.00);
});

test('F10: settling more than owed never drives the balance below zero', function () {
    $this->student->update(['total_fines' => 25]);

    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id,
            'amount' => 999,
            'type' => 'paid',
        ])
        ->assertStatus(200)
        ->assertJsonPath('settlement.applied_amount', 25)
        ->assertJsonPath('settlement.new_balance', 0);

    expect((float) $this->student->fresh()->total_fines)->toBe(0.00);
});

test('F11: settling a zero balance is rejected', function () {
    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id,
            'amount' => 10,
            'type' => 'paid',
        ])
        ->assertStatus(422);
});

test('F12: an invalid settlement type is rejected', function () {
    $this->student->update(['total_fines' => 50]);

    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id,
            'amount' => 10,
            'type' => 'forgiven',
        ])
        ->assertStatus(422);
});

test('F13: a student cannot settle fines', function () {
    $this->student->update(['total_fines' => 50]);

    $this->withHeaders(finesAs('Student', 'fine_student'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id,
            'amount' => 50,
            'type' => 'waived',
        ])
        ->assertStatus(403);

    expect((float) $this->student->fresh()->total_fines)->toBe(50.00);
});

test('F14: settling unblocks checkout', function () {
    $this->student->update(['total_fines' => 50]);
    $book = Book::create([
        'book_title' => 'T2', 'author' => 'A', 'category' => 'CS',
        'isbn' => 'ISBN-'.uniqid(), 'physical_location' => 'S1', 'total_copies' => 0,
    ]);
    $copy = BookCopy::create(['book_id' => $book->book_id, 'condition' => 'good', 'availability_status' => 'available']);

    // Blocked while owing.
    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->student->user_id, 'copy_id' => $copy->copy_id])
        ->assertStatus(422);

    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id, 'amount' => 50, 'type' => 'waived',
        ])->assertStatus(200);

    // Allowed once settled.
    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->student->user_id, 'copy_id' => $copy->copy_id])
        ->assertStatus(201);
});
