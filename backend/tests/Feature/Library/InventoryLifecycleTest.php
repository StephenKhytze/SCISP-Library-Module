<?php

/**
 * Copy condition history, and archive-instead-of-delete for titles.
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

function lifeAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function lifeUser(string $username, string $dbRole, bool $isSuperAdmin = false): User
{
    $user = User::create([
        'username' => $username,
        'password' => 'password',
        'role' => $dbRole,
        'status' => 'active',
        'total_fines' => 0,
    ]);

    $user->forceFill(['is_super_admin' => $isSuperAdmin])->save();

    return $user;
}

function lifeBook(array $overrides = []): Book
{
    return Book::create(array_merge([
        'book_title' => 'Lifecycle Title '.uniqid(),
        'author' => 'Author',
        'category' => 'Computer Science',
        'isbn' => '978'.rand(1000000000, 9999999999),
        'physical_location' => 'Shelf L1',
        'total_copies' => 0,
    ], $overrides));
}

beforeEach(function () {
    $this->admin = lifeUser('life_admin', 'administrator');
    $this->superAdmin = lifeUser('life_super', 'administrator', true);
    $this->student = lifeUser('life_student', 'student');
    $this->faculty = lifeUser('life_faculty', 'faculty');
});

/*
|--------------------------------------------------------------------------
| Current copy condition
|--------------------------------------------------------------------------
*/

test('CD1: a librarian can change a copy\'s current condition', function () {
    $book = lifeBook();
    $copy = BookCopy::create(['book_id' => $book->book_id, 'condition' => 'good', 'availability_status' => 'available']);

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->putJson("/api/library/copies/{$copy->copy_id}", ['condition' => 'fair'])
        ->assertStatus(200);

    // Only the current value is stored; nothing records what it used to be.
    expect($copy->fresh()->condition)->toBe('fair');
});

test('CD2: only librarians may change a condition', function () {
    $book = lifeBook();
    $copy = BookCopy::create(['book_id' => $book->book_id, 'condition' => 'good', 'availability_status' => 'available']);

    $this->withHeaders(lifeAs('Student', 'life_student'))
        ->putJson("/api/library/copies/{$copy->copy_id}", ['condition' => 'poor'])
        ->assertStatus(403);

    $this->withHeaders(lifeAs('Teacher', 'life_faculty'))
        ->putJson("/api/library/copies/{$copy->copy_id}", ['condition' => 'poor'])
        ->assertStatus(403);

    expect($copy->fresh()->condition)->toBe('good');
});

/*
|--------------------------------------------------------------------------
| Archive instead of delete
|--------------------------------------------------------------------------
*/

test('AR1: an archived title disappears from the borrower catalog', function () {
    $book = lifeBook();

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson("/api/library/books/{$book->book_id}/archive", ['reason' => 'Superseded edition'])
        ->assertStatus(200);

    $this->withHeaders(lifeAs('Student', 'life_student'))
        ->getJson('/api/library/books')
        ->assertStatus(200)
        ->assertJsonPath('total', 0);
});

test('AR2: a librarian can still see archived titles on request', function () {
    $book = lifeBook();

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson("/api/library/books/{$book->book_id}/archive")->assertStatus(200);

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->getJson('/api/library/books?include_archived=1')
        ->assertStatus(200)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.is_archived', true);

    // A borrower cannot simply pass the same flag.
    $this->withHeaders(lifeAs('Student', 'life_student'))
        ->getJson('/api/library/books?include_archived=1')
        ->assertStatus(200)
        ->assertJsonPath('total', 0);
});

test('AR3: an archived title cannot be borrowed', function () {
    $book = lifeBook();
    $copy = BookCopy::create(['book_id' => $book->book_id, 'condition' => 'good', 'availability_status' => 'available']);

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson("/api/library/books/{$book->book_id}/archive")->assertStatus(200);

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $copy->copy_id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'This title has been archived and is no longer available for borrowing.');
});

test('AR4: an archived title cannot receive a hold', function () {
    $book = lifeBook();
    BookCopy::create(['book_id' => $book->book_id, 'condition' => 'good', 'availability_status' => 'available']);

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson("/api/library/books/{$book->book_id}/archive")->assertStatus(200);

    $this->withHeaders(lifeAs('Student', 'life_student'))
        ->postJson("/api/library/books/{$book->book_id}/holds")
        ->assertStatus(422)
        ->assertJsonPath('error', 'This title has been archived and is no longer available for borrowing.');
});

