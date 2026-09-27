<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Book extends Model
{
    use HasFactory;

    protected $primaryKey = 'book_id';

    /**
     * `isbn_normalized` is deliberately NOT fillable — it is derived from
     * `isbn` in the boot hook below, never supplied by a caller. The archive
     * columns are likewise set only through BookArchiveService.
     */
    protected $fillable = [
        'book_title',
        'author',
        'edition',
        'publisher',
        'publication_year',
        'category',
        'isbn',
        'physical_location',
        'total_copies',
        'cover_image_path',
    ];

    protected $appends = ['cover_url', 'is_archived'];

    protected function casts(): array
    {
        return [
            'publication_year' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keeping the comparison key in sync here means no caller can create a
        // book whose normalised ISBN disagrees with its displayed one.
        static::saving(function (Book $book) {
            if ($book->isDirty('isbn')) {
                $book->isbn_normalized = self::normalizeIsbn($book->isbn);
            }
        });
    }

    /**
     * Strip the formatting people type but do not mean.
     *
     * "978-0-13-235088-4", "978 0 13 235088 4" and "9780132350884" are the same
     * book. X is kept because it is a valid ISBN-10 check digit.
     */
    public static function normalizeIsbn(?string $isbn): ?string
    {
        if ($isbn === null) {
            return null;
        }

        $normalized = preg_replace('/[^0-9A-Z]/', '', strtoupper(trim($isbn)));

        return $normalized === '' ? null : $normalized;
    }

    /** Loose normalisation for the ISBN-less duplicate check. */
    public static function normalizeText(?string $value): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim((string) $value)));
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function getIsArchivedAttribute(): bool
    {
        return $this->archived_at !== null;
    }

    /** A URL the frontend can use directly, or null so it shows a placeholder. */
    public function getCoverUrlAttribute(): ?string
    {
        if (! $this->cover_image_path) {
            return null;
        }

        return Storage::disk('public')->url($this->cover_image_path);
    }

    /** Titles borrowers are allowed to see. */
    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

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

    public function archivedBy()
    {
        return $this->belongsTo(User::class, 'archived_by', 'user_id');
    }
}
