<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('book_copies', function (Blueprint $table) {
            $table->unsignedBigInteger('reserve_id')->nullable();
            $table->foreign('reserve_id')->references('reserve_id')->on('course_reserves')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('book_copies', function (Blueprint $table) {
            $table->dropForeign(['reserve_id']);
            $table->dropColumn('reserve_id');
        });
    }
};
