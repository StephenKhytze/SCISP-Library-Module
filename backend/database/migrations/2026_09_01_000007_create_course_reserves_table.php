<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('course_reserves', function (Blueprint $table) {
            $table->id('reserve_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('Active Reserve');
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('book_id')->nullable();
            $table->integer('copies_requested')->default(1);
            $table->string('target_group')->nullable();
            $table->text('teacher_to_admin_note')->nullable();
            $table->text('admin_to_teacher_note')->nullable();
            $table->text('teacher_to_student_note')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('user_id')->on('users');
            $table->foreign('section_id')->references('section_id')->on('course_sections')->onDelete('cascade');
            $table->foreign('book_id')->references('book_id')->on('books')->onDelete('cascade');
        });
    }
    public function down(): void { Schema::dropIfExists('course_reserves'); }
};