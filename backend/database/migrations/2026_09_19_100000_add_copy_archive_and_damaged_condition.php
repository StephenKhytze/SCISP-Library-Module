<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Individual copy archive, and `damaged` as a physical condition.
 *
 * 1. Archive is a lifecycle state, kept apart from condition and availability.
 *    An archived copy keeps its accession number, its condition, its
 *    availability status and its loan history — archiving and restoring
 *    touch nothing but these three columns. That is what makes restore safe:
 *    a damaged copy comes back damaged, a lost one comes back lost.
 *
 * 2. `condition` gains `damaged`. The rule that a damaged copy is also
 *    unavailable is enforced in InventoryService, not here — the database
 *    only has to be able to store the value.
 *
 * Existing rows are not rewritten. In particular a copy already recorded as
 * condition=new / availability=damaged stays exactly as it is; that is a
 * librarian's call, not a migration's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_copies', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('reserve_id');
            $table->foreignId('archived_by')
                ->nullable()
                ->after('archived_at')
                ->constrained('users', 'user_id')
                ->nullOnDelete();
            $table->text('archive_reason')->nullable()->after('archived_by');

            $table->index('archived_at');
        });

        // Widening an enum only adds an allowed value; every existing value is
        // still valid, so no row changes. SQLite rebuilds the table for the
        // CHECK constraint; MySQL modifies the column in place.
        Schema::table('book_copies', function (Blueprint $table) {
            $table->enum('condition', ['new', 'good', 'fair', 'poor', 'damaged'])
                ->default('good')
                ->change();
        });
    }

    public function down(): void
    {
        // `damaged` has no place in the narrower enum. Map it to `poor`, the
        // nearest remaining value; availability_status is left alone, so a
        // damaged copy stays unavailable after the rollback.
        DB::table('book_copies')->where('condition', 'damaged')->update(['condition' => 'poor']);

        Schema::table('book_copies', function (Blueprint $table) {
            $table->enum('condition', ['new', 'good', 'fair', 'poor'])
                ->default('good')
                ->change();
        });

        Schema::table('book_copies', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['archived_at', 'archive_reason']);
        });
    }
};
