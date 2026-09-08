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
        Schema::table('holds', function (Blueprint $table) {
            $table->unsignedBigInteger('copy_id')->nullable()->after('book_id');
            $table->foreign('copy_id')->references('copy_id')->on('book_copies')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('holds', function (Blueprint $table) {
            $table->dropForeign(['copy_id']);
            $table->dropColumn('copy_id');
        });
    }
};
