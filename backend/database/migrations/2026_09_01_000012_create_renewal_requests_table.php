<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('renewal_requests', function (Blueprint $table) {
            $table->id('renewal_request_id');
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('status', ['pending','approved','denied'])->default('pending');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->dateTime('new_due_date')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestamps();
            $table->index(['transaction_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->foreign('transaction_id')->references('transaction_id')->on('transactions')->onDelete('cascade');
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            $table->foreign('decided_by')->references('user_id')->on('users')->onDelete('set null');
        });
    }
    public function down(): void { Schema::dropIfExists('renewal_requests'); }
};