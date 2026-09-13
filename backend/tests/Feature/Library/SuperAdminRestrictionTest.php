<?php

/**
 * Super Admin is management-only in the Library.
 *
 * Confirmed rule:
 *   Super Admin MAY   run circulation, decide renewals, manage fines,
 *                     inventory, holds and course reserves
 *   Super Admin MAY NOT be a borrower in any form
 *   Admin       MAY   still borrow, and keeps full librarian access
 *
 * The distinction has to be stored, not claimed: on checkout the borrower
 * arrives as a user_id and sends no role header of their own.
 */

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function saAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function saUser(string $username, string $dbRole, bool $isSuperAdmin = false): User
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

function saBook(): Book
{
    return Book::create([
        'book_title' => 'Restriction Title',
        'author' => 'Author',
        'category' => 'Computer Science',
        'isbn' => 'ISBN-'.uniqid(),
        'physical_location' => 'Shelf S1',
        'total_copies' => 0,
    ]);
}

function saCopy(?Book $book = null, string $status = 'available'): BookCopy
{
    return BookCopy::create([
        'book_id' => ($book ?: saBook())->book_id,
        'condition' => 'good',
        'availability_status' => $status,
    ]);
}

beforeEach(function () {
    $this->student = saUser('sa_student', 'student');
    $this->admin = saUser('sa_admin', 'administrator');
    $this->superAdmin = saUser('sa_superadmin', 'administrator', true);
});

/*
|--------------------------------------------------------------------------
| Identity: the two administrator personas are distinguishable
|--------------------------------------------------------------------------
*/

test('SA1: Admin and Super Admin share the stored role but not the flag', function () {
    expect($this->admin->role)->toBe('administrator');
    expect($this->superAdmin->role)->toBe('administrator');

    expect($this->admin->isSuperAdmin())->toBeFalse();
    expect($this->superAdmin->isSuperAdmin())->toBeTrue();

    expect($this->admin->canBorrow())->toBeTrue();
    expect($this->superAdmin->canBorrow())->toBeFalse();
});

test('SA2: a Super Admin cannot masquerade as an Admin to escape the rule', function () {
    $this->withHeaders(saAs('Admin', 'sa_superadmin'))
        ->getJson('/api/library/books')
        ->assertStatus(403)
        ->assertJsonPath('message', 'Forbidden. Supplied role does not match the user account.');
});

test('SA3: an Admin cannot claim Super Admin either', function () {
    $this->withHeaders(saAs('Super Admin', 'sa_admin'))
        ->getJson('/api/library/books')
        ->assertStatus(403);
});

test('SA4: the summary reports the distinction to the client', function () {
    $this->withHeaders(saAs('Super Admin', 'sa_superadmin'))
        ->getJson('/api/library/me/summary')
        ->assertStatus(200)
        ->assertJsonPath('is_super_admin', true)
        ->assertJsonPath('can_borrow', false)
        ->assertJsonPath('borrow_limit', 0);

    $this->withHeaders(saAs('Admin', 'sa_admin'))
        ->getJson('/api/library/me/summary')
        ->assertStatus(200)
        ->assertJsonPath('is_super_admin', false)
        ->assertJsonPath('can_borrow', true);
});

/*
|--------------------------------------------------------------------------
| Super Admin may not borrow
|--------------------------------------------------------------------------
*/

