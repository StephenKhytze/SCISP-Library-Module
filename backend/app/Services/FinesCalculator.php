<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Overdue fines.
 *
 * The rate, the grace period and the per-loan cap are now Library settings
 * rather than constants. They are read at the moment a fine is calculated,
 * which is check-in — so changing a rate affects future check-ins only and
 * never rewrites a balance already recorded on users.total_fines.
 */
class FinesCalculator
{
    protected LibrarySettingsService $settings;

    public function __construct(LibrarySettingsService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Whole overdue calendar days, rounding any partial day UP, after the
     * borrower's grace period has been used up.
     *
     * Carbon 3's diffInDays() returns a float, so 2 days + 1 hour is 2.041...
     * which must be charged as 3 full days.
     */
    public function overdueDays($dueDate, $returnDate, ?string $dbRole = null): int
    {
        if ($returnDate->lessThanOrEqualTo($dueDate)) {
            return 0;
        }

        $days = max(1, (int) ceil(abs($dueDate->diffInDays($returnDate))));

        // Grace is forgiveness, not a shift: a borrower three days late with a
        // two-day grace is charged for one day, not three.
        $grace = $dbRole === null ? 0 : $this->settings->graceDaysFor($dbRole);

        return max(0, $days - $grace);
    }

    /**
     * The fine for one overdue loan.
     *
     * @param string|null $dbRole borrower's role; null keeps the student rate,
     *                            which is what every caller used before roles
     *                            became configurable.
     */
    public function calculateFine($dueDate, $returnDate, ?string $dbRole = null): float
    {
        $role = $dbRole ?? 'student';

        $days = $this->overdueDays($dueDate, $returnDate, $role);

        if ($days === 0) {
            return 0.00;
        }

        $fine = round($days * $this->dailyRate($role), 2);

        $cap = $this->settings->maxFineFor($role);

        return $cap === null ? $fine : min($fine, round($cap, 2));
    }

    /** The configured per-day rate, for display and calculation. */
    public function dailyRate(?string $dbRole = null): float
    {
        return $this->settings->finePerDayFor($dbRole ?? 'student');
    }
}
