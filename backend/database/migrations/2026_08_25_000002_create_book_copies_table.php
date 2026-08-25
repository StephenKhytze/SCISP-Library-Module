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
        Schema::create('book_copies', function (Blueprint $table) {
            $table->id('copy_id');
            $table->unsignedBigInteger('book_id');
            $table->enum('condition', ['new', 'good', 'fair', 'poor'])->default('good');
            $table->enum('availability_status', ['available', 'checked_out', 'on_hold', 'lost', 'damaged'])->default('available');
            $table->timestamps();

            $table->foreign('book_id')->references('book_id')->on('books')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_copies');
    }
};
