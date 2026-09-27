<?php

namespace App\Services;

use App\Models\BookCopy;
use App\Models\CourseReserve;
use App\Models\Hold;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The course reserve lifecycle.
 *
 *   pending  -> approved   (Admin / Super Admin)
 *   pending  -> denied     (Admin / Super Admin)
 *   approved -> released   (Admin / Super Admin, or the owning faculty member)
 *
 * Nothing else. A denied or released reserve is final.
 *
 * Ending a reserve (deny or release) dissolves it atomically: every live hold
 * in its queue is cancelled, every allocated copy is detached, and a copy
 * that was set aside for one of those holds is returned to general
 * circulation — offered to the general waitlist if someone is waiting, put
 * back on the shelf otherwise. Condition, damaged/lost status and archive
 * state are never touched, so a damaged or lost copy is not made available.
 */
class ReserveLifecycleService
{
    /** from => allowed targets */
    public const TRANSITIONS = [
        'pending' => ['approved', 'denied'],
        'approved' => ['released'],
    ];

    /** Reserve states in which an allocation is still live. */
    public const LIVE_STATUSES = ['pending', 'approved'];

    public function __construct(protected HoldService $holds) {}

    public function transition(int $reserveId, string $to, ?string $note = null): CourseReserve
    {
        return DB::transaction(function () use ($reserveId, $to, $note) {
            $reserve = CourseReserve::where('reserve_id', $reserveId)->lockForUpdate()->firstOrFail();

            $from = (string) $reserve->status;

            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new \DomainException($this->refusal($from, $to));
            }

            $reserve->status = $to;

            if ($note !== null) {
                $reserve->admin_to_teacher_note = $note;
            }

            $reserve->save();

            if (in_array($to, ['denied', 'released'], true)) {
                $this->dissolve($reserve);
            }

            return $reserve;
        });
    }

    /**
     * Cancel the reserve's queue and hand its copies back to general stock.
     * Must run inside the transition's transaction.
     */
    protected function dissolve(CourseReserve $reserve): void
    {
        // 1. Every live hold in this reserve's queue ends. Copies they held
        //    are NOT promoted inside this reserve — it no longer exists as a
        //    pool — so promotion is suppressed here and handled in step 3.
        $liveHolds = Hold::where('reserve_id', $reserve->reserve_id)
            ->whereIn('status', ['pending', 'pending_approval', 'fulfilled'])
            ->lockForUpdate()
            ->get();

        $heldCopyIds = $liveHolds
            ->filter(fn ($h) => in_array($h->status, ['pending_approval', 'fulfilled'], true) && $h->copy_id)
            ->pluck('copy_id');

        foreach ($liveHolds as $hold) {
            $this->holds->cancelAndFree($hold, promote: false);
        }

        // 2. Detach every allocated copy. Only the pointer changes; condition,
        //    availability and archive state are left exactly as they are.
        $copyIds = BookCopy::where('reserve_id', $reserve->reserve_id)
            ->lockForUpdate()
            ->pluck('copy_id');

        BookCopy::whereIn('copy_id', $copyIds)->update(['reserve_id' => null]);

        // A copy still marked on_hold with no live hold behind it was only
        // on_hold because of this reserve (legacy residue). Release it too.
        $orphanedOnHold = BookCopy::whereIn('copy_id', $copyIds)
            ->where('availability_status', 'on_hold')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('holds')
                    ->whereColumn('holds.copy_id', 'book_copies.copy_id')
                    ->whereIn('holds.status', ['pending_approval', 'fulfilled']);
            })
            ->pluck('copy_id');

        BookCopy::whereIn('copy_id', $orphanedOnHold)->update(['availability_status' => 'available']);

        // 3. A copy freed above was put back on the shelf. Now that it is
        //    general stock, give the title's general waitlist first claim on
        //    it, exactly as a returned copy would.
        foreach ($heldCopyIds->merge($orphanedOnHold)->unique() as $copyId) {
            $copy = BookCopy::where('copy_id', $copyId)->lockForUpdate()->first();

            if ($copy && $copy->availability_status === 'available' && $copy->isUsable()) {
                $this->holds->advanceQueue($copy->book_id, $copy->copy_id);
            }
        }
    }

    /**
     * M-2: would moving these copies into a reserve leave the title's general
     * waitlist with no copy on the shelf to serve it?
     *
     * Mirrors the D-3 rule for copy archive, with the stricter "usable" test
     * the allocation rule calls for: in the collection, on the shelf and free
     * (available — not checked out, not on hold), not damaged or lost, and not
     * serving a live reserve.
     */
    public function wouldStarveGeneralQueue(int $bookId, Collection $allocatingCopies): bool
    {
        // Only copies leaving the general pool matter. A copy already in a
        // live reserve is not general stock to begin with.
        $liveReserveIds = CourseReserve::whereIn('status', self::LIVE_STATUSES)->pluck('reserve_id')->flip();

        $leavingGeneral = $allocatingCopies->filter(
            fn ($c) => $c->reserve_id === null || ! $liveReserveIds->has($c->reserve_id)
        );

        if ($leavingGeneral->isEmpty()) {
            return false;
        }

        $waiting = Hold::where('book_id', $bookId)
            ->whereNull('reserve_id')
            ->where('status', 'pending')
            ->exists();

        if (! $waiting) {
            return false;
        }

        $remainingUsable = BookCopy::active()
            ->where('book_id', $bookId)
            ->whereNotIn('copy_id', $leavingGeneral->pluck('copy_id'))
            ->where('availability_status', 'available')
            ->where('condition', '!=', 'damaged')
            ->where(function ($q) use ($liveReserveIds) {
                $q->whereNull('reserve_id')
                    ->orWhereNotIn('reserve_id', $liveReserveIds->keys()->all() ?: [0]);
            })
            ->exists();

        return ! $remainingUsable;
    }

    protected function refusal(string $from, string $to): string
    {
        if ($from === $to) {
            return "This course reserve is already {$to}.";
        }

        if (in_array($from, ['denied', 'released'], true)) {
            return "This course reserve is {$from}. A {$from} reserve cannot be changed.";
        }

        $allowed = self::TRANSITIONS[$from] ?? [];
        $article = in_array(strtolower($from[0] ?? ''), ['a', 'e', 'i', 'o', 'u'], true) ? 'An' : 'A';

        return $allowed
            ? "{$article} {$from} course reserve can only be ".implode(' or ', $allowed).", not {$to}."
            : "A course reserve in status \"{$from}\" cannot be changed.";
    }
}
