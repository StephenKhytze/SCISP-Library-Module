<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class FinesCalculator
{
    /** Overdue fine charged per calendar day. */
    protected float $dailyRate = 10.00;

    /**
     * Whole overdue calendar days, rounding any partial day UP.
     *
     * Carbon 3's diffInDays() returns a float, so 2 days + 1 hour is 2.041...
     * which must be charged as 3 full days.
     */
    public function overdueDays($dueDate, $returnDate): int
    {
        if ($returnDate->lessThanOrEqualTo($dueDate)) {
            return 0;
        }

        return max(1, (int) ceil(abs($dueDate->diffInDays($returnDate))));
    }

    /**
     * Calculate the total fine for an overdue transaction.
     *
     * @param Carbon $dueDate
     * @param Carbon $returnDate
     * @return float
     */
    public function calculateFine($dueDate, $returnDate): float
    {
        return round($this->overdueDays($dueDate, $returnDate) * $this->dailyRate, 2);
    }

    /** The configured per-day rate, for display. */
    public function dailyRate(): float
    {
        return $this->dailyRate;
    }
}
