<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A borrower's request to extend a loan, awaiting a librarian's decision.
 *
 * Borrowers never change their own due date; approving this request is what
 * moves it.
 */
class RenewalRequest extends Model
{
    use HasFactory;

    protected $primaryKey = 'renewal_request_id';

    protected $fillable = [
        'transaction_id',
        'user_id',
        'status',
        'decided_by',
        'decided_at',
        'new_due_date',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'new_due_date' => 'datetime',
        ];
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'transaction_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by', 'user_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
