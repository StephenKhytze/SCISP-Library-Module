<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Services\BookArchiveService;
use App\Services\BookCoverService;
use App\Services\InventoryService;
use Exception;
use Illuminate\Http\Request;

class BookController extends Controller
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filters = $request->only(['search', 'title', 'author', 'category', 'isbn', 'per_page']);

        // Archived titles stay hidden from borrowers. Only a librarian may ask
        // for them, and only by asking — the default is always the live
        // catalog, so an ordinary request cannot surface a withdrawn title.
        $role = strtolower((string) $request->attributes->get('role', ''));
        $isLibrarian = str_contains($role, 'admin');

        if ($isLibrarian && $request->boolean('include_archived')) {
            $filters['include_archived'] = true;
        }

        return response()->json($this->inventoryService->searchBooks($filters));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'book_title' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'edition' => 'nullable|string|max:100',
            'publisher' => 'nullable|string|max:255',
            'publication_year' => 'nullable|integer|min:1450|max:'.(date('Y') + 1),
            'category' => 'required|string|max:100',
            // Uniqueness is judged on the NORMALISED value below, not on the
            // formatting the librarian happened to type, so no unique rule here.
            'isbn' => 'nullable|string|max:32',
            'physical_location' => 'required|string|max:255',
            'copies' => 'nullable|integer|min:1'
        ]);

        foreach (['book_title', 'author', 'edition', 'publisher', 'isbn'] as $field) {
            if (! empty($validated[$field])) {
                $validated[$field] = trim($validated[$field]);
            }
        }

        // "978-0-13-235088-4" and "9780132350884" are the same book, and a
        // second row for it would split its copies across two titles.
        $existing = $this->inventoryService->findDuplicate($validated);

        if ($existing) {
            return response()->json([
                'message' => 'This book already exists in the catalog. Add another physical copy to the existing title instead.',
                'duplicate' => true,
                // Enough for the frontend to offer "Add Copy Instead" without
                // a second lookup.
                'existing_book' => [
                    'book_id' => $existing->book_id,
                    'book_title' => $existing->book_title,
                    'author' => $existing->author,
                    'edition' => $existing->edition,
                    'publisher' => $existing->publisher,
                    'publication_year' => $existing->publication_year,
                    'isbn' => $existing->isbn,
                    'physical_location' => $existing->physical_location,
                    'total_copies' => $existing->total_copies,
                    'is_archived' => $existing->isArchived(),
                ],
            ], 409);
        }

        $validated['actor_id'] = (int) $request->attributes->get('user_id');
        $book = $this->inventoryService->createBook($validated);

        if (!empty($validated['copies']) && $validated['copies'] > 0) {
            $this->inventoryService->addCopies(
                $book->book_id,
                $validated['copies'],
                'new',
                (int) $request->attributes->get('user_id')
            );
            // Refresh book to get updated total_copies
            $book->refresh();
        }

        return response()->json($book, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id)
    {
        $book = $this->inventoryService->getBookDetails($id);
        
        if (!$book) {
            return response()->json(['message' => 'Book not found'], 404);
        }

        return response()->json($book);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'book_title' => 'sometimes|string|max:255',
            'author' => 'sometimes|string|max:255',
            'edition' => 'sometimes|nullable|string|max:100',
            'publisher' => 'sometimes|nullable|string|max:255',
            'publication_year' => 'sometimes|nullable|integer|min:1450|max:'.(date('Y') + 1),
            'category' => 'sometimes|string|max:100',
            'isbn' => 'sometimes|nullable|string|max:32',
            'physical_location' => 'sometimes|string|max:255',
        ]);

        $book = Book::findOrFail($id);

        // Editing a title into an existing one is the same mistake as creating
        // it twice.
        $candidate = array_merge([
            'book_title' => $book->book_title,
            'author' => $book->author,
            'edition' => $book->edition,
            'publisher' => $book->publisher,
            'isbn' => $book->isbn,
        ], $validated);

        $existing = $this->inventoryService->findDuplicate($candidate, $id);

        if ($existing) {
            return response()->json([
                'message' => 'Another title in the catalog already matches these details.',
                'duplicate' => true,
                'existing_book' => [
                    'book_id' => $existing->book_id,
                    'book_title' => $existing->book_title,
                    'isbn' => $existing->isbn,
                ],
            ], 409);
        }

        $validated['actor_id'] = (int) $request->attributes->get('user_id');
        $book = $this->inventoryService->updateBook($id, $validated);

        return response()->json($book);
    }

    /**
     * Replace a title's cover image. Admin / Super Admin only.
     */
    public function uploadCover(Request $request, int $id, BookCoverService $covers)
    {
        $request->validate([
            // Both checked again inside the service, which is the last step
            // before the filesystem.
            'cover' => 'required|file|mimetypes:image/jpeg,image/png,image/webp|max:5120',
        ]);

        $book = Book::findOrFail($id);

        try {
            $covers->store($book, $request->file('cover'));
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => 'Could not save that cover.',
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Cover updated.',
            'book' => $book->fresh(),
        ]);
    }

    /** Remove a title's cover, falling back to the placeholder. */
    public function removeCover(int $id, BookCoverService $covers)
    {
        $book = Book::findOrFail($id);
        $covers->remove($book);

        return response()->json([
            'message' => 'Cover removed.',
            'book' => $book->fresh(),
        ]);
    }

    /**
     * Archive a title instead of deleting it, so its loan and fine history
     * stays readable.
     */
    public function archive(Request $request, int $id, BookArchiveService $archives)
    {
        $validated = $request->validate(['reason' => 'nullable|string|max:255']);

        try {
            $book = $archives->archive(
                $id,
                (int) $request->attributes->get('user_id'),
                $validated['reason'] ?? null
            );

            return response()->json([
                'message' => 'Title archived. Its history remains available.',
                'book' => $book,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Could not archive this title.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function restore(int $id, BookArchiveService $archives)
    {
        try {
            return response()->json([
                'message' => 'Title restored to the catalog.',
                'book' => $archives->restore($id),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Could not restore this title.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
