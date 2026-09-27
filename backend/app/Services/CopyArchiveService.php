<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\CourseReserve;
use App\Models\Hold;
use App\Models\Transaction;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Archive and restore ONE physical copy.
 *
 * Archive is a lifecycle state, orthogonal to condition and availability. It
 * writes three columns — archived_at, archived_by, archive_reason — and
 * nothing else: the copy keeps its id, its ABC-LIB accession number, its
 * condition, its availability status and every transaction that ever touched
 * it. Restore clears those same three columns and nothing else, so a copy
 * comes back exactly as it left: damaged stays damaged, lost stays lost.
 *
 * Title-level archive (BookArchiveService) is a separate concern and is not
 * changed by this class.
 */
class CopyArchiveService
{
    /** Reserve states in which an allocation is still live. */
    private const LIVE_RESERVE_STATUSES = ['pending', 'approved'];

    public function archive(int $copyId, ?int $actorId, ?string $reason = null): BookCopy
    {
        return DB::transaction(function () use ($copyId, $actorId, $reason) {
            $copy = BookCopy::where('copy_id', $copyId)->lockForUpdate()->firstOrFail();

            if ($copy->isArchived()) {
                throw new Exception("Copy {$copy->label} is already archived.");
            }

            $blockers = $this->blockers($copy);

            if (! empty($blockers)) {
                throw new Exception(
                    "Copy {$copy->label} cannot be archived yet: ".implode(' ', $blockers)
                );
            }

            // A pointer to a denied or released reserve is not a live
            // allocation — it is residue. Clear it so the archived copy does
            // not look set aside for a section that no longer wants it.
            if ($copy->reserve_id !== null && ! $this->reserveIsLive($copy->reserve_id)) {
                $copy->reserve_id = null;
            }

            $copy->archived_at = now();
            $copy->archived_by = $actorId;
            $copy->archive_reason = $reason;
            $copy->save();

            return $copy;
        });
    }

    public function restore(int $copyId): BookCopy
    {
        return DB::transaction(function () use ($copyId) {
            $copy = BookCopy::where('copy_id', $copyId)->lockForUpdate()->firstOrFail();

            if (! $copy->isArchived()) {
                throw new Exception("Copy {$copy->label} is not archived.");
            }

            // D-4: a copy restored under an archived title would be back in
            // the collection but still unborrowable, which reads as a bug.
            $book = Book::find($copy->book_id);

            if ($book && $book->isArchived()) {
                throw new Exception(
                    "The title \"{$book->book_title}\" is archived. Restore the title first."
                );
            }

            // Only the lifecycle columns. Condition, availability status and
            // accession number are deliberately left exactly as they are.
            $copy->archived_at = null;
            $copy->archived_by = null;
            $copy->archive_reason = null;
            $copy->save();

            return $copy;
        });
    }

    /**
     * Everything that would become inconsistent if this copy left circulation.
     *
     * Returned as sentences, because the librarian needs to know what to
     * resolve, not that a check failed.
     *
     * @return string[]
     */
    public function blockers(BookCopy $copy): array
    {
        $blockers = [];

        // 1. On loan.
        if (Transaction::where('copy_id', $copy->copy_id)->where('status', 'active')->exists()) {
            $blockers[] = 'It is on loan. Check it in first.';
        }

        // 2. Set aside for a named borrower (awaiting approval or Ready for Pickup).
        if (Hold::where('copy_id', $copy->copy_id)->whereIn('status', ['pending_approval', 'fulfilled'])->exists()) {
            $blockers[] = 'It is set aside for a borrower. Release or check out that hold first.';
        }

        // 3. Allocated to a course reserve that is still live.
        if ($copy->reserve_id !== null && $this->reserveIsLive($copy->reserve_id)) {
            $blockers[] = 'It is allocated to an active course reserve. Release the reserve first.';
        }

        // 4. Circulation-owned status with no matching record — a leftover
        //    contradiction. Refuse rather than archive over it.
        if (empty($blockers) && in_array($copy->availability_status, ['checked_out', 'on_hold'], true)) {
            $blockers[] = "Its status is \"{$copy->availability_status}\". Resolve that in circulation first.";
        }

        // 6. D-3: never strand a waiting queue. Only matters when this copy
        //    could actually have served it — archiving a copy that is already
        //    lost or damaged does not change what the queue can get.
        if (empty($blockers) && $this->wouldStrandGeneralQueue($copy)) {
            $blockers[] = 'Borrowers are waiting for this title and this is the last copy that can serve them.';
        }

        return $blockers;
    }

    /**
     * True when archiving this copy leaves the title's general waitlist with
     * no copy that could ever reach it.
     *
     * A copy on loan still counts: it will come back. Lost, damaged (by status
     * or condition), archived and reserve-allocated copies do not.
     */
    protected function wouldStrandGeneralQueue(BookCopy $copy): bool
    {
        // Reserve-allocated copies serve their own queue, not the general one.
        $servesGeneralQueue = $copy->reserve_id === null || ! $this->reserveIsLive($copy->reserve_id);

        if (! $servesGeneralQueue || ! $copy->isUsable()) {
            return false;
        }

        $waiting = Hold::where('book_id', $copy->book_id)
            ->whereNull('reserve_id')
            ->where('status', 'pending')
            ->exists();

        if (! $waiting) {
            return false;
        }

        $otherUsable = BookCopy::active()
            ->where('book_id', $copy->book_id)
            ->where('copy_id', '!=', $copy->copy_id)
            ->whereNotIn('availability_status', BookCopy::UNUSABLE_STATUSES)
            ->where('condition', '!=', 'damaged')
            ->where(function ($q) {
                $q->whereNull('reserve_id')
                    ->orWhereNotIn('reserve_id', CourseReserve::whereIn('status', self::LIVE_RESERVE_STATUSES)->select('reserve_id'));
            })
            ->exists();

        return ! $otherUsable;
    }

    protected function reserveIsLive(int $reserveId): bool
    {
        return CourseReserve::where('reserve_id', $reserveId)
            ->whereIn('status', self::LIVE_RESERVE_STATUSES)
            ->exists();
    }
}
