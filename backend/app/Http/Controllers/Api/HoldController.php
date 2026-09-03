<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HoldService;
use Exception;
use Illuminate\Http\Request;

class HoldController extends Controller
{
    protected $holdService;

    public function __construct(HoldService $holdService)
    {
        $this->holdService = $holdService;
    }

    /**
     * Place a hold on a book.
     */
    public function store(Request $request, int $bookId)
    {
        $userId = \Illuminate\Support\Facades\Auth::id();

        try {
            $hold = $this->holdService->placeHold($userId, $bookId);

            return response()->json([
                'message' => 'Hold placed successfully.',
                'hold' => $hold
            ], 201);
            
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to place hold.',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Cancel a hold.
     */
    public function destroy(Request $request, int $id)
    {
        $userId = \Illuminate\Support\Facades\Auth::id();
        $role = $request->attributes->get('role');
        $isAdmin = in_array($role, ['administrator', 'superadmin']);

        try {
            $hold = $this->holdService->cancelHold($id, $userId, $isAdmin);

            return response()->json([
                'message' => 'Hold cancelled successfully.',
                'hold' => $hold
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to cancel hold.',
                'error' => $e->getMessage()
            ], 422);
        }
    }
}
