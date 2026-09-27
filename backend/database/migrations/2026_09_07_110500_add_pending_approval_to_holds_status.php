<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For SQLite, modifying ENUMs is not natively supported by Schema builder in Laravel < 11
        // without dropping the table. Since SQLite doesn't enforce enum constraints strictly,
        // we can leave the schema as is, or redefine the column as a string for safety.
        // For production (MySQL/PostgreSQL), we would alter the enum.
        
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE holds MODIFY COLUMN status ENUM('pending', 'pending_approval', 'fulfilled', 'cancelled', 'expired') DEFAULT 'pending'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE holds MODIFY COLUMN status ENUM('pending', 'fulfilled', 'cancelled', 'expired') DEFAULT 'pending'");
        }
    }
};
