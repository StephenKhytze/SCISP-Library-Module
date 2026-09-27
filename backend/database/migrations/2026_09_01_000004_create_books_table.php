<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('books', function (Blueprint $table) {
            $table->id('book_id');
            $table->string('book_title');
            $table->string('author');
            $table->string('edition', 100)->nullable();
            $table->string('publisher')->nullable();
            $table->unsignedSmallInteger('publication_year')->nullable();
            $table->string('cover_image_path')->nullable();
            $table->string('category');
            $table->string('isbn')->nullable()->unique();
            $table->string('isbn_normalized', 64)->nullable()->unique();
            $table->string('physical_location');
            $table->integer('total_copies')->default(0);
            $table->timestamp('archived_at')->nullable()->index();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->string('archive_reason')->nullable();
            $table->timestamps();
            $table->foreign('archived_by')->references('user_id')->on('users')->onDelete('set null');
        });
    }
    public function down(): void { Schema::dropIfExists('books'); }
};