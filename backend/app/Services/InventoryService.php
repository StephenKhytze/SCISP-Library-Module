<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Hold;
use App\Models\Transaction;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * General-circulation availability.
     *
     * A copy allocated to a course reserve is NOT part of general stock: it is
     * set aside for one section and must never be offered to a general
     * borrower, so it is excluded here even while it sits on the shelf.
     */
    public static function generalAvailabilityCounts(): array
    {
        return [
            // Borrowable from general stock right now: in the collection,
            // free, and not set aside for a course reserve.
            'copies as available_copies_count' => function ($q) {
                self::whereGenerallyAvailable($q);
            },
            // Surfaced separately so the catalog can explain the difference
            // between "we own 5" and "5 you can borrow".
            'copies as reserved_copies_count' => function ($q) {
                $q->active()->whereNotNull('reserve_id');
            },
            // Copies still in the collection. books.total_copies stays the
            // number of physical copies ever registered, archived included.
            'copies as active_copies_count' => function ($q) {
                $q->active();
            },
        ];
    }

    /**
     * The one definition of "borrowable from general stock right now": in the
     * collection, on the shelf and free, not damaged, and not set aside for a
     * course reserve. Lost, checked-out and on-hold copies fail the status test.
     */
    public static function whereGenerallyAvailable($q)
    {
        return $q->active()
            ->where('availability_status', 'available')
            ->where('condition', '!=', 'damaged')
            ->whereNull('reserve_id');
    }

    /**
     * Search and filter books with live availability calculation.
     */
    public function searchBooks(array $filters = []): LengthAwarePaginator
    {
        $query = $this->filteredQuery($filters);

        // Archived titles are hidden from borrowers. A librarian passing
        // include_archived=1 sees them; nobody else can.
        if (empty($filters['include_archived'])) {
            $query->notArchived();
        }

        // Compute live availability using subquery and eager-load copies
        $query->with(['copies'])->withCount(self::generalAvailabilityCounts());

        $perPage = (int) ($filters['per_page'] ?? 15);
        $perPage = max(1, min($perPage, 100));

        return $query->orderBy('book_title')->paginate($perPage);
    }

    /**
     * Active-library totals for the dashboard, over every matching title (not
     * just the current page).
     *
     * Archived titles never count, even when a librarian's list includes them,
     * and neither do archived copies: the denominator is copies still in the
     * collection under a title still in the catalog.
     */
    public function activeSummary(array $filters = []): array
    {
        $bookIds = $this->filteredQuery($filters)->notArchived()->select('book_id');

        // Every active title, ignoring the catalog search. The two operational
        // counts below are desk totals — "how many copies are out right now" —
        // so they must not shrink because a librarian typed in the search box.
        $allActiveIds = Book::query()->notArchived()->select('book_id');

        return [
            'active_titles' => (clone $bookIds)->count(),
            'active_copies' => BookCopy::active()->whereIn('book_id', $bookIds)->count(),
            'available_copies' => self::whereGenerallyAvailable(BookCopy::query())->whereIn('book_id', $bookIds)->count(),
            'checked_out_copies' => BookCopy::active()->whereIn('book_id', $allActiveIds)
                ->where('availability_status', 'checked_out')->count(),
            'on_hold_copies' => BookCopy::active()->whereIn('book_id', $allActiveIds)
                ->where('availability_status', 'on_hold')->count(),
        ];
    }

    /** The catalog search and filters, with no archive or count handling. */
    protected function filteredQuery(array $filters)
    {
        $query = Book::query();

        // Single-box search across the fields a borrower would type, plus the
        // accession number a librarian reads off a shelf.
        if (!empty($filters['search'])) {
            $raw = trim($filters['search']);
            $term = '%'.$raw.'%';
            $normalizedIsbn = Book::normalizeIsbn($raw);

            $query->where(function ($q) use ($term, $raw, $normalizedIsbn) {
                $q->where('book_title', 'like', $term)
                    ->orWhere('author', 'like', $term)
                    ->orWhere('isbn', 'like', $term)
                    ->orWhere('publisher', 'like', $term)
                    ->orWhere('edition', 'like', $term)
                    ->orWhere('physical_location', 'like', $term);

                // "978-0-13-235088-4" should find a book stored as
                // "9780132350884" and the other way round.
                if ($normalizedIsbn) {
                    $q->orWhere('isbn_normalized', 'like', '%'.$normalizedIsbn.'%');
                }

                // An accession number identifies one physical copy, so it
                // resolves to the title that copy belongs to.
                $q->orWhereHas('copies', function ($c) use ($raw) {
                    $c->where('accession_number', 'like', '%'.$raw.'%');
                });
            });
        }

        if (!empty($filters['title'])) {
            $query->where('book_title', 'like', '%' . $filters['title'] . '%');
        }

        if (!empty($filters['author'])) {
            $query->where('author', 'like', '%' . $filters['author'] . '%');
        }

        if (!empty($filters['category'])) {
            // Match the category however it was cased or spaced in the
            // request, so a filter value never misses by punctuation alone.
            $query->whereRaw(
                'LOWER(TRIM(category)) = ?',
                [\App\Models\LibraryCategory::normalize($filters['category'])]
            );
        }

        if (!empty($filters['isbn'])) {
            $query->where('isbn', $filters['isbn']);
        }

        return $query;
    }

    /**
     * Get details of a single book with all its copies and live availability.
     */
    public function getBookDetails(int $bookId): ?Book
    {
        return Book::with(['copies'])
            ->withCount(self::generalAvailabilityCounts())
            ->find($bookId);
    }

    /**
     * Find an existing title that a new one would duplicate.
     *
     * Primary rule: the normalised ISBN. Formatting differences are not
     * different books.
     *
     * Secondary rule, only when there is no ISBN: an exact normalised match on
     * title + author + edition + publisher. Deliberately exact — a fuzzy match
     * that blocks a genuinely different edition is worse than letting a
     * librarian notice the duplicate themselves.
     *
     * @return Book|null the existing title, if this would duplicate it
     */
    public function findDuplicate(array $data, ?int $ignoreBookId = null): ?Book
    {
        $query = Book::query();

        if ($ignoreBookId) {
            $query->where('book_id', '!=', $ignoreBookId);
        }

        $normalizedIsbn = Book::normalizeIsbn($data['isbn'] ?? null);

        if ($normalizedIsbn) {
            return $query->where('isbn_normalized', $normalizedIsbn)->first();
        }

        // No ISBN: fall back to the bibliographic identity.
        $title = Book::normalizeText($data['book_title'] ?? '');
        $author = Book::normalizeText($data['author'] ?? '');

        if ($title === '' || $author === '') {
            return null;
        }

        $edition = Book::normalizeText($data['edition'] ?? '');
        $publisher = Book::normalizeText($data['publisher'] ?? '');

        return $query
            ->whereNull('isbn_normalized')
            ->get()
            ->first(function (Book $existing) use ($title, $author, $edition, $publisher) {
                return Book::normalizeText($existing->book_title) === $title
                    && Book::normalizeText($existing->author) === $author
                    && Book::normalizeText($existing->edition) === $edition
                    && Book::normalizeText($existing->publisher) === $publisher;
            });
    }

    /**
     * Admin: Create a new book title.
     */
    public function createBook(array $data): Book
    {
        return Book::create([
            'book_title' => $data['book_title'],
            'author' => $data['author'],
            'edition' => $data['edition'] ?? null,
            'publisher' => $data['publisher'] ?? null,
            'publication_year' => $data['publication_year'] ?? null,
            // Folded onto an existing category when one matches by name,
            // ignoring case and spacing, so the vocabulary does not fragment.
            'category' => app(CategoryService::class)->resolve($data['category'], $data['actor_id'] ?? null),
            'isbn' => $data['isbn'],
            'physical_location' => $data['physical_location'],
            'total_copies' => 0, // Starts at 0, updated when copies are added
        ]);
    }

    /**
     * Admin: Update an existing book title.
     */
    public function updateBook(int $bookId, array $data): Book
    {
        $book = Book::findOrFail($bookId);
        
        $book->update([
            'book_title' => $data['book_title'] ?? $book->book_title,
            'author' => $data['author'] ?? $book->author,
            'edition' => array_key_exists('edition', $data) ? $data['edition'] : $book->edition,
            'publisher' => array_key_exists('publisher', $data) ? $data['publisher'] : $book->publisher,
            'publication_year' => array_key_exists('publication_year', $data) ? $data['publication_year'] : $book->publication_year,
            'category' => array_key_exists('category', $data)
                ? app(CategoryService::class)->resolve($data['category'], $data['actor_id'] ?? null)
                : $book->category,
            'isbn' => $data['isbn'] ?? $book->isbn,
            'physical_location' => $data['physical_location'] ?? $book->physical_location,
        ]);

        return $book;
    }

    /**
     * Admin: Add physical copies to a book.
     */
    public function addCopies(int $bookId, int $quantity, string $condition = 'new', ?int $actorId = null): array
    {
        $book = Book::findOrFail($bookId);

        if ($book->isArchived()) {
            throw new \Exception('This title is archived. Restore it before adding copies.');
        }

        // One transaction so the reserved accession numbers and the copies that
        // use them either all land or none do — a crash halfway through must
        // not burn numbers or leave copies unlabelled.
        return DB::transaction(function () use ($book, $quantity, $condition, $actorId) {
            $numbers = app(AccessionNumberService::class)->reserve($quantity);
            $createdCopies = [];

            foreach ($numbers as $accession) {
                $copy = new BookCopy([
                    'book_id' => $book->book_id,
                    'condition' => $condition,
                    'availability_status' => 'available',
                ]);

                // Not fillable: assigned here, immutable afterwards.
                $copy->accession_number = $accession;
                $copy->save();

                $createdCopies[] = $copy;
            }

            $book->increment('total_copies', $quantity);

            return $createdCopies;
        });
    }

    /**
     * Admin: Update the condition or status of a specific copy.
     */
    public function updateCopyStatus(int $copyId, array $data, ?int $actorId = null): BookCopy
    {
        return DB::transaction(function () use ($copyId, $data) {
            // Locked, so a checkout or hold landing at the same moment cannot
            // slip between the checks below and the write.
            $copy = BookCopy::where('copy_id', $copyId)->lockForUpdate()->firstOrFail();

            $requestedStatus = $data['availability_status'] ?? null;
            $newCondition = $data['condition'] ?? $copy->condition;

            // checked_out and on_hold belong to circulation and the hold queue.
            // The controller already rejects them; this is the last line.
            if ($requestedStatus !== null && ! in_array($requestedStatus, BookCopy::MANUAL_STATUSES, true)) {
                throw new \Exception(
                    "A copy cannot be set to \"{$requestedStatus}\" by hand. That status is managed by checkout and the hold queue."
                );
            }

            // D-1: a damaged book is not borrowable. The condition drives the
            // availability; the reverse is deliberately NOT automatic — moving
            // condition away from damaged leaves availability where it is, so
            // a repaired copy only returns to circulation when a librarian
            // explicitly makes it available.
            $newStatus = $newCondition === 'damaged'
                ? 'damaged'
                : ($requestedStatus ?? $copy->availability_status);

            // While the copy is on loan, or set aside for someone to collect,
            // its availability belongs to that circulation action. Refuse any
            // change to it rather than invent a workflow for it.
            if ($newStatus !== $copy->availability_status) {
                $this->assertNotInCirculation($copy);
            }

            $copy->update([
                'condition' => $newCondition,
                'availability_status' => $newStatus,
            ]);

            return $copy;
        });
    }

    /**
     * Refuse when a live loan or a copy-owning hold would be contradicted.
     *
     * @throws \Exception with a sentence the librarian can act on
     */
    protected function assertNotInCirculation(BookCopy $copy): void
    {
        $onLoan = Transaction::where('copy_id', $copy->copy_id)
            ->where('status', 'active')
            ->exists();

        if ($onLoan) {
            throw new \Exception(
                'This copy is on loan. Check it in first, then update its status.'
            );
        }

        $heldFor = Hold::where('copy_id', $copy->copy_id)
            ->whereIn('status', ['pending_approval', 'fulfilled'])
            ->exists();

        if ($heldFor) {
            throw new \Exception(
                'This copy is set aside for a borrower. Release or check out that hold first, then update its status.'
            );
        }
    }
}
