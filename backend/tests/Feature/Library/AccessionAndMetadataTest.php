<?php

/**
 * Accession numbers, bibliographic metadata and duplicate prevention.
 *
 * ISBN identifies the TITLE AND EDITION.
 * The accession number identifies one EXACT PHYSICAL COPY.
 */

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\User;
use App\Services\AccessionNumberService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function accAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function accUser(string $username, string $dbRole, bool $isSuperAdmin = false): User
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

beforeEach(function () {
    $this->admin = accUser('acc_admin', 'administrator');
    $this->superAdmin = accUser('acc_super', 'administrator', true);
    $this->student = accUser('acc_student', 'student');
    $this->faculty = accUser('acc_faculty', 'faculty');
});

/** The payload the Add Title form sends. */
function accTitlePayload(array $overrides = []): array
{
    return array_merge([
        'book_title' => 'Clean Code',
        'author' => 'Robert C. Martin',
        'edition' => '1st Edition',
        'publisher' => 'Prentice Hall',
        'publication_year' => 2008,
        'category' => 'Computer Science',
        'isbn' => '9780132350884',
        'physical_location' => 'Shelf A22',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Accession numbers
|--------------------------------------------------------------------------
*/

test('AN1: a new copy is given an ABC-LIB accession number automatically', function () {
    $bookId = $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload(['copies' => 2]))
        ->assertStatus(201)
        ->json('book_id');

    $numbers = BookCopy::where('book_id', $bookId)->orderBy('copy_id')->pluck('accession_number');

    expect($numbers->all())->toBe(['ABC-LIB-000001', 'ABC-LIB-000002']);
});

test('AN2: the format is the prefix plus six padded digits', function () {
    expect(AccessionNumberService::format(1))->toBe('ABC-LIB-000001');
    expect(AccessionNumberService::format(42))->toBe('ABC-LIB-000042');
    expect(AccessionNumberService::format(999999))->toBe('ABC-LIB-999999');
});

test('AN3: numbers keep counting across different titles', function () {
    $first = $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload(['copies' => 2]))->json('book_id');

    $second = $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload([
            'isbn' => '9780262033848',
            'book_title' => 'Introduction to Algorithms',
            'copies' => 1,
        ]))->json('book_id');

    expect(BookCopy::where('book_id', $second)->value('accession_number'))->toBe('ABC-LIB-000003');
});

test('AN4: every accession number is unique', function () {
    $bookId = $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload(['copies' => 25]))->json('book_id');

    $numbers = BookCopy::pluck('accession_number');

    expect($numbers)->toHaveCount(25);
    expect($numbers->unique())->toHaveCount(25);
});

test('AN5: concurrent batches never collide', function () {
    // The counter is reserved under a row lock, so interleaved reservations
    // hand out disjoint blocks rather than repeating.
    $service = app(AccessionNumberService::class);

    $a = $service->reserve(3);
    $b = $service->reserve(3);

    expect(array_intersect($a, $b))->toBeEmpty();
    expect($a)->toBe(['ABC-LIB-000001', 'ABC-LIB-000002', 'ABC-LIB-000003']);
    expect($b)->toBe(['ABC-LIB-000004', 'ABC-LIB-000005', 'ABC-LIB-000006']);
});

test('AN6: the database refuses a duplicate accession number', function () {
    $bookId = $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload(['copies' => 1]))->json('book_id');

    $copy = new BookCopy(['book_id' => $bookId, 'condition' => 'good', 'availability_status' => 'available']);
    $copy->accession_number = 'ABC-LIB-000001'; // already taken

    expect(fn () => $copy->save())->toThrow(Illuminate\Database\QueryException::class);
});

test('AN7: an assigned accession number cannot be changed', function () {
    $bookId = $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload(['copies' => 1]))->json('book_id');

    $copy = BookCopy::where('book_id', $bookId)->first();
    $copy->accession_number = 'ABC-LIB-999999';

    expect(fn () => $copy->save())->toThrow(RuntimeException::class);
});

test('AN8: the accession number is not mass-assignable', function () {
    $book = Book::create(accTitlePayload(['total_copies' => 0]));

    $copy = BookCopy::create([
        'book_id' => $book->book_id,
        'condition' => 'good',
        'availability_status' => 'available',
        'accession_number' => 'ABC-LIB-HACKED',
    ]);

    expect($copy->accession_number)->toBeNull();
});

test('AN9: a copy is searchable by its accession number', function () {
    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload(['copies' => 1]))->assertStatus(201);

    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->getJson('/api/library/books?search=ABC-LIB-000001')
        ->assertStatus(200)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.book_title', 'Clean Code');
});

test('AN10: a copy without an accession falls back to a readable label', function () {
    $book = Book::create(accTitlePayload(['total_copies' => 0]));
    $copy = BookCopy::create(['book_id' => $book->book_id, 'condition' => 'good', 'availability_status' => 'available']);

    expect($copy->label)->toBe('CPY-'.$copy->copy_id);
});

/*
|--------------------------------------------------------------------------
| Bibliographic metadata
|--------------------------------------------------------------------------
*/

