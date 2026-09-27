<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shelf audits: a librarian walks the shelves and records what they actually
 * find, copy by copy, identified by accession number.
 *
 * The items table is a SNAPSHOT taken when the audit starts. Copies added
 * afterwards do not appear in it, so a long audit is not distorted by ongoing
 * acquisitions — and `expected_location` records where the copy was supposed to
 * be at that moment, not where it is now.
 *
 * An audit is an OBSERVATION. Nothing here changes availability_status,
 * condition, loans, holds, fines or reserves. Marking a copy MISSING does not
 * make it lost; a librarian must decide that separately.
 */
return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('inventory_audit_items');
        Schema::dropIfExists('inventory_audits');
    }
};
