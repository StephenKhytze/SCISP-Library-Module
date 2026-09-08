<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookCopy extends Model
{
    use HasFactory;

    protected $primaryKey = 'copy_id';
    
    protected $fillable = ['book_id', 'condition', 'availability_status', 'reserve_id'];

    /**
     * Get the book that owns the copy.
     */
    public function book()
    {
        return $this->belongsTo(Book::class, 'book_id', 'book_id'); // book_id on Book is usually id, wait let me check
    }

    /**
     * Get the transactions for the book copy.
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'copy_id', 'copy_id');
    }

    public function reserve()
    {
        return $this->belongsTo(CourseReserve::class, 'reserve_id', 'reserve_id');
    }
}
