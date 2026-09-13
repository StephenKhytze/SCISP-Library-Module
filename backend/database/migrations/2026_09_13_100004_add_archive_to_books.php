<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archive replaces delete for titles.
 *
 * A book that has ever been borrowed is referenced by transactions, fines and
 * course reserves. Deleting it would either break that history or cascade it
 * away. Archiving hides the title from borrowers and stops new activity while
 * leaving every historical row readable.
 *
 * Deliberately not Laravel's SoftDeletes: `deleted_at` implies the row is gone,
 * and every existing query would silently start excluding archived titles —
 * including the admin screens that must still show them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('total_copies');
            $table->foreignId('archived_by')
                ->nullable()
                ->after('archived_at')
                ->constrained('users', 'user_id')
                ->nullOnDelete();
            $table->string('archive_reason', 255)->nullable()->after('archived_by');

            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['archived_at', 'archive_reason']);
        });
    }
};
