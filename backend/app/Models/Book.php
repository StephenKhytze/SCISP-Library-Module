<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    use HasFactory;

    protected $primaryKey = 'book_id';

    protected $fillable = ['book_title', 'author', 'category', 'isbn', 'physical_location', 'total_copies'];

    /**
     * Get the copies for the book.
     */
    public function copies()
    {
        return $this->hasMany(BookCopy::class, 'book_id', 'book_id');
    }

    /**
     * Get the holds for the book.
     */
    public function holds()
    {
        return $this->hasMany(Hold::class, 'book_id', 'book_id');
    }
}
