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
        Schema::table('course_reserves', function (Blueprint $table) {
            $table->dropColumn(['course', 'title', 'type', 'note']);
            
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('book_id')->nullable();
            $table->integer('copies_requested')->default(1);
            $table->string('target_group')->nullable();
            $table->text('teacher_to_admin_note')->nullable();
            $table->text('admin_to_teacher_note')->nullable();
            $table->text('teacher_to_student_note')->nullable();

            $table->foreign('section_id')->references('section_id')->on('course_sections')->onDelete('cascade');
            $table->foreign('book_id')->references('book_id')->on('books')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_reserves', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            $table->dropForeign(['book_id']);
            
            $table->dropColumn([
                'section_id', 'book_id', 'copies_requested', 'target_group', 
                'teacher_to_admin_note', 'admin_to_teacher_note', 'teacher_to_student_note'
            ]);
            
            $table->string('course')->nullable();
            $table->string('title')->nullable();
            $table->string('type')->nullable();
            $table->text('note')->nullable();
        });
    }
};
