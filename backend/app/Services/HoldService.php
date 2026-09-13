<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\CourseReserve;
use App\Models\CourseSectionStudent;
use App\Models\Hold;
use App\Models\Transaction;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Holds come in two pools that must never mix:
 *
 *   - GENERAL      reserve_id IS NULL — the title's own waitlist, served only
 *                  by copies that are not allocated to any course reserve.
 *   - COURSE RESERVE  reserve_id = N — one queue per reserve, served only by
 *                  the copies allocated to that reserve.
 *
 * A general borrower must never be handed a section's reserved copy, and a
 * student waiting on a course reserve must never be handed general stock.
 */
class HoldService
{
    /**
     * Place a general-circulation hold on a book.
     *
     * Only copies with no reserve allocation are eligible.
     */
    public function placeHold(int $userId, int $bookId): Hold
    {
        return DB::transaction(function () use ($userId, $bookId) {
            $user = User::find($userId);

            if ($user && ! $user->canBorrow()) {
                throw new Exception('Super Admin accounts cannot borrow library materials.');
            }

            $book = Book::find($bookId);

            if ($book && $book->isArchived()) {
                throw new Exception('This title has been archived and is no longer available for borrowing.');
            }

            // One open general hold per title per borrower.
            $existingHold = Hold::where('book_id', $bookId)
                ->where('user_id', $userId)
                ->whereNull('reserve_id')
                ->whereIn('status', ['pending', 'pending_approval', 'fulfilled'])
                ->first();

            if ($existingHold) {
                throw new Exception("You already have an active hold for this book.");
            }

            // General stock only — a course-reserved copy is set aside for a
            // section and is not part of the pool this queue draws from.
            $availableCopy = BookCopy::where('book_id', $bookId)
                ->where('availability_status', 'available')
                ->whereNull('reserve_id')
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
                    'copy_id' => $availableCopy->copy_id,
                    'reserve_id' => null,
                ]);

                $availableCopy->update(['availability_status' => 'on_hold']);

                return $hold;
            }

            // Out of general stock -> join the title's waitlist.
            $maxPosition = Hold::where('book_id', $bookId)
                ->whereNull('reserve_id')
                ->where('status', 'pending')
                ->lockForUpdate()
                ->max('queue_position');

