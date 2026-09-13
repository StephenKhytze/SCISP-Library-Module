<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A book category in the managed vocabulary.
 *
 * `normalized_name` is derived, never supplied: it is what the unique index
 * protects, so it is written by the model rather than trusted from a caller.
 */
class LibraryCategory extends Model
{
    protected $table = 'library_categories';
    protected $primaryKey = 'category_id';

    protected $fillable = ['name', 'is_active', 'created_by'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Whatever route a category is created or renamed through, the
        // normalised form follows the display name automatically.
        static::saving(function (self $category) {
            $category->name = self::tidy($category->name);
            $category->normalized_name = self::normalize($category->name);
        });
    }

    /**
     * The comparison form: trimmed, internal whitespace runs collapsed to one
     * space, lowercased.
     *
     * "Computer Science", "computer science" and "  Computer   Science  " all
     * reduce to "computer science", so only one of them can exist.
     */
    public static function normalize(?string $value): string
    {
        return mb_strtolower(self::tidy($value));
    }

    /** The display form: trimmed, internal whitespace collapsed, case kept. */
    public static function tidy(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
