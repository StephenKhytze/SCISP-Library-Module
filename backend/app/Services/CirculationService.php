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

            // Clear any pending or fulfilled hold this borrower had for the copy
            // they just received. Scoped to the same pool the copy came from, so
            // checking out a reserve copy never silently cancels the borrower's
            // separate general-circulation hold for the same title.
            $existingHold = \App\Models\Hold::where('book_id', $copy->book_id)
                ->where('user_id', $user->user_id)
                ->whereIn('status', ['pending', 'fulfilled'])
                ->when($copy->reserve_id, fn ($q) => $q->where('reserve_id', $copy->reserve_id))
                ->when(! $copy->reserve_id, fn ($q) => $q->whereNull('reserve_id'))
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
                // Lock the user to apply fine safely. users.total_fines is the
                // authoritative balance; no separate fine ledger is kept.
                $user = User::where('user_id', $transaction->user_id)->lockForUpdate()->first();
                $user->increment('total_fines', $fine);
            }

            $transaction->status = 'returned';
            $transaction->actual_return_date = $returnDate;
            $transaction->save();

            $transaction->load('bookCopy');
            $isReserved = $transaction->bookCopy && $transaction->bookCopy->reserve_id !== null;

            // Mark the copy as available again, OR fulfill a hold if someone is waiting.
            // A reserve copy advances its own reserve's queue; a general copy
            // advances the title's general waitlist. The two never cross.
            $wasHoldFulfilled = false;

            if ($isReserved) {
                $wasHoldFulfilled = $this->holdService->advanceReserveQueue(
                    (int) $transaction->bookCopy->reserve_id,
                    $transaction->copy_id
                );
            } else {
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
