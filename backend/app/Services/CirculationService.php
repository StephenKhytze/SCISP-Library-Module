<?php

namespace App\Services;

use App\Models\BookCopy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Exception;

class CirculationService
{
    protected $finesCalculator;
    protected $holdService;

    public function __construct(FinesCalculator $finesCalculator, HoldService $holdService)
    {
        $this->finesCalculator = $finesCalculator;
        $this->holdService = $holdService;
    }

    /**
     * Get the due date based on the user's role.
     */
    protected function getDueDateForRole(string $role): Carbon
    {
        $days = match ($role) {
            'student' => 7,
            'faculty' => 14,
            'administrator', 'superadmin' => 30,
            default => 7,
        };

        return now()->addDays($days);
    }

    /**
     * Checkout a specific book copy to a user.
     * Uses pessimistic locking to prevent race conditions.
     *
     * @param int $userId
     * @param int $copyId
     * @param string $role
     * @return Transaction
     * @throws Exception
     */
    public function checkout(int $userId, int $copyId, string $role): Transaction
    {
        return DB::transaction(function () use ($userId, $copyId, $role) {
            // Lock the book copy row exclusively for update
            $copy = BookCopy::where('copy_id', $copyId)->lockForUpdate()->first();

            if (!$copy) {
                throw new Exception("Book copy not found.");
            }

            if ($copy->availability_status !== 'available') {
                throw new Exception("This copy is currently not available for checkout.");
            }

            $user = User::findOrFail($userId);

            // Update the copy status
            $copy->update(['availability_status' => 'checked_out']);

            // Create the transaction
            $transaction = Transaction::create([
                'user_id' => $user->user_id,
                'copy_id' => $copy->copy_id,
                'date_borrowed' => now(),
                'due_date' => $this->getDueDateForRole($role),
                'status' => 'active',
            ]);

            return $transaction;
        });
    }

    /**
     * Check-in a returned book copy and calculate any applicable fines.
     *
     * @param int $transactionId
     * @return Transaction
     * @throws Exception
     */
    public function checkin(int $transactionId): Transaction
    {
        return DB::transaction(function () use ($transactionId) {
            // Lock the transaction to prevent duplicate check-ins
            $transaction = Transaction::where('transaction_id', $transactionId)->lockForUpdate()->first();

            if (!$transaction) {
                throw new Exception("Transaction not found.");
            }

            if ($transaction->status !== 'active') {
                throw new Exception("Transaction is already closed.");
            }

            $returnDate = now();
            
            // Calculate fine if overdue
            $fine = $this->finesCalculator->calculateFine($transaction->due_date, $returnDate);

            if ($fine > 0) {
                // Lock the user to apply fine safely
                $user = User::where('user_id', $transaction->user_id)->lockForUpdate()->first();
                $user->increment('total_fines', $fine);
                $transaction->status = 'overdue'; // We can mark it overdue or just returned
            }

            $transaction->status = 'returned';
            $transaction->actual_return_date = $returnDate;
            $transaction->save();

            // Mark the copy as available again, OR fulfill a hold if someone is waiting
            $wasHoldFulfilled = $this->holdService->advanceQueue($transaction->bookCopy->book_id, $transaction->copy_id);

            if (!$wasHoldFulfilled) {
                // Only make it available if no one was in the hold queue
                $copy = BookCopy::findOrFail($transaction->copy_id);
                $copy->update(['availability_status' => 'available']);
            }

            return $transaction;
        });
    }
}
