<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow a title with no ISBN.
 *
 * Not every book has one — older stock, local publications, bound theses — and
 * the duplicate rules already define what to do without one: fall back to an
 * exact match on title, author, edition and publisher.
 *
 * This is the one column MODIFICATION in this pass rather than an addition, so
 * to be explicit about what it does and does not do:
 *
 *   - It RELAXES a constraint (NOT NULL -> NULL). No existing row changes, and
 *     no existing value is touched or converted.
 *   - Anything that could already be stored can still be stored.
 *   - The unique index on `isbn` stays. MySQL and SQLite both allow multiple
 *     NULLs in a unique index, so ISBN-less titles do not collide with each
 *     other.
 *
 * The down() path restores NOT NULL, which would fail if any ISBN-less title
 * exists by then — correctly, because there would be no value to put there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('isbn', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('isbn', 255)->nullable(false)->change();
        });
    }
};
