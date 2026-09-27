<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\RenewalRequest;
use App\Models\Transaction;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Renewal now needs a librarian's decision.
 *
 * Borrowers ask; Admin and Super Admin answer. A borrower can never move their
 * own due date — only an approval does that.
 */
class RenewalService
{
    protected CirculationService $circulation;
    protected LibrarySettingsService $settings;

    public function __construct(CirculationService $circulation, LibrarySettingsService $settings)
    {
        $this->circulation = $circulation;
        $this->settings = $settings;
    }

    /**
     * A borrower asks to extend one of their own active loans.
     */
    public function request(int $transactionId, int $userId): RenewalRequest
    {
        return DB::transaction(function () use ($transactionId, $userId) {
            $transaction = Transaction::where('transaction_id', $transactionId)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                throw new Exception('That loan could not be found.');
            }

            if ((int) $transaction->user_id !== (int) $userId) {
                throw new Exception('You can only request a renewal for your own loan.');
            }

            if ($transaction->status !== 'active') {
                throw new Exception('This loan has already been returned and cannot be renewed.');
            }

            $borrower = User::find($userId);

            if ($borrower && $borrower->isSuperAdmin()) {
                throw new Exception('Super Admin accounts cannot borrow library materials.');
            }

            $borrowerRole = $this->circulation->normalizeRole($borrower->role ?? 'student');

            if (! $this->settings->renewalEnabledFor($borrowerRole)) {
                throw new Exception('Renewal requests are currently disabled for this borrower category.');
            }

            // A cap counts APPROVED renewals: denials cost the borrower nothing.
            $maxRenewals = $this->settings->maxRenewalsPerLoan();

            if ($maxRenewals !== null) {
                $approved = RenewalRequest::where('transaction_id', $transactionId)
                    ->where('status', 'approved')
                    ->count();

                if ($approved >= $maxRenewals) {
                    throw new Exception(
                        $maxRenewals === 1
                            ? 'This loan has already been renewed once and cannot be renewed again.'
                            : "This loan has already been renewed {$maxRenewals} times and cannot be renewed again."
                    );
                }
            }

            // One open request per loan. The row lock above serialises concurrent
            // taps on the same button.
            $existing = RenewalRequest::where('transaction_id', $transactionId)
                ->where('status', 'pending')
                ->first();

            if ($existing) {
                throw new Exception('You already have a renewal request awaiting a decision for this loan.');
            }

            return RenewalRequest::create([
                'transaction_id' => $transactionId,
                'user_id' => $userId,
                'status' => 'pending',
            ]);
        });
    }

    /**
     * A librarian approves a pending request, which is the only thing that
     * moves a due date.
     */
    public function approve(int $renewalRequestId, int $deciderId): RenewalRequest
    {
        return DB::transaction(function () use ($renewalRequestId, $deciderId) {
            $renewal = RenewalRequest::where('renewal_request_id', $renewalRequestId)
                ->lockForUpdate()
                ->first();

            if (! $renewal) {
                throw new Exception('That renewal request could not be found.');
            }

            if ($renewal->status !== 'pending') {
                throw new Exception('This renewal request has already been decided.');
            }

            $transaction = Transaction::with(['user', 'bookCopy'])
                ->where('transaction_id', $renewal->transaction_id)
                ->lockForUpdate()
                ->first();

            if (! $transaction || $transaction->status !== 'active') {
                throw new Exception('This loan is no longer active, so it cannot be renewed.');
            }

            $maxRenewals = $this->settings->maxRenewalsPerLoan();

            if ($maxRenewals !== null) {
                $approved = RenewalRequest::where('transaction_id', $renewal->transaction_id)
                    ->where('status', 'approved')
                    ->count();

                if ($approved >= $maxRenewals) {
                    throw new Exception('This loan has reached the maximum number of renewals allowed.');
                }
            }

            $isReserved = $transaction->bookCopy && $transaction->bookCopy->reserve_id !== null;

            // Someone waiting for the same title outranks an extension. A
            // reserve copy is only blocked by its own reserve's queue, never by
            // the general waitlist for the title.
            if ($this->hasWaitingBorrower($transaction, $isReserved)) {
                throw new Exception('This loan cannot be renewed because another borrower is waiting for this title.');
            }

            $newDueDate = $this->circulation->dueDateForRenewal($transaction, $isReserved);

            $transaction->due_date = $newDueDate;
            $transaction->save();

            $renewal->update([
                'status' => 'approved',
                'decided_by' => $deciderId,
                'decided_at' => now(),
                'new_due_date' => $newDueDate,
            ]);

            return $renewal->fresh(['transaction']);
        });
    }

    /**
     * A librarian denies a pending request. The due date is left exactly as it
     * was.
     */
    public function deny(int $renewalRequestId, int $deciderId, ?string $note = null): RenewalRequest
    {
        return DB::transaction(function () use ($renewalRequestId, $deciderId, $note) {
            $renewal = RenewalRequest::where('renewal_request_id', $renewalRequestId)
                ->lockForUpdate()
                ->first();

            if (! $renewal) {
                throw new Exception('That renewal request could not be found.');
            }

            if ($renewal->status !== 'pending') {
                throw new Exception('This renewal request has already been decided.');
            }

            $renewal->update([
                'status' => 'denied',
                'decided_by' => $deciderId,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            return $renewal->fresh(['transaction']);
        });
    }

    /**
     * Is anyone queued for the copy this loan would keep out of circulation?
     */
    protected function hasWaitingBorrower(Transaction $transaction, bool $isReserved): bool
    {
        if (! $transaction->bookCopy) {
            return false;
        }

        $waiting = Hold::whereIn('status', ['pending', 'pending_approval'])
            ->where('user_id', '!=', $transaction->user_id);

        if ($isReserved) {
            // Only this reserve's own queue matters.
            $waiting->where('reserve_id', $transaction->bookCopy->reserve_id);
        } else {
            $waiting->whereNull('reserve_id')
                ->where('book_id', $transaction->bookCopy->book_id);
        }

        return $waiting->exists();
    }
}
