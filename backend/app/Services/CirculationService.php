<?php

namespace App\Services;

use App\Models\BookCopy;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Exception;

class CirculationService
{
    protected $finesCalculator;
    protected $holdService;
    protected LibrarySettingsService $settings;

    public function __construct(
        FinesCalculator $finesCalculator,
        HoldService $holdService,
        LibrarySettingsService $settings
    ) {
        $this->finesCalculator = $finesCalculator;
        $this->holdService = $holdService;
        $this->settings = $settings;
    }

    /**
     * Get the due date based on the user's role.
     */
    protected function getDueDateForRole(string $role)
    {
        // Read at checkout time. Changing the loan length later moves future
        // due dates only; loans already out keep the date they were given.
        return now()->addDays($this->settings->loanDaysFor($this->normalizeRole($role)));
    }

    /**
     * Reduce any role spelling to the three database roles.
     *
     * Roles reach this service in several vocabularies: the raw request header
     * ("Admin", "Super Admin", "Teacher") and the users.role enum
     * ("administrator", "faculty", "student"). Mirrors MockAuthMiddleware.
     */
    public function normalizeRole(?string $role): string
    {
        $normalized = strtolower(trim((string) $role));

        if (str_contains($normalized, 'admin')) {
            return 'administrator';
        }

        if (str_contains($normalized, 'faculty') || str_contains($normalized, 'teacher')) {
            return 'faculty';
        }

        return 'student';
    }

    /**
     * Admin and Super Admin are both librarians — they run the desk.
     * Where they now differ is BORROWING, which is a property of the borrower's
     * account (User::canBorrow), not of the role string.
     */
    protected function isLibrarian(?string $role): bool
    {
        return $this->normalizeRole($role) === 'administrator';
    }

