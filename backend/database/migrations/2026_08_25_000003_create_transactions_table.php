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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id('transaction_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('copy_id');
            $table->dateTime('date_borrowed');
            $table->dateTime('due_date');
            $table->dateTime('actual_return_date')->nullable();
            $table->enum('status', ['active', 'returned', 'overdue'])->default('active');
            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            $table->foreign('copy_id')->references('copy_id')->on('book_copies')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
