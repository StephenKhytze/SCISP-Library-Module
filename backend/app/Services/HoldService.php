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
            // Check if user already has a pending or fulfilled hold for this book
            $existingHold = Hold::where('book_id', $bookId)
                ->where('user_id', $userId)
                ->whereIn('status', ['pending', 'pending_approval', 'fulfilled'])
                ->first();

            if ($existingHold) {
                throw new Exception("You already have an active hold for this book.");
            }

            // Find an available copy
            $availableCopy = BookCopy::where('book_id', $bookId)
                ->where('availability_status', 'available')
                ->lockForUpdate()
                ->first();

            if ($availableCopy) {
                // Wait for admin approval before fulfilling (Getting Approval)
                $hold = Hold::create([
                    'user_id' => $userId,
                    'book_id' => $bookId,
                    'request_date' => now(),
                    'status' => 'pending_approval',
                    'queue_position' => 0,
                    'copy_id' => $availableCopy->copy_id
                ]);

                $availableCopy->update(['availability_status' => 'on_hold']);
                return $hold;
            } else {
                // Out of stock -> Join waitlist
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
            }
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
            // Fulfill the hold and assign the copy
            $nextHold->update([
                'status' => 'pending_approval',
                'queue_position' => 0,
                'copy_id' => $copyId
            ]);

            // Mark the copy as on_hold specifically for them
            $copy = BookCopy::where('copy_id', $copyId)->lockForUpdate()->first();
            $copy->update(['availability_status' => 'on_hold']);

            // Note: In a real system, you'd trigger a notification/email to the user here.

            $this->resequenceQueue($bookId);

            return true;
        }

        return false;
    }

    /**
     * Renumber the remaining waitlist to 1..N.
     *
     * Promotions and cancellations otherwise leave gaps, so a queue could read
     * "#2, #3" with no #1. Order was always correct; the displayed position was not.
     */
    protected function resequenceQueue(int $bookId): void
    {
        $pending = Hold::where('book_id', $bookId)
            ->where('status', 'pending')
            ->orderBy('queue_position')
            ->orderBy('hold_id')
            ->get();

        $position = 1;

        foreach ($pending as $hold) {
            if ((int) $hold->queue_position !== $position) {
                $hold->update(['queue_position' => $position]);
            }

            $position++;
        }
    }

    /**
     * Cancel a pending hold.
     */
    public function cancelHold(int $holdId, int $userId, bool $isAdmin = false): Hold
    {
        return DB::transaction(function () use ($holdId, $userId, $isAdmin) {
            $hold = Hold::findOrFail($holdId);

            if (!$isAdmin && $hold->user_id !== $userId) {
                throw new Exception("You are not authorized to cancel this hold.");
            }

            if (!in_array($hold->status, ['pending', 'pending_approval', 'fulfilled'])) {
                throw new Exception("Only pending, pending_approval, or fulfilled holds can be cancelled.");
            }

            $wasFulfilled = in_array($hold->status, ['fulfilled', 'pending_approval']);
            $copyId = $hold->copy_id;
            $bookId = $hold->book_id;

            $hold->update(['status' => 'cancelled']);

            if ($wasFulfilled && $copyId) {
                // The copy is now freed up. See if anyone else is waiting for it.
                $wasHoldFulfilled = $this->advanceQueue($bookId, $copyId);

                if (!$wasHoldFulfilled) {
                    // No one else is waiting, make it available again. This is also how a
                    // librarian frees a copy stuck behind an abandoned hold.
                    $copy = BookCopy::find($copyId);

                    if ($copy && $copy->availability_status === 'on_hold') {
                        $copy->update(['availability_status' => 'available']);
                    }
                }
            }

            // Close any gap this cancellation left in the waitlist.
            $this->resequenceQueue($bookId);

            return $hold;
        });
    }
}
