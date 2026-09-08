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
    protected function getDueDateForRole(string $role)
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

            $isReserved = false;
            if ($copy->reserve_id) {
                $reserve = \App\Models\CourseReserve::find($copy->reserve_id);
                if ($reserve && $reserve->status === 'approved') {
                    $isReserved = true;
                    $studentInClass = \App\Models\CourseSectionStudent::where('section_id', $reserve->section_id)
                                        ->where('student_id', $userId)
                                        ->exists();
                    if (!$studentInClass && !in_array($role, ['superadmin', 'admin', 'faculty'])) {
                        throw new Exception("This copy is strictly reserved for a course section. You are not assigned to it.");
                    }
                }
            }

            if ($copy->availability_status !== 'available') {
                if ($copy->availability_status === 'on_hold') {
                    $userHold = \App\Models\Hold::where('copy_id', $copyId)
                        ->where('user_id', $userId)
                        ->where('status', 'fulfilled')
                        ->first();
                    
                    if (!$userHold) {
                        throw new Exception("This copy is currently reserved for another user.");
                    }
                } else {
                    throw new Exception("This copy is currently not available for checkout.");
                }
            }

            $user = User::findOrFail($userId);

            // Update the copy status
            $copy->update(['availability_status' => 'checked_out']);

            $dueDate = $this->getDueDateForRole($user->role);
            if ($isReserved) {
                $dueDate = now()->addDays(14);
            }

            // Create the transaction
            $transaction = Transaction::create([
                'user_id' => $user->user_id,
                'copy_id' => $copy->copy_id,
                'date_borrowed' => now(),
                'due_date' => $dueDate,
                'status' => 'active',
            ]);

            // Clear any pending or fulfilled holds the user has for this book
            $existingHold = \App\Models\Hold::where('book_id', $copy->book_id)
                ->where('user_id', $user->user_id)
                ->whereIn('status', ['pending', 'fulfilled'])
                ->first();

            if ($existingHold) {
                if ($existingHold->status === 'fulfilled' && $existingHold->copy_id && $existingHold->copy_id !== $copy->copy_id) {
                    // Admin checked out a different physical copy. 
                    // Release the originally held copy to the waitlist.
                    $wasHoldFulfilled = $this->holdService->advanceQueue($existingHold->book_id, $existingHold->copy_id);
                    if (!$wasHoldFulfilled) {
                        $oldCopy = BookCopy::find($existingHold->copy_id);
                        if ($oldCopy) {
                            $oldCopy->update(['availability_status' => 'available']);
                        }
                    }
                }
                $existingHold->delete();
            }

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

            $transaction->load('bookCopy');
            $isReserved = $transaction->bookCopy && $transaction->bookCopy->reserve_id !== null;

            // Mark the copy as available again, OR fulfill a hold if someone is waiting
            $wasHoldFulfilled = false;
            
            if (!$isReserved) {
                $wasHoldFulfilled = $this->holdService->advanceQueue($transaction->bookCopy->book_id, $transaction->copy_id);
            }

            if (!$wasHoldFulfilled) {
                // Only make it available if no one was in the hold queue
                $copy = BookCopy::findOrFail($transaction->copy_id);
                $copy->update(['availability_status' => 'available']);
            }

            return $transaction;
        });
    }

    public function renew(int $transactionId, int $userId, string $role): Transaction
    {
        return DB::transaction(function () use ($transactionId, $userId, $role) {
            $transaction = Transaction::with(['user', 'bookCopy'])->where('transaction_id', $transactionId)->lockForUpdate()->first();

            if (!$transaction) {
                throw new Exception("Transaction not found.");
            }

            if ($transaction->status !== 'active') {
                throw new Exception("Transaction is already closed.");
            }

            $isReserved = $transaction->bookCopy && $transaction->bookCopy->reserve_id !== null;

            if ($isReserved && $role === 'faculty') {
                $reserve = \App\Models\CourseReserve::find($transaction->bookCopy->reserve_id);
                if ($reserve->user_id !== $userId) {
                    throw new Exception("You are not the teacher for this reserved book.");
                }
            } else if ($transaction->user_id !== $userId && !in_array($role, ['superadmin', 'admin'])) {
                throw new Exception("You are not authorized to renew this book.");
            }

            if (!$isReserved) {
                $hasHolds = \App\Models\Hold::where('book_id', $transaction->bookCopy->book_id)
                    ->whereIn('status', ['pending', 'pending_approval'])
                    ->exists();

                if ($hasHolds) {
                    throw new Exception("Cannot renew: This book has pending holds.");
                }
            }

            $dueDate = $this->getDueDateForRole($transaction->user->role ?? 'student');
            if ($isReserved) {
                $dueDate = now()->addDays(14);
            }

            $transaction->due_date = $dueDate;
            $transaction->save();

            return $transaction;
        });
    }
}