test('BM1: edition, publisher and publication year are stored', function () {
    $book = $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload())
        ->assertStatus(201)
        ->json();

    expect($book['edition'])->toBe('1st Edition');
    expect($book['publisher'])->toBe('Prentice Hall');
    expect($book['publication_year'])->toBe(2008);
});

test('BM2: they are optional', function () {
    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload([
            'edition' => null,
            'publisher' => null,
            'publication_year' => null,
        ]))
        ->assertStatus(201)
        ->assertJsonPath('edition', null)
        ->assertJsonPath('publication_year', null);
});

test('BM3: an implausible publication year is rejected', function () {
    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload(['publication_year' => 1200]))
        ->assertStatus(422);

    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload([
            'isbn' => '9780262033848',
            'publication_year' => (int) date('Y') + 5,
        ]))
        ->assertStatus(422);
});

test('BM4: metadata can be edited afterwards', function () {
    $bookId = $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload())->json('book_id');

    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->putJson("/api/library/books/{$bookId}", [
            'edition' => '2nd Edition',
            'publisher' => 'Pearson',
            'publication_year' => 2012,
        ])
        ->assertStatus(200)
        ->assertJsonPath('edition', '2nd Edition')
        ->assertJsonPath('publication_year', 2012);
});

/*
|--------------------------------------------------------------------------
| Duplicate prevention
|--------------------------------------------------------------------------
*/

test('DUP1: the same ISBN is refused with a conflict', function () {
    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload())->assertStatus(201);

    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload())
        ->assertStatus(409)
        ->assertJsonPath('duplicate', true)
        ->assertJsonPath('message', 'This book already exists in the catalog. Add another physical copy to the existing title instead.');

    expect(Book::count())->toBe(1);
});

test('DUP2: hyphenated and unhyphenated ISBNs are the same book', function () {
    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload(['isbn' => '978-0-13-235088-4']))
        ->assertStatus(201);

    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload(['isbn' => '9780132350884']))
        ->assertStatus(409);

    // Spaces are formatting too.
    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload(['isbn' => '978 0 13 235088 4']))
        ->assertStatus(409);

    expect(Book::count())->toBe(1);
});

test('DUP3: the conflict carries enough to offer Add Copy Instead', function () {
    $existingId = $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload())->json('book_id');

    $response = $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload(['isbn' => '978-0-13-235088-4']))
        ->assertStatus(409);

    $response->assertJsonPath('existing_book.book_id', $existingId)
        ->assertJsonPath('existing_book.book_title', 'Clean Code')
        ->assertJsonPath('existing_book.total_copies', 0);

    // And that id really does accept copies.
    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson("/api/library/books/{$existingId}/copies", ['quantity' => 1])
        ->assertStatus(201);
});

test('DUP4: a genuinely different edition is allowed', function () {
    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload())->assertStatus(201);

    // Same title and author, different ISBN — a real second edition.
    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload([
            'isbn' => '9780262033848',
            'edition' => '2nd Edition',
        ]))
        ->assertStatus(201);

    expect(Book::count())->toBe(2);
});

test('DUP5: with no ISBN, an exact bibliographic match is refused', function () {
    $payload = accTitlePayload(['isbn' => null]);

    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', $payload)->assertStatus(201);

    // Case and spacing are formatting, not identity.
    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', array_merge($payload, [
            'book_title' => '  clean   code ',
            'author' => 'ROBERT C. MARTIN',
        ]))
        ->assertStatus(409);

    expect(Book::count())->toBe(1);
});

test('DUP6: with no ISBN, a different edition or publisher is allowed', function () {
    $payload = accTitlePayload(['isbn' => null]);

    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', $payload)->assertStatus(201);

    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', array_merge($payload, ['edition' => '2nd Edition']))
        ->assertStatus(201);

    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', array_merge($payload, ['publisher' => 'Pearson']))
        ->assertStatus(201);

    expect(Book::count())->toBe(3);
});

test('DUP7: editing a title into an existing one is also refused', function () {
    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload())->assertStatus(201);

    $secondId = $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->postJson('/api/library/books', accTitlePayload(['isbn' => '9780262033848']))->json('book_id');

    $this->withHeaders(accAs('Admin', 'acc_admin'))
        ->putJson("/api/library/books/{$secondId}", ['isbn' => '978-0-13-235088-4'])
        ->assertStatus(409);
});

test('DUP8: the normalised key is derived, never supplied', function () {
    $book = Book::create(accTitlePayload([
        'total_copies' => 0,
        'isbn' => '978-0-13-235088-4',
        'isbn_normalized' => 'ATTACKER-SUPPLIED',
    ]));

    expect($book->fresh()->isbn_normalized)->toBe('9780132350884');
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

test('DUP9: only librarians may create titles', function () {
    $this->withHeaders(accAs('Student', 'acc_student'))
        ->postJson('/api/library/books', accTitlePayload())->assertStatus(403);

    $this->withHeaders(accAs('Teacher', 'acc_faculty'))
        ->postJson('/api/library/books', accTitlePayload())->assertStatus(403);

    $this->withHeaders(accAs('Super Admin', 'acc_super'))
        ->postJson('/api/library/books', accTitlePayload())->assertStatus(201);
});
