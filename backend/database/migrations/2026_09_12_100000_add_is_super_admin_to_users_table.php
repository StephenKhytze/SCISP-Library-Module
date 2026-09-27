<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes Super Admin from Admin in stored data.
 *
 * WHY A SCHEMA CHANGE WAS UNAVOIDABLE
 * -----------------------------------
 * Both personas store users.role = 'administrator'; only the X-Mock-Role header
 * tells them apart, and the header describes the CALLER. The rule we have to
 * enforce ("Super Admin cannot borrow") is about the BORROWER, and on
 * POST /library/checkout the borrower arrives as a user_id — they send no
 * header at all. There is therefore no request-side signal that can answer
 * "is user 11 a Super Admin?", so the distinction has to be stored.
 *
 * WHY A COLUMN RATHER THAN A NEW ROLE ENUM VALUE
 * ----------------------------------------------
 * `users` is shared with the other SCISP modules. Adding 'super_admin' to the
 * role enum would silently change the meaning of every existing
 * `role === 'administrator'` comparison across the whole system — Super Admins
 * would quietly stop matching administrator checks they match today. This
 * column is purely additive: every existing query keeps its current result, and
 * only code that opts in sees the distinction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')
                ->default(false)
                ->after('role')
                ->comment('Library: management-only administrator who may not borrow.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });
    }
};
