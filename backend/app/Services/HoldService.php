<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Hold;
use Exception;
use Illuminate\Support\Facades\DB;

class HoldService
{
    /**
     * Place a hold on a book if no copies are available.
     */
    public function placeHold(int $userId, int $bookId): Hold
    {
        return DB::transaction(function () use ($userId, $bookId) {
            // Count available copies using a lock
            $availableCount = BookCopy::where('book_id', $bookId)
                ->where('availability_status', 'available')
                ->lockForUpdate()
                ->count();

            if ($availableCount > 0) {
                throw new Exception("Cannot place a hold. There are currently copies available for checkout.");
            }

            // Check if user already has a pending hold for this book
            $existingHold = Hold::where('book_id', $bookId)
                ->where('user_id', $userId)
                ->where('status', 'pending')
                ->first();

            if ($existingHold) {
                throw new Exception("You already have a pending hold for this book.");
            }

            // Determine queue position
            $maxPosition = Hold::where('book_id', $bookId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->max('queue_position');

            $nextPosition = $maxPosition ? $maxPosition + 1 : 1;

            $hold = Hold::create([
                'user_id' => $userId,
                'book_id' => $bookId,
                'request_date' => now(),
                'status' => 'pending',
                'queue_position' => $nextPosition,
            ]);

            return $hold;
        });
    }

    /**
     * Advance the hold queue when a book copy is returned.
     * This should be called inside a DB::transaction.
     * 
     * @return bool True if a hold was fulfilled, false otherwise.
     */
    public function advanceQueue(int $bookId, int $copyId): bool
    {
        $nextHold = Hold::where('book_id', $bookId)
            ->where('status', 'pending')
            ->orderBy('queue_position', 'asc')
            ->lockForUpdate()
            ->first();

        if ($nextHold) {
            // Fulfill the hold
            $nextHold->update(['status' => 'fulfilled']);

            // Mark the copy as on_hold specifically for them
            $copy = BookCopy::where('copy_id', $copyId)->lockForUpdate()->first();
            $copy->update(['availability_status' => 'on_hold']);

            // Note: In a real system, you'd trigger a notification/email to the user here.

            return true;
        }

        return false;
    }

    /**
     * Cancel a pending hold.
     */
    public function cancelHold(int $holdId, int $userId, bool $isAdmin = false): Hold
    {
        $hold = Hold::findOrFail($holdId);

        if (!$isAdmin && $hold->user_id !== $userId) {
            throw new Exception("You are not authorized to cancel this hold.");
        }

        if ($hold->status !== 'pending') {
            throw new Exception("Only pending holds can be cancelled.");
        }

        $hold->update(['status' => 'cancelled']);

        return $hold;
    }
}
