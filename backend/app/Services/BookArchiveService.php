<?php

namespace App\Services;

use App\Models\Book;
use App\Models\CourseReserve;
use App\Models\Hold;
use App\Models\Transaction;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Archive replaces delete.
 *
 * A title that has ever circulated is referenced by transactions, fines and
 * course reserves. Deleting it would either orphan that history or cascade it
 * away, so instead the title is marked archived: hidden from borrowers, closed
 * to new activity, and fully readable in every historical record.
 */
class BookArchiveService
{
    /**
     * Archive a title, refusing while anything is still live on it.
     *
     * The point of the refusal is that a borrower holding the book, or queued
     * for it, would otherwise be left in a state nobody can resolve.
     */
    public function archive(int $bookId, ?int $actorId, ?string $reason = null): Book
    {
        return DB::transaction(function () use ($bookId, $actorId, $reason) {
            $book = Book::where('book_id', $bookId)->lockForUpdate()->firstOrFail();

            if ($book->isArchived()) {
                throw new Exception('This title is already archived.');
            }

            $blockers = $this->blockers($book);

            if (! empty($blockers)) {
                throw new Exception(
                    'This title cannot be archived yet: '.implode(' ', $blockers)
                );
            }

            $book->archived_at = now();
            $book->archived_by = $actorId;
            $book->archive_reason = $reason;
            $book->save();

            return $book;
        });
    }

    public function restore(int $bookId): Book
    {
        return DB::transaction(function () use ($bookId) {
            $book = Book::where('book_id', $bookId)->lockForUpdate()->firstOrFail();

            if (! $book->isArchived()) {
                throw new Exception('This title is not archived.');
            }

            $book->archived_at = null;
            $book->archived_by = null;
            $book->archive_reason = null;
            $book->save();

            return $book;
        });
    }

    /**
     * Everything that would become inconsistent if this title were archived.
     *
     * Returned as sentences rather than codes, because the librarian needs to
     * know what to resolve, not that a check failed.
     *
     * @return string[]
     */
    public function blockers(Book $book): array
    {
        $copyIds = $book->copies()->pluck('copy_id');
        $blockers = [];

        if ($copyIds->isNotEmpty()) {
            $activeLoans = Transaction::whereIn('copy_id', $copyIds)
                ->where('status', 'active')
                ->count();

            if ($activeLoans > 0) {
                $blockers[] = $activeLoans === 1
                    ? '1 copy is still on loan.'
                    : "{$activeLoans} copies are still on loan.";
            }
        }

        $openHolds = Hold::where('book_id', $book->book_id)
            ->whereIn('status', ['pending', 'pending_approval', 'fulfilled'])
            ->count();

        if ($openHolds > 0) {
            $blockers[] = $openHolds === 1
                ? '1 borrower is waiting or has a copy ready for pickup.'
                : "{$openHolds} borrowers are waiting or have a copy ready for pickup.";
        }

        $liveReserves = CourseReserve::where('book_id', $book->book_id)
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->count();

        if ($liveReserves > 0) {
            $blockers[] = $liveReserves === 1
                ? '1 course reserve still refers to it.'
                : "{$liveReserves} course reserves still refer to it.";
        }

        return $blockers;
    }
}
