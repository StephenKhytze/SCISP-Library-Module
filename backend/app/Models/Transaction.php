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
