<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renewal now needs librarian approval, so a borrower's request has to survive
 * between the asking and the decision. Nothing existing can carry that:
 * `transactions` records the loan itself and has no request concept, and
 * `holds` is about waiting for a copy, not extending one already borrowed.
 *
 * Deliberately flat — one row per request, three states, no workflow engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_requests', function (Blueprint $table) {
            $table->id('renewal_request_id');

            $table->foreignId('transaction_id')
                ->constrained('transactions', 'transaction_id')
                ->cascadeOnDelete();

            // The borrower who asked. Denormalised from the transaction so an
            // ownership check never needs a join.
            $table->foreignId('user_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->enum('status', ['pending', 'approved', 'denied'])->default('pending');

            // Who decided, and when. Null while pending.
            $table->foreignId('decided_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            // The due date the approval produced, for the audit trail.
            $table->dateTime('new_due_date')->nullable();

            // Why a denial happened, shown back to the borrower.
            $table->string('decision_note')->nullable();

            $table->timestamps();

            // "One pending request per loan" is enforced in the service under a
            // row lock; this index keeps the lookup cheap.
            $table->index(['transaction_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_requests');
    }
};
