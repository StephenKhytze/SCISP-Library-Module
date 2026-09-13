<?php

/**
 * Book category vocabulary.
 *
 * Categories used to exist only as whatever string some book happened to
 * carry, which made "create a category" impossible and let three spellings of
 * the same category coexist. These tests pin both halves of the fix: only a
 * librarian may add to the vocabulary, and a name that differs from an
 * existing one only by case or spacing is the same category.
 */

use App\Models\Book;
use App\Models\LibraryCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function catAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function catUser(string $username, string $dbRole, bool $isSuperAdmin = false): User
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

function catBook(string $title, string $category, string $isbn): Book
{
    return Book::create([
        'book_title' => $title,
        'author' => 'Test Author',
        'category' => $category,
        'isbn' => $isbn,
        'physical_location' => 'Shelf A1',
        'total_copies' => 0,
    ]);
}

beforeEach(function () {
    catUser('Admin_User_00001', 'administrator');
    catUser('SysAdmin_001', 'administrator', true);
    catUser('DelaCruz_Juan_C1234', 'student');
    catUser('Santos_Maria_F12', 'faculty');
});

/* ------------------------------------------------------------------ create */

test('CAT1: an Admin can create a category', function () {
    $res = $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->postJson('/api/library/categories', ['name' => 'Computer Science']);

    $res->assertStatus(201)
        ->assertJsonPath('category.name', 'Computer Science');

    expect(LibraryCategory::where('normalized_name', 'computer science')->exists())->toBeTrue();
});

test('CAT2: a Super Admin can create a category', function () {
    $this->withHeaders(catAs('Super Admin', 'SysAdmin_001'))
        ->postJson('/api/library/categories', ['name' => 'Mathematics'])
        ->assertStatus(201);

    expect(LibraryCategory::count())->toBe(1);
});

test('CAT3: the creator is recorded', function () {
    $admin = User::where('username', 'Admin_User_00001')->first();

    $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->postJson('/api/library/categories', ['name' => 'Philosophy'])
        ->assertStatus(201);

    expect(LibraryCategory::first()->created_by)->toBe($admin->user_id);
});

/* ----------------------------------------------------------- authorization */

test('CAT4: a student cannot create a category', function () {
    $this->withHeaders(catAs('Student', 'DelaCruz_Juan_C1234'))
        ->postJson('/api/library/categories', ['name' => 'Smuggled'])
        ->assertStatus(403);

    expect(LibraryCategory::count())->toBe(0);
});

test('CAT5: a teacher cannot create a category', function () {
    $this->withHeaders(catAs('Teacher', 'Santos_Maria_F12'))
        ->postJson('/api/library/categories', ['name' => 'Smuggled'])
        ->assertStatus(403);

    expect(LibraryCategory::count())->toBe(0);
});

test('CAT6: a student cannot rename a category', function () {
    $category = LibraryCategory::create(['name' => 'Computer Science']);

    $this->withHeaders(catAs('Student', 'DelaCruz_Juan_C1234'))
        ->putJson("/api/library/categories/{$category->category_id}", ['name' => 'Hacked'])
        ->assertStatus(403);

    expect($category->fresh()->name)->toBe('Computer Science');
});

test('CAT7: every role can read the category list', function () {
    LibraryCategory::create(['name' => 'Computer Science']);

    foreach ([
        ['Student', 'DelaCruz_Juan_C1234'],
        ['Teacher', 'Santos_Maria_F12'],
        ['Admin', 'Admin_User_00001'],
        ['Super Admin', 'SysAdmin_001'],
    ] as [$role, $username]) {
        $this->withHeaders(catAs($role, $username))
            ->getJson('/api/library/categories')
            ->assertOk()
            ->assertJsonFragment(['Computer Science']);
    }
});

/* ------------------------------------------------------------- duplicates */

