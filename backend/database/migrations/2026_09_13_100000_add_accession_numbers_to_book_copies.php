<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Human-readable accession numbers for physical copies: ABC-LIB-000001.
 *
 * copy_id stays the primary key — the accession number is the label a librarian
 * reads off a shelf, not a new identity. It is generated server-side, unique,
 * and normally immutable once assigned.
 *
 * `library_counters` exists so two librarians adding copies at the same moment
 * cannot be handed the same number: the counter row is locked for update inside
 * the same transaction that creates the copy. MAX()+1 would race.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_counters', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
        });

        Schema::table('book_copies', function (Blueprint $table) {
            // Nullable so this migration can add the column before backfilling,
            // and so the unique index tolerates the gap while it runs.
            $table->string('accession_number', 32)
                ->nullable()
                ->after('copy_id')
                ->comment('Human-readable copy label, e.g. ABC-LIB-000001.');

            $table->unique('accession_number');
        });

        // Backfill deterministically by copy_id, so re-running on a clone
        // produces the same numbers.
        $copies = DB::table('book_copies')->orderBy('copy_id')->pluck('copy_id');
        $sequence = 0;

        foreach ($copies as $copyId) {
            $sequence++;

            DB::table('book_copies')
                ->where('copy_id', $copyId)
                ->update(['accession_number' => 'ABC-LIB-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT)]);
        }

        DB::table('library_counters')->insert([
            'name' => 'accession_number',
            'value' => $sequence,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('book_copies', function (Blueprint $table) {
            $table->dropUnique(['accession_number']);
            $table->dropColumn('accession_number');
        });

        Schema::dropIfExists('library_counters');
    }
};
