<?php

namespace App\Http\Controllers\Api\Library;

use App\Http\Controllers\Controller;
use App\Models\RenewalRequest;
use App\Services\RenewalService;
use Exception;
use Illuminate\Http\Request;

/**
 * Borrowers ask to extend a loan; Admin and Super Admin decide.
 * Nothing here lets a borrower move their own due date.
 */
class RenewalController extends Controller
{
    protected RenewalService $renewals;

    public function __construct(RenewalService $renewals)
    {
        $this->renewals = $renewals;
    }

    /**
     * Borrower: request a renewal of one of their own active loans.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => 'required|exists:transactions,transaction_id',
        ]);

        try {
            $renewal = $this->renewals->request(
                (int) $validated['transaction_id'],
                (int) $request->attributes->get('user_id')
            );

            return response()->json([
                'message' => 'Renewal requested. A librarian will review it.',
                'renewal_request' => $renewal,
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return response()->json([
                'message' => 'Renewal request failed.',
                'error' => 'Something went wrong on our side. Please try again.',
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Renewal request failed.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Borrower: their own renewal requests, newest first.
     */
    public function myRequests(Request $request)
    {
        $renewals = RenewalRequest::with(['transaction.bookCopy.book'])
            ->where('user_id', $request->attributes->get('user_id'))
            ->orderByDesc('renewal_request_id')
            ->get();

        return response()->json($renewals);
    }

    /**
     * Librarian: the queue of requests awaiting a decision.
     *
     * Pass ?status=all to see decided ones too.
     */
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');

        $query = RenewalRequest::with(['user:user_id,username,role', 'transaction.bookCopy.book'])
            ->orderBy('renewal_request_id');

        if (in_array($status, ['pending', 'approved', 'denied'], true)) {
            $query->where('status', $status);
        }

        return response()->json($query->get());
    }

    /**
     * Librarian: approve, which is the only thing that extends a due date.
     */
    public function approve(Request $request, int $id)
    {
        try {
            $renewal = $this->renewals->approve(
                $id,
                (int) $request->attributes->get('user_id')
            );

            return response()->json([
                'message' => 'Renewal approved. The due date has been extended.',
                'renewal_request' => $renewal,
            ], 200);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return response()->json([
                'message' => 'Could not approve this renewal.',
                'error' => 'Something went wrong on our side. Please try again.',
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Could not approve this renewal.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Librarian: deny, leaving the due date untouched.
     */
    public function deny(Request $request, int $id)
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:255',
        ]);

        try {
            $renewal = $this->renewals->deny(
                $id,
                (int) $request->attributes->get('user_id'),
                $validated['note'] ?? null
            );

            return response()->json([
                'message' => 'Renewal denied. The due date is unchanged.',
                'renewal_request' => $renewal,
            ], 200);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return response()->json([
                'message' => 'Could not deny this renewal.',
                'error' => 'Something went wrong on our side. Please try again.',
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Could not deny this renewal.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
