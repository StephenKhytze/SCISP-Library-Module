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

        if (!empty($filters['title'])) {
            $query->where('book_title', 'like', '%' . $filters['title'] . '%');
        }

        if (!empty($filters['author'])) {
            $query->where('author', 'like', '%' . $filters['author'] . '%');
        }

        if (!empty($filters['category'])) {
            $query->where('category', 'like', '%' . $filters['category'] . '%');
        }

        if (!empty($filters['isbn'])) {
            $query->where('isbn', $filters['isbn']);
        }

        // Compute live availability using subquery
        $query->withCount([
            'copies as available_copies_count' => function ($q) {
                $q->where('availability_status', 'available');
            }
        ]);

        return $query->paginate(15);
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
        
        $copy->update([
            'condition' => $data['condition'] ?? $copy->condition,
            'availability_status' => $data['availability_status'] ?? $copy->availability_status,
        ]);

        return $copy;
    }
}
