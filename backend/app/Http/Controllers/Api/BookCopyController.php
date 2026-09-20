<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookCopy;
use App\Services\CopyArchiveService;
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

        try {
            $copies = $this->inventoryService->addCopies(
                $bookId,
                $validated['quantity'],
                $condition,
                (int) $request->attributes->get('user_id')
            );
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Could not add copies.',
                'error' => $e->getMessage(),
            ], 422);
        }

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
            'condition' => 'sometimes|in:'.implode(',', BookCopy::CONDITIONS),
            // checked_out and on_hold are owned by circulation and the hold
            // queue; a librarian may only set these three by hand.
            'availability_status' => 'sometimes|in:'.implode(',', BookCopy::MANUAL_STATUSES),
            'condition_note' => 'nullable|string|max:255',
        ], [
            'availability_status.in' => 'Availability can only be set to available, lost or damaged. Checked out and on hold are managed by checkout and the hold queue.',
            'condition.in' => 'Condition must be one of: '.implode(', ', BookCopy::CONDITIONS).'.',
        ]);

        try {
            $copy = $this->inventoryService->updateCopyStatus(
                $id,
                $validated,
                (int) $request->attributes->get('user_id')
            );
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Could not update copy.',
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json($copy);
    }

    /**
     * Archive one physical copy. Admin / Super Admin only (route group).
     *
     * The copy keeps its accession number, condition, availability and loan
     * history; it simply leaves circulation.
     */
    public function archive(Request $request, int $id, CopyArchiveService $archives)
    {
        $validated = $request->validate(['reason' => 'nullable|string|max:1000']);

        try {
            $copy = $archives->archive(
                $id,
                (int) $request->attributes->get('user_id'),
                $validated['reason'] ?? null
            );
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Could not archive this copy.',
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => "Copy {$copy->label} archived. Its history remains available.",
            'copy' => $copy,
        ]);
    }

    /** Return an archived copy to the collection, exactly as it was. */
    public function restore(int $id, CopyArchiveService $archives)
    {
        try {
            $copy = $archives->restore($id);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Could not restore this copy.',
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => "Copy {$copy->label} restored.",
            'copy' => $copy,
        ]);
    }
}
