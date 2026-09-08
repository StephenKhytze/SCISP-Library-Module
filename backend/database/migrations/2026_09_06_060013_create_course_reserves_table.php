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
        Schema::create('course_reserves', function (Blueprint $table) {
            $table->id('reserve_id');
            $table->string('course');
            $table->string('title');
            $table->string('type');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('Active Reserve');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_reserves');
    }
};
