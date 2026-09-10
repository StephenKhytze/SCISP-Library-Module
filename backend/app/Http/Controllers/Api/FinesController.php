<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FinesCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fines are a single authoritative balance on users.total_fines.
 *
 * The Library module records settlements but does NOT process money: there is
 * no payment gateway, no ledger and no accounting history. "Paid" means the
 * borrower settled externally; "Waived" means a librarian forgave the amount.
 * Both simply reduce the balance.
 */
class FinesController extends Controller
{
    protected $finesCalculator;

    public function __construct(FinesCalculator $finesCalculator)
    {
        $this->finesCalculator = $finesCalculator;
    }

    /**
     * Admin: everyone who currently owes something.
     */
    public function index()
    {
        $debtors = User::where('total_fines', '>', 0)
            ->orderByDesc('total_fines')
            ->get(['user_id', 'username', 'role', 'total_fines']);

        return response()->json([
            'daily_rate' => $this->finesCalculator->dailyRate(),
            'total_outstanding' => round((float) $debtors->sum('total_fines'), 2),
            'debtors' => $debtors,
        ]);
    }

    /**
     * The signed-in user's own balance.
     */
    public function myFines(Request $request)
    {
        $user = User::find($request->attributes->get('user_id'));

        return response()->json([
            'user_id' => $user?->user_id,
            'total_fines' => round((float) ($user->total_fines ?? 0), 2),
            'daily_rate' => $this->finesCalculator->dailyRate(),
        ]);
    }

    /**
     * Admin: record a settlement against one borrower's balance.
     *
     * Partial settlement is allowed. The balance can never go below zero.
     */
    public function settle(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,user_id',
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|in:paid,waived',
        ]);

        $result = DB::transaction(function () use ($validated) {
            $user = User::where('user_id', $validated['user_id'])->lockForUpdate()->first();

            $before = round((float) $user->total_fines, 2);

            if ($before <= 0) {
                return ['error' => 'This borrower has no outstanding balance.'];
            }

            // Never settle more than is owed, and never go negative.
            $applied = min(round((float) $validated['amount'], 2), $before);
            $after = round($before - $applied, 2);

            $user->total_fines = $after;
            $user->save();

            return [
                'user_id' => $user->user_id,
                'username' => $user->username,
                'type' => $validated['type'],
                'requested_amount' => round((float) $validated['amount'], 2),
                'applied_amount' => $applied,
                'previous_balance' => $before,
                'new_balance' => $after,
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'message' => 'Settlement failed.',
                'error' => $result['error'],
            ], 422);
        }

        $verb = $result['type'] === 'paid' ? 'Payment recorded' : 'Fine waived';

        return response()->json([
            'message' => "{$verb}: PHP ".number_format($result['applied_amount'], 2)
                .'. Remaining balance: PHP '.number_format($result['new_balance'], 2).'.',
            'settlement' => $result,
        ]);
    }
}