test('CAT8: an exact duplicate is refused with 409', function () {
    LibraryCategory::create(['name' => 'Computer Science']);

    $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->postJson('/api/library/categories', ['name' => 'Computer Science'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'This category already exists.');

    expect(LibraryCategory::count())->toBe(1);
});

test('CAT9: a case-insensitive duplicate is refused', function () {
    LibraryCategory::create(['name' => 'Computer Science']);

    $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->postJson('/api/library/categories', ['name' => 'computer science'])
        ->assertStatus(409);

    expect(LibraryCategory::count())->toBe(1);
});

test('CAT10: a whitespace-padded duplicate is refused', function () {
    LibraryCategory::create(['name' => 'Computer Science']);

    $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->postJson('/api/library/categories', ['name' => '  Computer   Science  '])
        ->assertStatus(409);

    expect(LibraryCategory::count())->toBe(1);
});

test('CAT11: the stored name is trimmed and whitespace-collapsed', function () {
    $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->postJson('/api/library/categories', ['name' => '  Computer   Science  '])
        ->assertStatus(201)
        ->assertJsonPath('category.name', 'Computer Science');
});

test('CAT12: an empty or whitespace-only name is refused', function () {
    $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->postJson('/api/library/categories', ['name' => '   '])
        ->assertStatus(422);

    expect(LibraryCategory::count())->toBe(0);
});

/* --------------------------------------------------------------- existing */

test('CAT13: categories already on books are preserved and listed', function () {
    catBook('Clean Code', 'Software Engineering', '111');
    catBook('SICP', 'Computer Science', '222');

    $res = $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->getJson('/api/library/categories')
        ->assertOk();

    expect($res->json())->toContain('Software Engineering')
        ->and($res->json())->toContain('Computer Science');
});

test('CAT14: renaming a category carries across to its books', function () {
    $book = catBook('Clean Code', 'Software Engineerings', '111');
    $category = LibraryCategory::create(['name' => 'Software Engineerings']);

    $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->putJson("/api/library/categories/{$category->category_id}", ['name' => 'Software Engineering'])
        ->assertOk();

    expect($book->fresh()->category)->toBe('Software Engineering');
});

test('CAT15: a rename onto an existing name is refused', function () {
    LibraryCategory::create(['name' => 'Computer Science']);
    $other = LibraryCategory::create(['name' => 'Mathematics']);

    $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->putJson("/api/library/categories/{$other->category_id}", ['name' => 'COMPUTER SCIENCE'])
        ->assertStatus(409);

    expect($other->fresh()->name)->toBe('Mathematics');
});

/* ------------------------------------------------------------- book saving */

test('CAT16: adding a book accepts a created category', function () {
    $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->postJson('/api/library/categories', ['name' => 'Computer Science'])
        ->assertStatus(201);

    $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->postJson('/api/library/books', [
            'book_title' => 'Structure and Interpretation',
            'author' => 'Abelson',
            'category' => 'Computer Science',
            'isbn' => '978-0262510875',
            'physical_location' => 'Shelf B2',
        ])->assertStatus(201);

    expect(Book::first()->category)->toBe('Computer Science');
});

test('CAT17: a book saved with different casing folds onto the existing category', function () {
    LibraryCategory::create(['name' => 'Computer Science']);

    $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->postJson('/api/library/books', [
            'book_title' => 'SICP',
            'author' => 'Abelson',
            'category' => '  computer   SCIENCE ',
            'isbn' => '978-0262510875',
            'physical_location' => 'Shelf B2',
        ])->assertStatus(201);

    // Stored under the canonical spelling, and no second category created.
    expect(Book::first()->category)->toBe('Computer Science')
        ->and(LibraryCategory::count())->toBe(1);
});

test('CAT18: editing a book accepts a created category', function () {
    $book = catBook('Clean Code', 'Computer Science', '111');
    LibraryCategory::create(['name' => 'Software Engineering']);

    $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->putJson("/api/library/books/{$book->book_id}", ['category' => 'Software Engineering'])
        ->assertOk();

    expect($book->fresh()->category)->toBe('Software Engineering');
});

test('CAT19: saving a book registers a category nobody created yet', function () {
    $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->postJson('/api/library/books', [
            'book_title' => 'Dune',
            'author' => 'Herbert',
            'category' => 'Science Fiction',
            'isbn' => '978-0441013593',
            'physical_location' => 'Shelf C1',
        ])->assertStatus(201);

    expect(LibraryCategory::where('normalized_name', 'science fiction')->exists())->toBeTrue();
});

/* ------------------------------------------------------------- filtering */

test('CAT20: the catalog filters by category', function () {
    catBook('Clean Code', 'Software Engineering', '111');
    catBook('SICP', 'Computer Science', '222');

    $res = $this->withHeaders(catAs('Student', 'DelaCruz_Juan_C1234'))
        ->getJson('/api/library/books?category=Computer Science')
        ->assertOk();

    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.book_title'))->toBe('SICP');
});

test('CAT21: the category filter ignores casing and spacing', function () {
    catBook('SICP', 'Computer Science', '222');

    $res = $this->withHeaders(catAs('Student', 'DelaCruz_Juan_C1234'))
        ->getJson('/api/library/books?category=' . urlencode('  computer science '))
        ->assertOk();

    expect($res->json('data'))->toHaveCount(1);
});

test('CAT22: search and category apply together', function () {
    catBook('Introduction to Algorithms', 'Computer Science', '111');
    catBook('Algorithms of Oppression', 'Sociology', '222');
    catBook('Clean Code', 'Computer Science', '333');

    $res = $this->withHeaders(catAs('Student', 'DelaCruz_Juan_C1234'))
        ->getJson('/api/library/books?search=Algorithms&category=Computer Science')
        ->assertOk();

    // Both filters, not either: the Sociology match and the non-matching
    // Computer Science title are each excluded.
    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.book_title'))->toBe('Introduction to Algorithms');
});

test('CAT23: omitting the category returns every title', function () {
    catBook('Clean Code', 'Software Engineering', '111');
    catBook('SICP', 'Computer Science', '222');

    $res = $this->withHeaders(catAs('Student', 'DelaCruz_Juan_C1234'))
        ->getJson('/api/library/books?search=')
        ->assertOk();

    expect($res->json('data'))->toHaveCount(2);
});

test('CAT24: the detailed listing returns records with ids', function () {
    LibraryCategory::create(['name' => 'Computer Science']);

    $res = $this->withHeaders(catAs('Admin', 'Admin_User_00001'))
        ->getJson('/api/library/categories?detailed=1')
        ->assertOk();

    expect($res->json('0.name'))->toBe('Computer Science')
        ->and($res->json('0.category_id'))->not->toBeNull();
});
