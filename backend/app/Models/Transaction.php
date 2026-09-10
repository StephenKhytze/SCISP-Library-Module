<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = ['user_id', 'copy_id', 'date_borrowed', 'due_date', 'actual_return_date', 'status'];
    use HasFactory;

    protected $primaryKey = 'transaction_id';

    /**
     * Overdue state is DERIVED at read time, never stored.
     * Fines are only written to users.total_fines on check-in.
     */
    protected $appends = ['is_overdue', 'days_overdue', 'estimated_fine'];

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'active'
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    public function getDaysOverdueAttribute(): int
    {
        if (! $this->is_overdue) {
            return 0;
        }

        return app(\App\Services\FinesCalculator::class)->overdueDays($this->due_date, now());
    }

    /** What the fine WOULD be if returned right now. Not persisted. */
    public function getEstimatedFineAttribute(): float
    {
        if (! $this->is_overdue) {
            return 0.00;
        }

        return app(\App\Services\FinesCalculator::class)->calculateFine($this->due_date, now());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_borrowed' => 'datetime',
            'due_date' => 'datetime',
            'actual_return_date' => 'datetime',
        ];
    }

    /**
     * Get the user that made the transaction.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Get the book copy for the transaction.
     */
    public function bookCopy()
    {
        return $this->belongsTo(BookCopy::class, 'copy_id', 'copy_id');
    }
}
