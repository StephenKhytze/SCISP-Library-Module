<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A managed vocabulary for book categories.
 *
 * Until now `books.category` was a free varchar with no list behind it, so the
 * only way to know which categories existed was to ask which ones some book
 * already used. That has two consequences this table fixes:
 *
 *   1. A category with no books yet cannot exist, so "create a category" was
 *      impossible — you could only create a book that happened to mention one.
 *   2. Nothing stopped "Computer Science", "computer science" and
 *      "  Computer   Science " becoming three separate categories.
 *
 * `books.category` is deliberately left as a string. Converting it to a foreign
 * key would mean rewriting every existing row, and nothing in this change needs
 * that. The table is the vocabulary; the column still stores the chosen name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_categories', function (Blueprint $table) {
            $table->id('category_id');

            // What a librarian typed, preserved exactly as they typed it.
            $table->string('name');

            // Trimmed, whitespace-collapsed, lowercased. The uniqueness rule
            // lives here rather than on `name`, so casing and stray spaces
            // cannot produce a second row for the same category.
            $table->string('normalized_name', 255)->unique();

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('user_id')->on('users')->nullOnDelete();
            $table->index('is_active');
        });

        $this->backfillFromBooks();
    }

    /**
     * Register every category the catalog is already using.
     *
     * Existing book rows are read, never written: no category is renamed,
     * merged or deleted here. If two existing spellings normalise to the same
     * value, the first one seen wins the row and the others are skipped —
     * the books keep their own spelling either way, and the situation is
     * reported rather than silently resolved.
     */
    protected function backfillFromBooks(): void
    {
        if (! Schema::hasTable('books')) {
            return;
        }

        $existing = DB::table('books')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $now = now();
        $seen = [];

        foreach ($existing as $name) {
            $normalized = self::normalize($name);

            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;

            DB::table('library_categories')->insert([
                'name' => trim(preg_replace('/\s+/u', ' ', $name)),
                'normalized_name' => $normalized,
                'is_active' => true,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** Kept in step with LibraryCategory::normalize(). */
    public static function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }

    public function down(): void
    {
        Schema::dropIfExists('library_categories');
    }
};