test('AR5: an archived title cannot receive new copies', function () {
    $book = lifeBook();

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson("/api/library/books/{$book->book_id}/archive")->assertStatus(200);

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson("/api/library/books/{$book->book_id}/copies", ['quantity' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error', 'This title is archived. Restore it before adding copies.');
});

test('AR6: an active loan blocks archiving, with a reason', function () {
    $book = lifeBook();
    $copy = BookCopy::create(['book_id' => $book->book_id, 'condition' => 'good', 'availability_status' => 'checked_out']);

    Transaction::create([
        'user_id' => $this->student->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDay(),
        'due_date' => now()->addDays(5),
        'status' => 'active',
    ]);

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson("/api/library/books/{$book->book_id}/archive")
        ->assertStatus(422)
        ->assertJsonPath('error', 'This title cannot be archived yet: 1 copy is still on loan.');

    expect($book->fresh()->isArchived())->toBeFalse();
});

test('AR7: a waiting or ready-for-pickup borrower blocks archiving', function () {
    $book = lifeBook();

    Hold::create([
        'user_id' => $this->student->user_id,
        'book_id' => $book->book_id,
        'request_date' => now(),
        'status' => 'pending',
        'queue_position' => 1,
    ]);

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson("/api/library/books/{$book->book_id}/archive")
        ->assertStatus(422)
        ->assertJsonPath('error', 'This title cannot be archived yet: 1 borrower is waiting or has a copy ready for pickup.');
});

test('AR8: a live course reserve blocks archiving', function () {
    $book = lifeBook();

    $section = CourseSection::create(['teacher_id' => $this->faculty->user_id, 'name' => 'Sec L']);

    CourseReserve::create([
        'section_id' => $section->section_id,
        'book_id' => $book->book_id,
        'user_id' => $this->faculty->user_id,
        'copies_requested' => 1,
        'status' => 'approved',
    ]);

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson("/api/library/books/{$book->book_id}/archive")
        ->assertStatus(422)
        ->assertJsonPath('error', 'This title cannot be archived yet: 1 course reserve still refers to it.');
});

test('AR9: history survives archiving', function () {
    $book = lifeBook();
    $copy = BookCopy::create(['book_id' => $book->book_id, 'condition' => 'good', 'availability_status' => 'checked_out']);

    $loan = Transaction::create([
        'user_id' => $this->student->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDays(5),
        'due_date' => now()->subDay(),
        'status' => 'returned',
        'actual_return_date' => now(),
    ]);

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson("/api/library/books/{$book->book_id}/archive")->assertStatus(200);

    // The loan row and its title are both still readable.
    expect(Transaction::find($loan->transaction_id))->not->toBeNull();

    $this->withHeaders(lifeAs('Student', 'life_student'))
        ->getJson('/api/library/loans/me/history')
        ->assertStatus(200)
        ->assertJsonPath('0.book_copy.book.book_title', $book->book_title);
});

test('AR10: a title can be restored', function () {
    $book = lifeBook();

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson("/api/library/books/{$book->book_id}/archive")->assertStatus(200);

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson("/api/library/books/{$book->book_id}/restore")
        ->assertStatus(200)
        ->assertJsonPath('book.is_archived', false);

    $this->withHeaders(lifeAs('Student', 'life_student'))
        ->getJson('/api/library/books')
        ->assertStatus(200)
        ->assertJsonPath('total', 1);
});

test('AR11: archiving twice is refused', function () {
    $book = lifeBook();

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson("/api/library/books/{$book->book_id}/archive")->assertStatus(200);

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson("/api/library/books/{$book->book_id}/archive")
        ->assertStatus(422)
        ->assertJsonPath('error', 'This title is already archived.');
});

test('AR12: only librarians may archive or restore', function () {
    $book = lifeBook();

    $this->withHeaders(lifeAs('Student', 'life_student'))
        ->postJson("/api/library/books/{$book->book_id}/archive")->assertStatus(403);

    $this->withHeaders(lifeAs('Teacher', 'life_faculty'))
        ->postJson("/api/library/books/{$book->book_id}/archive")->assertStatus(403);

    $this->withHeaders(lifeAs('Super Admin', 'life_super'))
        ->postJson("/api/library/books/{$book->book_id}/archive")->assertStatus(200);

    $this->withHeaders(lifeAs('Super Admin', 'life_super'))
        ->postJson("/api/library/books/{$book->book_id}/restore")->assertStatus(200);
});

test('AR13: archiving records who did it and why', function () {
    $book = lifeBook();

    $this->withHeaders(lifeAs('Admin', 'life_admin'))
        ->postJson("/api/library/books/{$book->book_id}/archive", ['reason' => 'Water damage, whole shelf'])
        ->assertStatus(200);

    $fresh = $book->fresh();

    expect($fresh->archived_at)->not->toBeNull();
    expect((int) $fresh->archived_by)->toBe((int) $this->admin->user_id);
    expect($fresh->archive_reason)->toBe('Water damage, whole shelf');
});
