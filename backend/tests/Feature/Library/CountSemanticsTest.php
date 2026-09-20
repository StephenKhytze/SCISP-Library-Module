<?php

/**
 * Count / summary semantics.
 *
 *   active title      = books.archived_at IS NULL
 *   active copy       = copy not archived, under an active title
 *   available copy    = active copy, availability=available, condition not
 *                       damaged, not set aside for a course reserve
 *   dashboard summary = available active copies / active copies, over active
 *                       titles only, even when a librarian lists archived ones
 */

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\CourseReserve;
use App\Models\CourseSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/* ------------------------------------------------------------------ helpers */

function cxAdmin(): array
{
    return ['X-Mock-Role' => 'Admin', 'X-Mock-Username' => 'cx_admin'];
}

function cxStudent(): array
{
    return ['X-Mock-Role' => 'Student', 'X-Mock-Username' => 'cx_student'];
}

function cxUser(string $username, string $role): User
{
    return User::create([
        'username' => $username, 'password' => 'password', 'role' => $role,
        'status' => 'active', 'total_fines' => 0,
    ]);
}

function cxBook(string $title): Book
{
    return Book::create([
        'book_title' => $title, 'author' => 'Author', 'category' => 'Computer Science',
        'isbn' => 'ISBN-'.uniqid(), 'physical_location' => 'Shelf X1', 'total_copies' => 0,
    ]);
}

function cxCopy(Book $book, string $status = 'available', string $condition = 'good', ?int $reserveId = null): BookCopy
{
    $copy = BookCopy::create([
        'book_id' => $book->book_id, 'condition' => $condition,
        'availability_status' => $status, 'reserve_id' => $reserveId,
    ]);
    $book->increment('total_copies');

    return $copy;
}

function cxReserve(Book $book, User $teacher): CourseReserve
{
    $section = CourseSection::create(['teacher_id' => $teacher->user_id, 'name' => 'Section '.uniqid()]);

    return CourseReserve::create([
        'section_id' => $section->section_id, 'book_id' => $book->book_id,
        'user_id' => $teacher->user_id, 'copies_requested' => 1, 'status' => 'approved',
    ]);
}

/** The catalog list as the librarian dashboard loads it (archived titles included). */
function cxList($test, ?array $headers = null, bool $includeArchived = true): array
{
    $query = $includeArchived ? '?include_archived=1&per_page=12' : '?per_page=12';

    return $test->withHeaders($headers ?? cxAdmin())->getJson('/api/library/books'.$query)->assertOk()->json();
}

function cxSummary($test, ?array $headers = null): array
{
    return cxList($test, $headers)['summary'];
}

/** The three catalog counts, without the system-wide desk totals. */
function cxCore(array $summary): array
{
    return array_intersect_key($summary, array_flip(['active_titles', 'active_copies', 'available_copies']));
}

function cxTitle(array $list, int $bookId): array
{
    return collect($list['data'])->firstWhere('book_id', $bookId);
}

function cxArchiveBook($test, Book $book)
{
    return $test->withHeaders(cxAdmin())->postJson("/api/library/books/{$book->book_id}/archive", ['reason' => 'test'])->assertOk();
}

function cxArchiveCopy($test, BookCopy $copy)
{
    return $test->withHeaders(cxAdmin())->postJson("/api/library/copies/{$copy->copy_id}/archive", ['reason' => 'test'])->assertOk();
}

beforeEach(function () {
    $this->admin = cxUser('cx_admin', 'administrator');
    $this->student = cxUser('cx_student', 'student');
    $this->faculty = cxUser('cx_faculty', 'faculty');
});

/* -------------------------------------------------------------- TITLE COUNT */

test('CX1: 3 titles, 1 archived -> active title count is 2', function () {
    $a = cxBook('A'); cxCopy($a);
    cxBook('B'); cxBook('C');

    cxArchiveBook($this, $a);

    $list = cxList($this);
    expect($list['total'])->toBe(3)                       // the librarian list still shows it
        ->and($list['summary']['active_titles'])->toBe(2); // the dashboard does not count it
});

test('CX2: restoring the title brings the active title count back to 3', function () {
    $a = cxBook('A'); cxCopy($a);
    cxBook('B'); cxBook('C');
    cxArchiveBook($this, $a);

    $this->withHeaders(cxAdmin())->postJson("/api/library/books/{$a->book_id}/restore")->assertOk();

    expect(cxSummary($this)['active_titles'])->toBe(3);
});

/* -------------------------------------------------------------- COPY COUNTS */

test('CX3: an archived copy is excluded from active_copies_count', function () {
    $book = cxBook('A');
    cxCopy($book);
    cxArchiveCopy($this, cxCopy($book));

    $row = cxTitle(cxList($this), $book->book_id);
    expect($row['total_copies'])->toBe(2)
        ->and($row['active_copies_count'])->toBe(1);
});

test('CX4: an archived copy is excluded from available_copies_count', function () {
    $book = cxBook('A');
    cxCopy($book);
    cxArchiveCopy($this, cxCopy($book));

    expect(cxTitle(cxList($this), $book->book_id)['available_copies_count'])->toBe(1)
        ->and(cxSummary($this)['available_copies'])->toBe(1);
});

