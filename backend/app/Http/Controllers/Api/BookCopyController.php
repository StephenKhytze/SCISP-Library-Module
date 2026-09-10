<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class BookCopyController extends Controller
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    /**
     * Store newly created copies for a book.
     */
    public function store(Request $request, int $bookId)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:100',
            'condition' => 'sometimes|in:new,good,fair,poor',
        ]);

        $condition = $validated['condition'] ?? 'new';
        $copies = $this->inventoryService->addCopies($bookId, $validated['quantity'], $condition);

        return response()->json([
            'message' => 'Copies added successfully',
            'added_copies' => $copies
        ], 201);
    }

    /**
     * Update the specified copy.
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'condition' => 'sometimes|in:new,good,fair,poor',
            'availability_status' => 'sometimes|in:available,checked_out,on_hold,lost,damaged',
        ]);

        try {
            $copy = $this->inventoryService->updateCopyStatus($id, $validated);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Could not update copy.',
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json($copy);
    }
}
