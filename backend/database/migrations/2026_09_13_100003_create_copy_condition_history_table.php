<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change to a physical copy's condition, newest first when read.
 *
 * `book_copies.condition` keeps the current value; this is the trail behind it.
 * Rows are written once and never rewritten — a later Good → Fair must not
 * alter the earlier New → Good record.
 *
 * The condition values are the existing enum (new, good, fair, poor); no new
 * vocabulary is introduced. They are stored as plain strings so a historical
 * row survives a future enum change.
 */
return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('copy_condition_history');
    }
};
