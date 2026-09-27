<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('holds', function (Blueprint $table) {
            $table->id('hold_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('book_id');
            $table->unsignedBigInteger('copy_id')->nullable();
            $table->unsignedBigInteger('reserve_id')->nullable();
            $table->dateTime('request_date');
            $table->enum('status', ['pending','pending_approval','fulfilled','cancelled','expired'])->default('pending');
            $table->integer('queue_position');
            $table->timestamps();
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            $table->foreign('book_id')->references('book_id')->on('books')->onDelete('cascade');
            $table->foreign('copy_id')->references('copy_id')->on('book_copies')->onDelete('set null');
            $table->foreign('reserve_id')->references('reserve_id')->on('course_reserves')->onDelete('set null');
        });
    }
    public function down(): void { Schema::dropIfExists('holds'); }
};