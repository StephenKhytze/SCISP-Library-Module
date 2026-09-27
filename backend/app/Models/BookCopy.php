<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookCopy extends Model
{
    use HasFactory;

    protected $primaryKey = 'copy_id';

    protected $appends = ['label', 'is_archived'];

    /**
     * Physical condition. `damaged` also forces availability_status=damaged —
     * see InventoryService::updateCopyStatus. `lost` is deliberately NOT a
     * condition: a lost book has no inspectable condition.
     */
    public const CONDITIONS = ['new', 'good', 'fair', 'poor', 'damaged'];

    /**
     * Availability a librarian may set by hand. `checked_out` and `on_hold`
     * are owned by circulation and the hold queue and are never set manually.
     */
    public const MANUAL_STATUSES = ['available', 'lost', 'damaged'];

    /** Statuses that mean the copy cannot serve any borrower. */
    public const UNUSABLE_STATUSES = ['lost', 'damaged'];

    /**
     * `archived_at`, `archived_by` and `archive_reason` are not fillable either:
     * they are written only by CopyArchiveService, so a generic update() can
     * never archive or un-archive a copy by accident.
     */
    /**
     * `accession_number` is NOT fillable. It is assigned once by
     * AccessionNumberService when the copy is created and is immutable
     * afterwards — the label is physically written on the book, so changing it
     * in software would make the database disagree with the shelf.
     */
    protected $fillable = ['book_id', 'condition', 'availability_status', 'reserve_id'];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (BookCopy $copy) {
            if ($copy->isDirty('accession_number') && $copy->getOriginal('accession_number')) {
                throw new \RuntimeException('An accession number cannot be changed once assigned.');
            }
        });
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function getIsArchivedAttribute(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Copies still in the collection — i.e. not archived.
     *
     * Every operational selection (availability, hold picks, reserve picks and
     * allocation, checkout) goes through this, so an archived copy can never
     * drift back into circulation because one query forgot a filter.
     */
    public function scopeActive($query)
    {
        return $query->whereNull($query->qualifyColumn('archived_at'));
    }

    /**
     * Could this copy serve a borrower once it is free? Active, not lost or
     * damaged by status, and not damaged by condition. Says nothing about
     * whether it is free right now.
     */
    public function isUsable(): bool
    {
        return ! $this->isArchived()
            && ! in_array($this->availability_status, self::UNUSABLE_STATUSES, true)
            && $this->condition !== 'damaged';
    }

    public function archivedBy()
    {
        return $this->belongsTo(User::class, 'archived_by', 'user_id');
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
