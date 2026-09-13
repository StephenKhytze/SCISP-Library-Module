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
     * Get active loans for the authenticated user.
     */
    public function myLoans(Request $request)
    {
        $userId = $request->attributes->get('user_id');

        $loans = Transaction::with(['bookCopy.book', 'latestRenewalRequest'])
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->orderByDesc('transaction_id')
            ->get();

        return response()->json($loans);
    }

    /**
     * The signed-in user's own transaction history (active + returned).
     * Always scoped to the caller — never another user's records.
     */
    public function myHistory(Request $request)
    {
        $userId = $request->attributes->get('user_id');

        $history = Transaction::with(['bookCopy.book', 'latestRenewalRequest'])
            ->where('user_id', $userId)
            ->orderByDesc('transaction_id')
            ->get();

        return response()->json($history);
    }

    /**
     * Get loans (Admin only).
     *
     * Defaults to active loans, which is what the circulation desk shows.
     * Pass ?status=all for full history, or ?status=returned.
     */
    public function index(Request $request)
    {
        $status = $request->query('status', 'active');

        $query = Transaction::with(['bookCopy.book', 'user'])
            ->orderByDesc('transaction_id');

        if (in_array($status, ['active', 'returned'], true)) {
            $query->where('status', $status);
        }

        return response()->json($query->get());
    }

    /**
     * Lightweight self summary: balance, active loans and the borrowing limit.
     * Backs the borrower-facing counters so the UI never hardcodes them.
     */
    public function mySummary(Request $request)
    {
        $userId = $request->attributes->get('user_id');
        $user = \App\Models\User::find($userId);

        $dbRole = $this->circulationService->normalizeRole($user->role ?? 'student');
        $isSuperAdmin = $user && $user->isSuperAdmin();
        $canBorrow = $user ? $user->canBorrow() : false;

        // A management-only account has no borrowing limit because it has no
        // borrowing at all; null here would read as "unlimited".
        $limit = $canBorrow ? $this->circulationService->getBorrowLimitForRole($dbRole) : 0;

        $activeLoans = $canBorrow
            ? Transaction::where('user_id', $userId)->where('status', 'active')->count()
            : 0;

        $overdueLoans = $canBorrow
            ? Transaction::where('user_id', $userId)
                ->where('status', 'active')
                ->whereNotNull('due_date')
                ->where('due_date', '<', now())
                ->count()
            : 0;

        $payload = [
            'user_id' => $user?->user_id,
            'username' => $user?->username,
            'role' => $dbRole,
            // Admin and Super Admin share the database role, so this flag is
            // the only thing that separates them for the client.
            'is_super_admin' => $isSuperAdmin,
            'can_borrow' => $canBorrow,
            'total_fines' => round((float) ($user->total_fines ?? 0), 2),
            'active_loans' => $activeLoans,
            'overdue_loans' => $overdueLoans,
            'borrow_limit' => $limit,
            'holds' => $canBorrow
                ? \App\Models\Hold::where('user_id', $userId)
                    ->whereIn('status', ['pending', 'pending_approval', 'fulfilled'])
                    ->count()
                : 0,
            'pending_renewals' => $canBorrow
                ? \App\Models\RenewalRequest::where('user_id', $userId)->where('status', 'pending')->count()
                : 0,
        ];

        // Desk counters for the navigation badges. A handful of cheap COUNTs
        // here saves the client from loading four full admin datasets just to
        // label a tab.
        if ($dbRole === 'administrator') {
            $payload['librarian'] = [
                'active_loans' => Transaction::where('status', 'active')->count(),
                'pending_holds' => \App\Models\Hold::whereIn('status', ['pending', 'pending_approval', 'fulfilled'])->count(),
                'pending_renewals' => \App\Models\RenewalRequest::where('status', 'pending')->count(),
                'debtors' => \App\Models\User::where('total_fines', '>', 0)->count(),
                'pending_reserves' => \App\Models\CourseReserve::where('status', 'pending')->count(),
                'total_reserves' => \App\Models\CourseReserve::count(),
            ];
        }

        return response()->json($payload);
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
