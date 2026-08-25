<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class FinesCalculator
{
    protected float $dailyRate = 5.00;

    /**
     * Calculate the total fine for an overdue transaction.
     *
     * @param Carbon $dueDate
     * @param Carbon $returnDate
     * @return float
     */
    public function calculateFine(Carbon $dueDate, Carbon $returnDate): float
    {
        if ($returnDate->lessThanOrEqualTo($dueDate)) {
            return 0.00;
        }

        $daysLate = $dueDate->diffInDays($returnDate);
        
        return $daysLate * $this->dailyRate;
    }
}