            return Hold::create([
                'user_id' => $userId,
                'book_id' => $bookId,
                'request_date' => now(),
                'status' => 'pending',
                'queue_position' => $maxPosition ? $maxPosition + 1 : 1,
                'reserve_id' => null,
            ]);
        });
    }

    /**
     * An enrolled student asks to borrow from a specific course reserve.
     *
     * Only that reserve's allocated copies are ever considered.
     */
    public function requestReserveCopy(int $userId, int $reserveId): Hold
    {
        return DB::transaction(function () use ($userId, $reserveId) {
            $reserve = CourseReserve::where('reserve_id', $reserveId)->first();

            if (! $reserve) {
                throw new Exception('That course reserve could not be found.');
            }

            if ($reserve->status !== 'approved') {
                throw new Exception('This course reserve is not available for borrowing.');
            }

            $book = Book::find($reserve->book_id);

            if ($book && $book->isArchived()) {
                throw new Exception('This title has been archived and is no longer available for borrowing.');
            }

            $user = User::find($userId);

            if (! $user || ! $user->canBorrow()) {
                throw new Exception('Super Admin accounts cannot borrow library materials.');
            }

            $enrolled = CourseSectionStudent::where('section_id', $reserve->section_id)
                ->where('student_id', $userId)
                ->exists();

            if (! $enrolled) {
                throw new Exception('You are not enrolled in this course section.');
            }

            $existing = Hold::where('reserve_id', $reserveId)
                ->where('user_id', $userId)
                ->whereIn('status', ['pending', 'pending_approval', 'fulfilled'])
                ->first();

            if ($existing) {
                throw new Exception('You already have an active request for this course reserve.');
            }

            // Already holding one of this reserve's copies on loan.
            $allocatedCopyIds = BookCopy::where('reserve_id', $reserveId)->pluck('copy_id');

            if ($allocatedCopyIds->isEmpty()) {
                throw new Exception('No physical copies have been allocated to this course reserve yet.');
            }

            $alreadyOnLoan = Transaction::whereIn('copy_id', $allocatedCopyIds)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->exists();

            if ($alreadyOnLoan) {
                throw new Exception('You already have a copy of this course reserve on loan.');
            }

            // Only this reserve's own copies, and only ones physically free.
            $freeCopy = BookCopy::where('reserve_id', $reserveId)
                ->where('availability_status', 'available')
                ->lockForUpdate()
                ->first();

            if ($freeCopy) {
                $hold = Hold::create([
                    'user_id' => $userId,
                    'book_id' => $reserve->book_id,
                    'reserve_id' => $reserveId,
                    'copy_id' => $freeCopy->copy_id,
                    'request_date' => now(),
                    // Ready for pickup, not "getting approval": the librarian
                    // already approved this reserve, so the only step left is
                    // the physical checkout at the desk.
                    'status' => 'fulfilled',
                    'queue_position' => 0,
                ]);

                $freeCopy->update(['availability_status' => 'on_hold']);

                return $hold;
            }

            // Every allocated copy is in use -> queue for THIS reserve.
            $maxPosition = Hold::where('reserve_id', $reserveId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->max('queue_position');

            return Hold::create([
                'user_id' => $userId,
                'book_id' => $reserve->book_id,
                'reserve_id' => $reserveId,
                'request_date' => now(),
                'status' => 'pending',
                'queue_position' => $maxPosition ? $maxPosition + 1 : 1,
            ]);
        });
    }

    /**
     * Advance the general waitlist when a general copy is returned.
     * This should be called inside a DB::transaction.
     *
     * @return bool True if a hold was fulfilled, false otherwise.
     */
    public function advanceQueue(int $bookId, int $copyId): bool
    {
        $nextHold = Hold::where('book_id', $bookId)
            ->whereNull('reserve_id')
            ->where('status', 'pending')
            ->orderBy('queue_position', 'asc')
            ->lockForUpdate()
            ->first();

        if (! $nextHold) {
            return false;
        }

        return $this->promote($nextHold, $copyId);
    }

    /**
     * Advance one course reserve's own queue when one of its copies is
     * returned. The title's general waitlist is deliberately not consulted.
     */
    public function advanceReserveQueue(int $reserveId, int $copyId): bool
    {
        $nextHold = Hold::where('reserve_id', $reserveId)
            ->where('status', 'pending')
            ->orderBy('queue_position', 'asc')
            ->lockForUpdate()
            ->first();

        if (! $nextHold) {
            return false;
        }

        // A reserve is already approved, so its queue promotes straight to
        // Ready for Pickup — the same state a direct reserve request produces.
        // General holds keep their existing "Getting Approval" step.
        return $this->promote($nextHold, $copyId, 'fulfilled');
    }

    /** Give a waiting borrower the copy that just came back. */
    protected function promote(Hold $hold, int $copyId, string $status = 'pending_approval'): bool
    {
        $hold->update([
            'status' => $status,
            'queue_position' => 0,
            'copy_id' => $copyId,
        ]);

        $copy = BookCopy::where('copy_id', $copyId)->lockForUpdate()->first();
        $copy->update(['availability_status' => 'on_hold']);

        // Note: In a real system, you'd trigger a notification/email here.

        $this->resequence($hold->book_id, $hold->reserve_id);

        return true;
    }

    /**
     * Renumber a waitlist to 1..N.
     *
     * Promotions and cancellations otherwise leave gaps, so a queue could read
     * "#2, #3" with no #1. Order was always correct; the displayed position was
     * not. Each pool is renumbered independently.
     */
    protected function resequence(int $bookId, ?int $reserveId = null): void
    {
        $query = Hold::where('status', 'pending');

        if ($reserveId) {
            $query->where('reserve_id', $reserveId);
        } else {
            $query->whereNull('reserve_id')->where('book_id', $bookId);
        }

        $pending = $query->orderBy('queue_position')->orderBy('hold_id')->get();

        $position = 1;

        foreach ($pending as $hold) {
            if ((int) $hold->queue_position !== $position) {
                $hold->update(['queue_position' => $position]);
            }

            $position++;
        }
    }

    /**
     * Cancel a hold, general or reserve-scoped.
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
            $reserveId = $hold->reserve_id;

            $hold->update(['status' => 'cancelled']);

            if ($wasFulfilled && $copyId) {
                // The copy is now freed up. See if anyone else in the SAME pool
                // is waiting for it.
                $wasHoldFulfilled = $reserveId
                    ? $this->advanceReserveQueue($reserveId, $copyId)
                    : $this->advanceQueue($bookId, $copyId);

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
            $this->resequence($bookId, $reserveId);

            return $hold;
        });
    }
}