test('CX5: a damaged copy is not available', function () {
    $book = cxBook('A');
    cxCopy($book, 'damaged', 'damaged');

    expect(cxTitle(cxList($this), $book->book_id)['available_copies_count'])->toBe(0);
});

test('CX6: a copy whose condition is damaged is never counted available', function () {
    $book = cxBook('A');
    // A legacy contradiction the D-1 guard now prevents creating via the API.
    cxCopy($book, 'available', 'damaged');

    expect(cxTitle(cxList($this), $book->book_id)['available_copies_count'])->toBe(0)
        ->and(cxSummary($this)['available_copies'])->toBe(0);
});

test('CX7: a lost copy is not available', function () {
    $book = cxBook('A');
    cxCopy($book, 'lost');

    expect(cxTitle(cxList($this), $book->book_id)['available_copies_count'])->toBe(0)
        ->and(cxSummary($this)['active_copies'])->toBe(1);
});

test('CX8: a reserve-linked shelf copy is not general-available, but is active and reserved', function () {
    $book = cxBook('A');
    $reserve = cxReserve($book, $this->faculty);
    cxCopy($book, 'available', 'good', $reserve->reserve_id);

    $row = cxTitle(cxList($this), $book->book_id);
    expect($row['available_copies_count'])->toBe(0)
        ->and($row['reserved_copies_count'])->toBe(1)
        ->and($row['active_copies_count'])->toBe(1);
});

test('CX9: copies under an archived title do not count in the dashboard', function () {
    $archived = cxBook('A'); cxCopy($archived); cxCopy($archived);
    $live = cxBook('B'); cxCopy($live);
    cxArchiveBook($this, $archived);

    expect(cxCore(cxSummary($this)))->toBe(['active_titles' => 1, 'active_copies' => 1, 'available_copies' => 1]);
});

/* -------------------------------------------------------- DASHBOARD SUMMARY */

test('CX10: numerator = available active copies, denominator = active copies', function () {
    $book = cxBook('A');
    cxCopy($book);                                   // available
    cxCopy($book, 'checked_out');                    // active, not available
    cxCopy($book, 'damaged', 'damaged');             // active, not available
    cxArchiveCopy($this, cxCopy($book));             // not active

    $s = cxSummary($this);
    expect($s['available_copies'])->toBe(1)
        ->and($s['active_copies'])->toBe(3);
});

test('CX11: the summary covers every matching title, not just the current page', function () {
    foreach (range(1, 14) as $i) {
        cxCopy(cxBook('T'.str_pad($i, 2, '0', STR_PAD_LEFT)));
    }

    $list = cxList($this);
    expect(count($list['data']))->toBe(12)
        ->and(cxCore($list['summary']))->toBe(['active_titles' => 14, 'active_copies' => 14, 'available_copies' => 14]);
});

test('CX12: a borrower sees the same active summary', function () {
    $archived = cxBook('A'); cxCopy($archived);
    $live = cxBook('B'); cxCopy($live); cxCopy($live, 'checked_out');
    cxArchiveBook($this, $archived);

    $list = cxList($this, cxStudent(), false);
    expect($list['total'])->toBe(1)
        ->and(cxCore($list['summary']))->toBe(['active_titles' => 1, 'active_copies' => 2, 'available_copies' => 1]);
});

/* ---------------------------------------------------------------- PER-TITLE */

test('CX13: one available + one damaged -> 1 / 2', function () {
    $book = cxBook('A');
    cxCopy($book);
    cxCopy($book, 'damaged', 'damaged');

    $row = cxTitle(cxList($this), $book->book_id);
    expect([$row['available_copies_count'], $row['active_copies_count']])->toBe([1, 2]);
});

test('CX14: one archived + one available -> 1 / 1 active', function () {
    $book = cxBook('A');
    cxCopy($book);
    cxArchiveCopy($this, cxCopy($book));

    $row = cxTitle(cxList($this), $book->book_id);
    expect([$row['available_copies_count'], $row['active_copies_count']])->toBe([1, 1]);
});

test('CX15: the live Clean Code shape (reserve copy + archived damaged copy) -> 0 / 1', function () {
    $book = cxBook('Clean Code');
    $reserve = cxReserve($book, $this->faculty);
    cxCopy($book, 'available', 'good', $reserve->reserve_id);
    cxArchiveCopy($this, cxCopy($book, 'damaged', 'new'));

    $row = cxTitle(cxList($this), $book->book_id);
    expect([$row['available_copies_count'], $row['active_copies_count'], $row['reserved_copies_count']])->toBe([0, 1, 1]);

    // The detail endpoint agrees with the list.
    $detail = $this->withHeaders(cxAdmin())->getJson("/api/library/books/{$book->book_id}")->assertOk()->json();
    $detail = $detail['book'] ?? $detail;
    expect([$detail['available_copies_count'], $detail['active_copies_count']])->toBe([0, 1]);
});

