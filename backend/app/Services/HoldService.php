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
            $availableCopy = BookCopy::active()
                ->where('book_id', $bookId)
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
            $allocatedCopyIds = BookCopy::active()->where('reserve_id', $reserveId)->pluck('copy_id');

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
            $freeCopy = BookCopy::active()
                ->where('reserve_id', $reserveId)
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
        // Only a live reserve hands out its copies. A released or denied
        // reserve has no queue left to serve.
        $reserve = CourseReserve::where('reserve_id', $reserveId)->lockForUpdate()->first();

        if (! $reserve || $reserve->status !== 'approved') {
            return false;
        }

        while (true) {
            $nextHold = Hold::where('reserve_id', $reserveId)
                ->where('status', 'pending')
                ->orderBy('queue_position', 'asc')
                ->orderBy('hold_id', 'asc')
                ->lockForUpdate()
                ->first();

            if (! $nextHold) {
                return false;
            }

            // A reserve copy is for the section. Someone who has since left the
            // section is no longer eligible; drop them from the queue rather
            // than hand them a copy checkout would refuse, and try the next.
            $stillEnrolled = CourseSectionStudent::where('section_id', $reserve->section_id)
                ->where('student_id', $nextHold->user_id)
                ->exists();

            if (! $stillEnrolled) {
                $nextHold->update(['status' => 'cancelled']);
                $this->resequence($nextHold->book_id, $reserveId);

                continue;
            }

            // A reserve is already approved, so its queue promotes straight to
            // Ready for Pickup — the same state a direct reserve request produces.
            // General holds keep their existing "Getting Approval" step.
            return $this->promote($nextHold, $copyId, 'fulfilled');
        }
    }

    /**
     * Give a waiting borrower the copy that just came back.
     *
     * Refuses — returning false, touching nothing — unless the copy can
     * genuinely serve this borrower: in the collection, not damaged or lost,
     * free (or freshly released from a hold), and in the same pool as the
     * hold. Every caller already treats false as "nobody was promoted", so a
     * refused copy is simply not handed out and the borrower keeps waiting.
     */
    protected function promote(Hold $hold, int $copyId, string $status = 'pending_approval'): bool
    {
        $copy = BookCopy::where('copy_id', $copyId)->lockForUpdate()->first();

        if (! $copy || ! $this->canServe($copy, $hold)) {
            return false;
        }

        $hold->update([
            'status' => $status,
            'queue_position' => 0,
            'copy_id' => $copyId,
        ]);

        $copy->update(['availability_status' => 'on_hold']);

        // Note: In a real system, you'd trigger a notification/email here.

        $this->resequence($hold->book_id, $hold->reserve_id);

        return true;
    }

    /**
     * May this copy be handed to this hold?
     *
     * `on_hold` is accepted because two callers promote a copy that is being
     * released from someone else's hold (cancellation, and checkout of a
     * different copy). `checked_out` is not: check-in puts the copy back on
     * the shelf before promoting.
     */
    protected function canServe(BookCopy $copy, Hold $hold): bool
    {
        if (! $copy->isUsable()) {
            return false; // archived, lost, or damaged by status or condition
        }

        if (! in_array($copy->availability_status, ['available', 'on_hold'], true)) {
            return false;
        }

        // Pools never cross: general holds take general stock only, a reserve
        // queue takes only its own reserve's copies.
        return $hold->reserve_id === null
            ? $copy->reserve_id === null
            : (int) $copy->reserve_id === (int) $hold->reserve_id;
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
            // Locked, so two cancellations (or a cancel racing a promotion)
            // cannot both free the same copy.
            $hold = Hold::where('hold_id', $holdId)->lockForUpdate()->firstOrFail();

            if (!$isAdmin && $hold->user_id !== $userId) {
                throw new Exception("You are not authorized to cancel this hold.");
            }

            if (!in_array($hold->status, ['pending', 'pending_approval', 'fulfilled'])) {
                throw new Exception("Only pending, pending_approval, or fulfilled holds can be cancelled.");
            }

            $this->cancelAndFree($hold);

            return $hold;
        });
    }

    /**
     * Cancel one live hold and deal with any copy it was holding.
     *
     * The single path for every way a hold ends early — a borrower or
     * librarian cancelling, a reserve being released or denied, a student
     * being removed from a section — so each leaves the copy and the queue in
     * exactly the same state. Call inside a transaction.
     *
     * @param  bool  $promote  false when the hold's own pool is being dissolved
     *                         and must not hand the copy to anyone in it
     */
    public function cancelAndFree(Hold $hold, bool $promote = true): void
    {
        $ownedCopyId = in_array($hold->status, ['pending_approval', 'fulfilled'], true)
            ? $hold->copy_id
            : null;

        $hold->update(['status' => 'cancelled']);

        if ($ownedCopyId) {
            $this->freeCopy((int) $ownedCopyId, $promote);
        }

        // Close any gap this cancellation left in the waitlist.
        $this->resequence($hold->book_id, $hold->reserve_id);
    }

    /**
     * A copy has stopped being held for someone.
     *
     * Offer it to the next eligible borrower in the copy's OWN pool — its
     * current reserve_id decides which queue, so a copy detached from a
     * released reserve serves the general waitlist and never a stale reserve
     * queue. If nobody can take it, it goes back on the shelf.
     *
     * Only an `on_hold` copy is touched: a copy that is damaged, lost or
     * already on loan keeps its status, so this can never make a damaged or
     * lost copy available. Promotion itself refuses archived and unusable
     * copies (see canServe()).
     */
    public function freeCopy(int $copyId, bool $promote = true): void
    {
        $copy = BookCopy::where('copy_id', $copyId)->lockForUpdate()->first();

        if (! $copy || $copy->availability_status !== 'on_hold') {
            return;
        }

        $promoted = false;

        if ($promote) {
            $promoted = $copy->reserve_id !== null
                ? $this->advanceReserveQueue((int) $copy->reserve_id, $copy->copy_id)
                : $this->advanceQueue($copy->book_id, $copy->copy_id);
        }

        if (! $promoted) {
            $copy->refresh();

            if ($copy->availability_status === 'on_hold') {
                $copy->update(['availability_status' => 'available']);
            }
        }
    }
}
