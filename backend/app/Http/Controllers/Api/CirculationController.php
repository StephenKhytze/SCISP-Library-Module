<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CirculationService;
use Exception;
use Illuminate\Http\Request;

class CirculationController extends Controller
{
    protected $circulationService;

    public function __construct(CirculationService $circulationService)
    {
        $this->circulationService = $circulationService;
    }

    /**
     * Checkout a book copy to a user.
     */
    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,user_id',
            'copy_id' => 'required|exists:book_copies,copy_id',
        ]);

        $role = $request->attributes->get('role', 'student');

        try {
            $transaction = $this->circulationService->checkout(
                $validated['user_id'],
                $validated['copy_id'],
                $role
            );

            return response()->json([
                'message' => 'Checkout successful.',
                'transaction' => $transaction
            ], 201);
            
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Checkout failed.',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Check-in a returned book copy.
     */
    public function checkin(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => 'required|exists:transactions,transaction_id',
        ]);

        try {
            $transaction = $this->circulationService->checkin($validated['transaction_id']);

            return response()->json([
                'message' => 'Check-in successful.',
                'transaction' => $transaction
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Check-in failed.',
                'error' => $e->getMessage()
            ], 422);
        }
    }
}