test('CX16: an archived title contributes nothing to the dashboard', function () {
    $book = cxBook('A'); cxCopy($book); cxCopy($book);
    cxArchiveBook($this, $book);

    expect(cxCore(cxSummary($this)))->toBe(['active_titles' => 0, 'active_copies' => 0, 'available_copies' => 0]);
});

/* ------------------------------------------------------------------ RESTORE */

test('CX17: restoring a title restores its counts', function () {
    $book = cxBook('A'); cxCopy($book); cxCopy($book, 'damaged', 'damaged');
    cxArchiveBook($this, $book);

    $this->withHeaders(cxAdmin())->postJson("/api/library/books/{$book->book_id}/restore")->assertOk();

    expect(cxCore(cxSummary($this)))->toBe(['active_titles' => 1, 'active_copies' => 2, 'available_copies' => 1]);
});

test('CX18: restoring a copy restores the active counts', function () {
    $book = cxBook('A');
    cxCopy($book);
    $copy = cxCopy($book);
    cxArchiveCopy($this, $copy);

    expect(cxSummary($this)['active_copies'])->toBe(1);

    $this->withHeaders(cxAdmin())->postJson("/api/library/copies/{$copy->copy_id}/restore")->assertOk();

    $row = cxTitle(cxList($this), $book->book_id);
    expect(cxCore(cxSummary($this)))->toBe(['active_titles' => 1, 'active_copies' => 2, 'available_copies' => 2])
        ->and([$row['available_copies_count'], $row['active_copies_count']])->toBe([2, 2]);
});

test('CX19: the summary follows the catalog search filter', function () {
    cxCopy(cxBook('Algorithms'));
    cxCopy(cxBook('Refactoring'));

    $list = $this->withHeaders(cxAdmin())->getJson('/api/library/books?include_archived=1&search=Algo')->assertOk()->json();
    expect(cxCore($list['summary']))->toBe(['active_titles' => 1, 'active_copies' => 1, 'available_copies' => 1]);
});

/* ------------------------------------------- DESK TOTALS (checked out / held) */

test('CX20: checked out and held are counted system-wide, not per page', function () {
    // 14 titles: the catalog serves 12 per page, so these two land on page 2.
    foreach (range(1, 12) as $i) {
        cxCopy(cxBook('T'.str_pad($i, 2, '0', STR_PAD_LEFT)));
    }
    cxCopy(cxBook('Z1 out'), 'checked_out');
    cxCopy(cxBook('Z2 held'), 'on_hold');

    $list = cxList($this);

    expect(count($list['data']))->toBe(12)
        ->and(collect($list['data'])->pluck('book_title'))->not->toContain('Z1 out')
        ->and($list['summary']['checked_out_copies'])->toBe(1)
        ->and($list['summary']['on_hold_copies'])->toBe(1);
});

test('CX21: an archived copy is not counted as checked out or held', function () {
    $book = cxBook('A');
    $out = cxCopy($book, 'checked_out');
    $held = cxCopy($book, 'on_hold');
    cxCopy($book);

    expect(cxSummary($this)['checked_out_copies'])->toBe(1);

    // Archive both, through the model: the API refuses to archive a copy that
    // is out or held, and this test is about the COUNT, not that guard.
    foreach ([$out, $held] as $copy) {
        $copy->forceFill(['archived_at' => now(), 'archived_by' => $this->admin->user_id])->save();
    }

    $s = cxSummary($this);
    expect($s['checked_out_copies'])->toBe(0)
        ->and($s['on_hold_copies'])->toBe(0);
});

test('CX22: copies under an archived title are not counted as checked out or held', function () {
    $book = cxBook('A');
    cxCopy($book, 'checked_out');
    cxCopy($book, 'on_hold');
    $book->forceFill(['archived_at' => now()])->save();

    $s = cxSummary($this);
    expect($s['checked_out_copies'])->toBe(0)
        ->and($s['on_hold_copies'])->toBe(0);
});

test('CX23: the desk totals ignore the catalog search and category filter', function () {
    cxCopy(cxBook('Algorithms'), 'checked_out');
    cxCopy(cxBook('Refactoring'), 'on_hold');

    // The catalog counts narrow to the search; the desk totals do not, because
    // they answer "what is out right now", not "what matched".
    $list = $this->withHeaders(cxAdmin())->getJson('/api/library/books?include_archived=1&search=Algo')->assertOk()->json();

    expect(cxCore($list['summary']))->toBe(['active_titles' => 1, 'active_copies' => 1, 'available_copies' => 0])
        ->and($list['summary']['checked_out_copies'])->toBe(1)
        ->and($list['summary']['on_hold_copies'])->toBe(1);
});

test('CX24: a borrower sees the same desk totals', function () {
    cxCopy(cxBook('A'), 'checked_out');
    cxCopy(cxBook('B'), 'on_hold');

    expect(cxSummary($this, cxStudent()))->toBe([
        'active_titles' => 2, 'active_copies' => 2, 'available_copies' => 0,
        'checked_out_copies' => 1, 'on_hold_copies' => 1,
    ]);
});
