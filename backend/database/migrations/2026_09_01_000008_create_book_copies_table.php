<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('book_copies', function (Blueprint $table) {
            $table->id('copy_id');
            $table->string('accession_number', 32)->nullable()->unique()->comment('Human-readable copy label, e.g. ABC-LIB-000001.');
            $table->unsignedBigInteger('book_id');
            $table->enum('condition', ['new','good','fair','poor','damaged'])->default('good');
            $table->enum('availability_status', ['available','checked_out','on_hold','lost','damaged'])->default('available');
            $table->unsignedBigInteger('reserve_id')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->text('archive_reason')->nullable();
            $table->timestamps();
            $table->foreign('book_id')->references('book_id')->on('books')->onDelete('cascade');
            $table->foreign('reserve_id')->references('reserve_id')->on('course_reserves')->onDelete('set null');
            $table->foreign('archived_by')->references('user_id')->on('users')->onDelete('set null');
        });
    }
    public function down(): void { Schema::dropIfExists('book_copies'); }
};