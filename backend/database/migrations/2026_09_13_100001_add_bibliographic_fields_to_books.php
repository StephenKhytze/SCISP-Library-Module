<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Edition / publisher / publication year / cover, plus a normalised ISBN.
 *
 * ISBN identifies the TITLE AND EDITION. The accession number identifies the
 * exact physical copy. Those are different questions and now have different
 * columns.
 *
 * `isbn_normalized` exists because "978-0-13-235088-4" and "9780132350884" are
 * the same book. The display value in `isbn` is left exactly as the librarian
 * typed it; only the comparison key is derived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('edition', 100)->nullable()->after('author');
            $table->string('publisher', 255)->nullable()->after('edition');
            $table->unsignedSmallInteger('publication_year')->nullable()->after('publisher');

            // Path relative to the public disk, never the image bytes.
            $table->string('cover_image_path', 255)->nullable()->after('publication_year');

            $table->string('isbn_normalized', 64)->nullable()->after('isbn');
        });

        // Backfill the comparison key. `isbn` itself is NOT rewritten.
        foreach (DB::table('books')->select('book_id', 'isbn')->get() as $book) {
            $normalized = preg_replace('/[^0-9A-Z]/', '', strtoupper((string) $book->isbn));

            DB::table('books')
                ->where('book_id', $book->book_id)
                ->update(['isbn_normalized' => $normalized === '' ? null : $normalized]);
        }

        Schema::table('books', function (Blueprint $table) {
            // Unique, but nullable — a title with no ISBN falls back to the
            // application-level title/author/edition/publisher check.
            $table->unique('isbn_normalized');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropUnique(['isbn_normalized']);
            $table->dropColumn([
                'edition',
                'publisher',
                'publication_year',
                'cover_image_path',
                'isbn_normalized',
            ]);
        });
    }
};
