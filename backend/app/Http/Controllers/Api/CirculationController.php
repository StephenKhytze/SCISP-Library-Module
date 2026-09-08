<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CirculationService;
use Exception;
use App\Models\Transaction;
use App\Models\Hold;
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

    /**
     * Renew a borrowed book copy.
     */
    public function renew(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => 'required|exists:transactions,transaction_id',
        ]);

        $userId = $request->attributes->get('user_id');
        $role = $request->attributes->get('role', 'student');

        try {
            $transaction = $this->circulationService->renew(
                $validated['transaction_id'],
                $userId,
                $role
            );

            return response()->json([
                'message' => 'Renewal successful.',
                'transaction' => $transaction
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Renewal failed.',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Get active loans for the authenticated user.
     */
    public function myLoans(Request $request)
    {
        $userId = $request->attributes->get('user_id');
        
        $loans = Transaction::with(['bookCopy.book'])
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->get();
            
        return response()->json($loans);
    }

    /**
     * Get all active loans (Admin only).
     */
    public function index(Request $request)
    {
        $loans = Transaction::with(['bookCopy.book', 'user'])
            ->where('status', 'active')
            ->get();
            
        return response()->json($loans);
    }

    public function activeHolds(Request $request)
    {
        $holds = Hold::with(['book', 'user'])
            ->whereIn('status', ['pending', 'pending_approval', 'fulfilled'])
            ->orderBy('queue_position', 'asc')
            ->get();
            
        return response()->json($holds);
    }
}
