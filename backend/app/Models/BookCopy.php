<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['book_id', 'condition', 'availability_status'])]
class BookCopy extends Model
{
    use HasFactory;

    protected $primaryKey = 'copy_id';

    /**
     * Get the book that owns the copy.
     */
    public function book()
    {
        return $this->belongsTo(Book::class, 'book_id', 'book_id');
    }

    /**
     * Get the transactions for the book copy.
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'copy_id', 'copy_id');
    }
}
