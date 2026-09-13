<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational settings, and a record of who changed them.
 *
 * Key/value rather than one column per rule: the set of rules is expected to
 * grow, and a wide table would need a migration every time. `type` tells the
 * service how to cast the stored string back.
 *
 * Defaults are NOT written here — they live in LibrarySettingsService, so a
 * fresh database and an untouched setting behave identically, and a row only
 * appears once someone has actually changed something.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // int | float | bool | string
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();
            $table->timestamps();
        });

        // Deliberately a settings-change log, not a system-wide audit trail.
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
    }

    public function down(): void
    {
        Schema::dropIfExists('library_setting_history');
        Schema::dropIfExists('library_settings');
    }
};