test('SA5: a Super Admin cannot be selected as the borrower at checkout', function () {
    $copy = saCopy();

    // Operated by an ordinary Admin — this is about the BORROWER, not the desk.
    $this->withHeaders(saAs('Admin', 'sa_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->superAdmin->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'Super Admin accounts cannot borrow library materials.');

    expect(Transaction::count())->toBe(0);
    expect($copy->fresh()->availability_status)->toBe('available');
});

test('SA6: a Super Admin cannot check a book out to themselves', function () {
    $copy = saCopy();

    $this->withHeaders(saAs('Super Admin', 'sa_superadmin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->superAdmin->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'Super Admin accounts cannot borrow library materials.');
});

test('SA7: a Super Admin cannot place a hold', function () {
    $book = saBook();
    saCopy($book);

    $this->withHeaders(saAs('Super Admin', 'sa_superadmin'))
        ->postJson("/api/library/books/{$book->book_id}/holds")
        ->assertStatus(422)
        ->assertJsonPath('error', 'Super Admin accounts cannot borrow library materials.');
});

test('SA8: a Super Admin cannot request a renewal', function () {
    $copy = saCopy(null, 'checked_out');

    // Even with a loan row forced into existence, the route refuses them.
    $loan = Transaction::create([
        'user_id' => $this->superAdmin->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDay(),
        'due_date' => now()->addDays(5),
        'status' => 'active',
    ]);

    $this->withHeaders(saAs('Super Admin', 'sa_superadmin'))
        ->postJson('/api/library/renewals', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(403);
});

test('SA9: a Super Admin summary reports no loans, holds or limit', function () {
    $this->withHeaders(saAs('Super Admin', 'sa_superadmin'))
        ->getJson('/api/library/me/summary')
        ->assertStatus(200)
        ->assertJsonPath('active_loans', 0)
        ->assertJsonPath('overdue_loans', 0)
        ->assertJsonPath('holds', 0)
        ->assertJsonPath('pending_renewals', 0);
});

/*
|--------------------------------------------------------------------------
| Admin may still borrow
|--------------------------------------------------------------------------
*/

test('SA10: an Admin can still be checked out a book', function () {
    $copy = saCopy();

    $this->withHeaders(saAs('Admin', 'sa_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->admin->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(201);

    expect(Transaction::where('user_id', $this->admin->user_id)->where('status', 'active')->count())->toBe(1);
});

test('SA11: an Admin can request a renewal of their own loan', function () {
    $copy = saCopy(null, 'checked_out');

    $loan = Transaction::create([
        'user_id' => $this->admin->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDay(),
        'due_date' => now()->addDays(5),
        'status' => 'active',
    ]);

    $this->withHeaders(saAs('Admin', 'sa_admin'))
        ->postJson('/api/library/renewals', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(201);
});

/*
|--------------------------------------------------------------------------
| Super Admin keeps every librarian power
|--------------------------------------------------------------------------
*/

test('SA12: a Super Admin can still run the circulation desk', function () {
    $copy = saCopy();

    // Check a book out TO A STUDENT while operating as Super Admin.
    $this->withHeaders(saAs('Super Admin', 'sa_superadmin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(201);

    $transactionId = Transaction::where('user_id', $this->student->user_id)->value('transaction_id');

    $this->withHeaders(saAs('Super Admin', 'sa_superadmin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $transactionId])
        ->assertStatus(200);
});

test('SA13: a Super Admin keeps the other librarian screens', function () {
    $this->withHeaders(saAs('Super Admin', 'sa_superadmin'))
        ->getJson('/api/library/circulation')->assertStatus(200);

    $this->withHeaders(saAs('Super Admin', 'sa_superadmin'))
        ->getJson('/api/library/fines')->assertStatus(200);

    $this->withHeaders(saAs('Super Admin', 'sa_superadmin'))
        ->getJson('/api/library/holds')->assertStatus(200);

    $this->withHeaders(saAs('Super Admin', 'sa_superadmin'))
        ->getJson('/api/library/reserves')->assertStatus(200);

    $this->withHeaders(saAs('Super Admin', 'sa_superadmin'))
        ->getJson('/api/library/renewals')->assertStatus(200);

    $this->withHeaders(saAs('Super Admin', 'sa_superadmin'))
        ->getJson('/api/library/students')->assertStatus(200);
});

test('SA14: a Super Admin can still settle a fine', function () {
    $this->student->update(['total_fines' => 50.00]);

    $this->withHeaders(saAs('Super Admin', 'sa_superadmin'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id,
            'amount' => 50.00,
            'type' => 'paid',
        ])
        ->assertStatus(200);

    expect((float) $this->student->fresh()->total_fines)->toBe(0.0);
});

test('SA15: a Super Admin can still add inventory', function () {
    $this->withHeaders(saAs('Super Admin', 'sa_superadmin'))
        ->postJson('/api/library/books', [
            'book_title' => 'Added by Super Admin',
            'author' => 'Author',
            'category' => 'Computer Science',
            'isbn' => 'ISBN-'.uniqid(),
            'physical_location' => 'Shelf Z9',
            'copies' => 2,
        ])
        ->assertStatus(201);
});