    /** Maximum concurrent active loans, or null when unlimited. */
    public function getBorrowLimitForRole(string $dbRole): ?int
    {
        return $this->settings->maxBooksFor($dbRole);
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

            // The borrower is resolved before any eligibility rule, because every
            // rule below is about the BORROWER — never the librarian operating the desk.
            $user = User::find($userId);

            if (!$user) {
                throw new Exception("Borrower not found.");
            }

            // An archived title has been withdrawn from circulation.
            $book = \App\Models\Book::find($copy->book_id);

            if ($book && $book->isArchived()) {
                throw new Exception('This title has been archived and is no longer available for borrowing.');
            }

            // An archived copy has left the collection even if its status
            // still reads "available" — archive is kept apart from status.
            if ($copy->isArchived()) {
                throw new Exception("Copy {$copy->label} has been archived and is no longer in circulation.");
            }

            // Belt and braces for D-1: a damaged condition always means a
            // damaged status, but never lend a damaged book even if a row
            // somehow disagrees.
            if ($copy->condition === 'damaged') {
                throw new Exception("Copy {$copy->label} is damaged and cannot be borrowed.");
            }

            // Management-only accounts are not borrowers. This has to be read
            // from the borrower's record: on checkout the borrower arrives as a
            // user_id and sends no role header of their own.
            if (! $user->canBorrow()) {
                throw new Exception('Super Admin accounts cannot borrow library materials.');
            }

            $borrowerRole = $this->normalizeRole($user->role);

            // Borrowing can be switched off for a whole role, e.g. during a
            // stocktake or at the end of term.
            if (! $this->settings->borrowingEnabledFor($borrowerRole)) {
                throw new Exception('Borrowing is currently disabled for this borrower category.');
            }

            // Outstanding fines block further borrowing until a librarian settles them.
            if ((float) $user->total_fines > 0) {
                throw new Exception(
                    'This borrower has an outstanding balance of PHP '
                    .number_format((float) $user->total_fines, 2)
                    .'. Record a payment or waive it before borrowing.'
                );
            }

            // Role-based active loan limit.
            $limit = $this->getBorrowLimitForRole($borrowerRole);

            if ($limit !== null) {
                $activeLoans = Transaction::where('user_id', $userId)
                    ->where('status', 'active')
                    ->count();

                if ($activeLoans >= $limit) {
                    throw new Exception("Borrowing limit reached: {$activeLoans} of {$limit} active loans.");
                }
            }

            $isReserved = false;
            if ($copy->reserve_id) {
                $reserve = \App\Models\CourseReserve::find($copy->reserve_id);
                if ($reserve && $reserve->status === 'approved') {
                    $isReserved = true;

                    // Only a student borrower must be enrolled in the reserve's section.
                    // Faculty borrowers are eligible; the operator's role is irrelevant.
                    if ($borrowerRole === 'student') {
                        $studentInClass = \App\Models\CourseSectionStudent::where('section_id', $reserve->section_id)
                            ->where('student_id', $userId)
                            ->exists();

                        if (!$studentInClass) {
                            throw new Exception("This copy is reserved for a course section the borrower is not enrolled in.");
                        }
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

            // Update the copy status
            $copy->update(['availability_status' => 'checked_out']);

            $dueDate = $this->getDueDateForRole($user->role);
            if ($isReserved) {
                $dueDate = now()->addDays($this->settings->reserveLoanDays());
            }

            // Create the transaction
            $transaction = Transaction::create([
                'user_id' => $user->user_id,
                'copy_id' => $copy->copy_id,
                'date_borrowed' => now(),
                'due_date' => $dueDate,
                'status' => 'active',
            ]);

            // Resolve the hold this borrower had for what they just received.
            //
            // First by copy_id: the hold that owns THIS copy is the one being
            // satisfied. Matching on the copy rather than on the pool matters
            // because the copy's reserve_id can change while it waits on the
            // shelf, and a pool lookup would then miss it and leave a phantom
            // Ready for Pickup behind.
            $existingHold = \App\Models\Hold::where('copy_id', $copy->copy_id)
                ->where('user_id', $user->user_id)
                ->whereIn('status', ['pending_approval', 'fulfilled'])
                ->lockForUpdate()
                ->first();

            // Otherwise by pool: a hold for the same title in the same pool the
            // copy came from, so checking out a reserve copy never silently
            // cancels the borrower's separate general-circulation hold.
            if (! $existingHold) {
                $existingHold = \App\Models\Hold::where('book_id', $copy->book_id)
                    ->where('user_id', $user->user_id)
                    ->whereIn('status', ['pending', 'pending_approval', 'fulfilled'])
                    ->when($copy->reserve_id, fn ($q) => $q->where('reserve_id', $copy->reserve_id))
                    ->when(! $copy->reserve_id, fn ($q) => $q->whereNull('reserve_id'))
                    ->lockForUpdate()
                    ->first();
            }

            if ($existingHold) {
                $heldOtherCopy = in_array($existingHold->status, ['pending_approval', 'fulfilled'], true)
                    && $existingHold->copy_id
                    && (int) $existingHold->copy_id !== (int) $copy->copy_id;

                $otherCopyId = $heldOtherCopy ? (int) $existingHold->copy_id : null;

                $existingHold->delete();

                // Admin checked out a different physical copy than the one set
                // aside. Release that one: to the next waiting borrower in its
                // own pool if there is one, back on the shelf otherwise — never
                // making a damaged or lost copy available.
                if ($otherCopyId) {
                    $this->holdService->freeCopy($otherCopyId);
                }
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

            // The fine follows the BORROWER's policy — rate, grace and cap —
            // not the student default, and not the librarian running the desk.
            // This is the same role the borrowing screen's estimate uses, so
            // what a borrower was shown is what they are charged.
            $borrower = User::find($transaction->user_id);

            $fine = $this->finesCalculator->calculateFine(
                $transaction->due_date,
                $returnDate,
                $this->normalizeRole($borrower->role ?? 'student')
            );

            if ($fine > 0) {
                // Lock the user to apply fine safely. users.total_fines is the
                // authoritative balance; no separate fine ledger is kept.
                $user = User::where('user_id', $transaction->user_id)->lockForUpdate()->first();
                $user->increment('total_fines', $fine);
            }

            $transaction->status = 'returned';
            $transaction->actual_return_date = $returnDate;
            $transaction->save();

            $copy = BookCopy::where('copy_id', $transaction->copy_id)->lockForUpdate()->firstOrFail();

            // The loan is closed above no matter what. What happens to the
            // copy depends on whether it can serve anyone:
            //
            //  - damaged or lost (by status), or damaged (by condition):
            //    it keeps that status and is NOT handed to the next borrower.
            //    The queue stays pending until a usable copy comes back.
            //  - archived: it is no longer in circulation; release it from
            //    checked_out but do not promote anyone onto it.
            //  - otherwise: it returns to the shelf, and if someone is waiting
            //    in the same pool it goes straight to them (unchanged flow).
            if ($copy->condition === 'damaged') {
                $copy->update(['availability_status' => 'damaged']);

                return $transaction;
            }

            if (in_array($copy->availability_status, BookCopy::UNUSABLE_STATUSES, true)) {
                return $transaction;
            }

            // Back on the shelf first, so the copy is never promoted while it
            // still reads checked_out.
            $copy->update(['availability_status' => 'available']);

            if ($copy->isArchived()) {
                return $transaction;
            }

            // A reserve copy advances its own reserve's queue; a general copy
            // advances the title's general waitlist. The two never cross.
            if ($copy->reserve_id !== null) {
                $this->holdService->advanceReserveQueue((int) $copy->reserve_id, $copy->copy_id);
            } else {
                $this->holdService->advanceQueue($copy->book_id, $copy->copy_id);
            }

            return $transaction;
        });
    }

    /**
     * The due date an approved renewal should produce.
     *
     * Borrowers no longer renew directly — RenewalService::approve is the only
     * caller, so the rule lives here but the decision lives there.
     */
    public function dueDateForRenewal(Transaction $transaction, bool $isReserved): CarbonInterface
    {
        if ($isReserved) {
            return now()->addDays($this->settings->reserveLoanDays());
        }

        return $this->getDueDateForRole($transaction->user->role ?? 'student');
    }
}
