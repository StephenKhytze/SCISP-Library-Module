<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookCopy;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryService
{
    /**
     * Search and filter books with live availability calculation.
     */
    public function searchBooks(array $filters = []): LengthAwarePaginator
    {
        $query = Book::query();

        // Single-box search across the fields a borrower would type.
        // Runs server-side so results are not limited to the current page.
        if (!empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';

            $query->where(function ($q) use ($term) {
                $q->where('book_title', 'like', $term)
                    ->orWhere('author', 'like', $term)
                    ->orWhere('isbn', 'like', $term)
                    ->orWhere('physical_location', 'like', $term);
            });
        }

        if (!empty($filters['title'])) {
            $query->where('book_title', 'like', '%' . $filters['title'] . '%');
        }

        if (!empty($filters['author'])) {
            $query->where('author', 'like', '%' . $filters['author'] . '%');
        }

        if (!empty($filters['category'])) {
            // Exact match so a chip selects precisely that shelf category.
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['isbn'])) {
            $query->where('isbn', $filters['isbn']);
        }

        // Compute live availability using subquery and eager-load copies
        $query->with(['copies'])->withCount([
            'copies as available_copies_count' => function ($q) {
                $q->where('availability_status', 'available');
            }
        ]);

        $perPage = (int) ($filters['per_page'] ?? 15);
        $perPage = max(1, min($perPage, 100));

        return $query->orderBy('book_title')->paginate($perPage);
    }

    /** Distinct categories actually present in the catalog, for filter chips. */
    public function categories(): array
    {
        return Book::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();
    }

    /**
     * Get details of a single book with all its copies and live availability.
     */
    public function getBookDetails(int $bookId): ?Book
    {
        return Book::with(['copies'])->withCount([
            'copies as available_copies_count' => function ($q) {
                $q->where('availability_status', 'available');
            }
        ])->find($bookId);
    }

    /**
     * Admin: Create a new book title.
     */
    public function createBook(array $data): Book
    {
        return Book::create([
            'book_title' => $data['book_title'],
            'author' => $data['author'],
            'category' => $data['category'],
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
            'category' => $data['category'] ?? $book->category,
            'isbn' => $data['isbn'] ?? $book->isbn,
            'physical_location' => $data['physical_location'] ?? $book->physical_location,
        ]);

        return $book;
    }

    /**
     * Admin: Add physical copies to a book.
     */
    public function addCopies(int $bookId, int $quantity, string $condition = 'new'): array
    {
        $book = Book::findOrFail($bookId);
        $createdCopies = [];

        for ($i = 0; $i < $quantity; $i++) {
            $createdCopies[] = BookCopy::create([
                'book_id' => $book->book_id,
                'condition' => $condition,
                'availability_status' => 'available',
            ]);
        }

        // Update the cached total_copies on the book
        $book->increment('total_copies', $quantity);

        return $createdCopies;
    }

    /**
     * Admin: Update the condition or status of a specific copy.
     */
    public function updateCopyStatus(int $copyId, array $data): BookCopy
    {
        $copy = BookCopy::findOrFail($copyId);

        $newStatus = $data['availability_status'] ?? $copy->availability_status;

        // A copy that is still on loan must not be hand-flipped back to available;
        // it returns to circulation through check-in, which also settles any fine.
        if ($newStatus === 'available' && $copy->availability_status !== 'available') {
            $hasActiveLoan = \App\Models\Transaction::where('copy_id', $copyId)
                ->where('status', 'active')
                ->exists();

            if ($hasActiveLoan) {
                throw new \Exception('This copy has an active loan. Check it in instead of marking it available.');
            }
        }

        $copy->update([
            'condition' => $data['condition'] ?? $copy->condition,
            'availability_status' => $newStatus,
        ]);

        return $copy;
    }
}
