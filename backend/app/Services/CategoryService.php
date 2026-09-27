<?php

namespace App\Services;

use App\Models\Book;
use App\Models\LibraryCategory;
use Illuminate\Support\Facades\DB;

/**
 * The book category vocabulary.
 *
 * Two entry points matter and they behave differently on purpose:
 *
 *   create()  — a librarian deliberately adding a category. A duplicate is an
 *               error worth telling them about (409).
 *   resolve() — saving a book. A name that matches an existing category, in any
 *               casing or spacing, is folded onto that category's canonical
 *               spelling. This is what stops the catalog growing three
 *               variations of "Computer Science".
 */
class CategoryService
{
    /**
     * Register a new category.
     *
     * @throws DuplicateCategoryException when one already exists by normalised name
     */
    public function create(string $name, ?int $userId = null): LibraryCategory
    {
        $tidy = LibraryCategory::tidy($name);
        $normalized = LibraryCategory::normalize($name);

        if ($normalized === '') {
            throw new \InvalidArgumentException('Category name cannot be empty.');
        }

        // Locked so two librarians pressing Add Category at the same instant
        // cannot both pass the existence check. The unique index is the backstop.
        return DB::transaction(function () use ($tidy, $normalized, $userId) {
            $existing = LibraryCategory::where('normalized_name', $normalized)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new DuplicateCategoryException($existing);
            }

            return LibraryCategory::create([
                'name' => $tidy,
                'is_active' => true,
                'created_by' => $userId,
            ]);
        });
    }

    /** Rename a category, keeping its identity and every book pointing at it. */
    public function rename(LibraryCategory $category, string $name, ?int $userId = null): LibraryCategory
    {
        $tidy = LibraryCategory::tidy($name);
        $normalized = LibraryCategory::normalize($name);

        if ($normalized === '') {
            throw new \InvalidArgumentException('Category name cannot be empty.');
        }

        $clash = LibraryCategory::where('normalized_name', $normalized)
            ->where('category_id', '!=', $category->category_id)
            ->first();

        if ($clash) {
            throw new DuplicateCategoryException($clash);
        }

        $previous = $category->name;
        $category->name = $tidy;
        $category->save();

        // Books store the category as a string, so a rename has to carry
        // across or those books would point at a name that no longer exists.
        if ($previous !== $category->name) {
            Book::where('category', $previous)->update(['category' => $category->name]);
        }

        return $category;
    }

    /**
     * The canonical name to store on a book.
     *
     * An unknown category is registered rather than rejected: a librarian
     * saving a book should never be blocked by the vocabulary being incomplete,
     * and registering it keeps the list honest about what the catalog contains.
     */
    public function resolve(?string $name, ?int $userId = null): ?string
    {
        $tidy = LibraryCategory::tidy($name);

        if ($tidy === '') {
            return null;
        }

        $normalized = LibraryCategory::normalize($tidy);

        $existing = LibraryCategory::where('normalized_name', $normalized)->first();

        if ($existing) {
            return $existing->name;
        }

        try {
            return $this->create($tidy, $userId)->name;
        } catch (DuplicateCategoryException $e) {
            // Lost a race with a concurrent create; its row is the answer.
            return $e->category->name;
        }
    }

    /**
     * Categories for a dropdown.
     *
     * The union with categories still present on books is defensive: if a
     * category were ever deactivated while books still used it, those books
     * would otherwise become unreachable through the filter.
     */
    public function listNames(): array
    {
        $managed = LibraryCategory::active()->orderBy('name')->pluck('name');

        $inUse = Book::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->pluck('category');

        return $managed->concat($inUse)
            ->unique(fn ($name) => LibraryCategory::normalize($name))
            ->sort(fn ($a, $b) => strcasecmp($a, $b))
            ->values()
            ->all();
    }

    /** Full records, for management screens. */
    public function listAll()
    {
        return LibraryCategory::active()->orderBy('name')->get();
    }
}

/** Thrown when a category name is already taken, ignoring case and spacing. */
class DuplicateCategoryException extends \RuntimeException
{
    public LibraryCategory $category;

    public function __construct(LibraryCategory $category)
    {
        parent::__construct('This category already exists.');
        $this->category = $category;
    }
}
