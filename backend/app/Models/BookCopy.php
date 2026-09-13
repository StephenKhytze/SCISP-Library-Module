<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookCopy extends Model
{
    use HasFactory;

    protected $primaryKey = 'copy_id';

    protected $appends = ['label'];
    
    /**
     * `accession_number` is NOT fillable. It is assigned once by
     * AccessionNumberService when the copy is created and is immutable
     * afterwards — the label is physically written on the book, so changing it
     * in software would make the database disagree with the shelf.
     */
    protected $fillable = ['book_id', 'condition', 'availability_status', 'reserve_id'];

    protected static function booted(): void
    {
        static::updating(function (BookCopy $copy) {
            if ($copy->isDirty('accession_number') && $copy->getOriginal('accession_number')) {
                throw new \RuntimeException('An accession number cannot be changed once assigned.');
            }
        });
    }

    /** What a librarian should see on screen for this copy. */
    public function getLabelAttribute(): string
    {
        return $this->accession_number ?: 'CPY-'.$this->copy_id;
    }

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

    /**
     * The open loan on this copy, if any.
     * Serialised as `active_transaction`, which the reserves UI already reads.
     */
    public function activeTransaction()
    {
        return $this->hasOne(Transaction::class, 'copy_id', 'copy_id')
            ->where('status', 'active')
            ->with('user');
    }
}
