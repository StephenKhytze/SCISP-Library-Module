<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the Library audit / history subsystem.
 *
 * The module keeps only CURRENT state for these three concerns:
 *
 *   - `library_settings`     the current policy values (no change log)
 *   - `book_copies.condition` the copy's current physical condition (no trail)
 *   - inventory audits        removed entirely
 *
 * What this does NOT touch, deliberately: `transactions` remains the
 * authoritative borrowing and return history, and the Circulation Desk's
 * Active / Returned / All History views are unaffected. Nothing here reads or
 * writes that table.
 *
 * The earlier migrations that created these tables are left exactly as they
 * were — history is not rewritten, it is superseded.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dropped child-first so foreign keys never block the drop:
        // inventory_audit_items -> inventory_audits.
        Schema::dropIfExists('inventory_audit_items');
        Schema::dropIfExists('inventory_audits');
        Schema::dropIfExists('copy_condition_history');
        Schema::dropIfExists('library_setting_history');
    }

    /**
     * Recreate the tables as they were defined, so a rollback restores a
     * working schema. Structure only — the rows are gone for good, which is
     * the point of the removal.
     */
    public function down(): void
    {
        Schema::create('library_setting_history', function (Blueprint $table) {
            $table->id('history_id');
            $table->string('key', 100);
            $table->text('previous_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['key', 'changed_at']);
        });

        Schema::create('copy_condition_history', function (Blueprint $table) {
            $table->id('history_id');

            $table->foreignId('copy_id')
                ->constrained('book_copies', 'copy_id')
                ->cascadeOnDelete();

            $table->string('previous_condition', 20)->nullable();
            $table->string('new_condition', 20);

            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            $table->string('note', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['copy_id', 'history_id']);
        });

        Schema::create('inventory_audits', function (Blueprint $table) {
            $table->id('audit_id');
            $table->string('name', 255)->nullable();
            $table->foreignId('started_by')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 20)->default('in_progress'); // in_progress | completed | cancelled
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('inventory_audit_items', function (Blueprint $table) {
            $table->id('item_id');

            $table->foreignId('audit_id')
                ->constrained('inventory_audits', 'audit_id')
                ->cascadeOnDelete();

            $table->foreignId('copy_id')
                ->constrained('book_copies', 'copy_id')
                ->cascadeOnDelete();

            // Copied at snapshot time so the expectation is frozen.
            $table->string('accession_number', 32)->nullable();
            $table->string('expected_location', 255)->nullable();

            $table->string('observed_location', 255)->nullable();
            $table->string('observed_condition', 20)->nullable();

            // pending | found | missing | damaged | wrong_shelf
            $table->string('audit_status', 20)->default('pending');

            $table->string('notes', 255)->nullable();

            $table->foreignId('checked_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();
            $table->timestamp('checked_at')->nullable();

            $table->timestamps();

            // One row per copy per audit, and a cheap status roll-up.
            $table->unique(['audit_id', 'copy_id']);
            $table->index(['audit_id', 'audit_status']);
        });
    }
};
