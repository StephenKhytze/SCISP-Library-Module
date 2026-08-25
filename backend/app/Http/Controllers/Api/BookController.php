<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InventoryService;
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
        $filters = $request->only(['title', 'author', 'category', 'isbn']);
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
            'category' => 'required|string|max:100',
            'isbn' => 'required|string|unique:books,isbn|max:20',
            'physical_location' => 'required|string|max:255',
        ]);

        $book = $this->inventoryService->createBook($validated);

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
            'category' => 'sometimes|string|max:100',
            'isbn' => 'sometimes|string|max:20|unique:books,isbn,' . $id . ',book_id',
            'physical_location' => 'sometimes|string|max:255',
        ]);

        $book = $this->inventoryService->updateBook($id, $validated);

        return response()->json($book);
    }
}
