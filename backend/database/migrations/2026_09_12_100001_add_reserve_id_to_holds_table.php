<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scopes a hold to a single course reserve.
 *
 * Course-reserve requests need their own queue: a student waiting for
 * "Clean Code" as allocated to Section A must not be served a general-
 * circulation copy, and must not sit in the title-wide queue. Rather than add a
 * second queue table, the existing holds machinery is reused — reserve_id NULL
 * means a general hold (today's behaviour, unchanged), and a set reserve_id
 * means the hold belongs to that reserve's own queue.
 *
 * Nullable and additive: every existing hold row stays a general hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holds', function (Blueprint $table) {
            $table->foreignId('reserve_id')
                ->nullable()
                ->after('copy_id')
                ->constrained('course_reserves', 'reserve_id')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('holds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reserve_id');
        });
    }
};
