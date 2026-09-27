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
        $userId = $request->attributes->get('user_id');

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
        $userId = $request->attributes->get('user_id');
        $role = strtolower($request->attributes->get('role', ''));
        $isAdmin = in_array($role, ['admin', 'super admin']);

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

    /**
     * Get the authenticated user's holds.
     */
    public function myHolds(Request $request)
    {
        $userId = $request->attributes->get('user_id');

        $holds = \App\Models\Hold::with(['book'])
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'pending_approval', 'fulfilled'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($holds);
    }

    /**
     * Accept a pending hold.
     */
    public function acceptHold(Request $request, int $id)
    {
        $role = strtolower($request->attributes->get('role', ''));
        $isAdmin = in_array($role, ['admin', 'super admin']);

        if (!$isAdmin) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        try {
            $hold = \Illuminate\Support\Facades\DB::transaction(function () use ($id) {
                $hold = \App\Models\Hold::findOrFail($id);

                if ($hold->status !== 'pending_approval') {
                    throw new Exception("Only holds waiting for approval can be accepted.");
                }

                $hold->update(['status' => 'fulfilled']);
                return $hold;
            });

            return response()->json([
                'message' => 'Hold accepted and is now Ready for Pickup.',
                'hold' => $hold
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to accept hold.',
                'error' => $e->getMessage()
            ], 422);
        }
    }
}
