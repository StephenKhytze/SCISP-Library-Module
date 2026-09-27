<?php

/**
 * Simplified Library completion — Batch 2: overdue visibility and fine settlement.
 *
 * Confirmed rules under test:
 *   - overdue is DERIVED while a book is out; nothing is written to the DB
 *   - users.total_fines is the authoritative balance
 *   - Paid and Waived are distinct actions that both reduce the balance
 *   - partial settlement is allowed; the balance never goes below zero
 *   - borrowers see only their own balance
 */

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function finesAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function finesUser(string $username, string $dbRole, float $balance = 0): User
{
    return User::create([
        'username' => $username,
        'password' => 'password',
        'role' => $dbRole,
        'status' => 'active',
        'total_fines' => $balance,
    ]);
}

function finesLoan(User $user, int $dueInDays): Transaction
{
    $book = Book::create([
        'book_title' => 'T', 'author' => 'A', 'category' => 'CS',
        'isbn' => 'ISBN-'.uniqid(), 'physical_location' => 'S1', 'total_copies' => 0,
    ]);

    $copy = BookCopy::create([
        'book_id' => $book->book_id,
        'condition' => 'good',
        'availability_status' => 'checked_out',
    ]);

    return Transaction::create([
        'user_id' => $user->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDays(10),
        'due_date' => now()->addDays($dueInDays),
        'status' => 'active',
    ]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00'));

    $this->student = finesUser('fine_student', 'student');
    $this->admin = finesUser('fine_admin', 'administrator');
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Overdue is derived, not stored
|--------------------------------------------------------------------------
*/

test('F1: an overdue active loan is flagged with days and an estimated fine', function () {
    $loan = finesLoan($this->student, -3); // due 3 days ago

    $this->withHeaders(finesAs('Student', 'fine_student'))
        ->getJson('/api/library/loans/me')
        ->assertStatus(200)
        ->assertJsonPath('0.is_overdue', true)
        ->assertJsonPath('0.days_overdue', 3)
        ->assertJsonPath('0.estimated_fine', 30);

    // Nothing was written while the book is still out.
    expect((float) $this->student->fresh()->total_fines)->toBe(0.00);
    expect($loan->fresh()->status)->toBe('active');
});

test('F2: a loan that is not yet due is not flagged', function () {
    finesLoan($this->student, 4);

    $this->withHeaders(finesAs('Student', 'fine_student'))
        ->getJson('/api/library/loans/me')
        ->assertStatus(200)
        ->assertJsonPath('0.is_overdue', false)
        ->assertJsonPath('0.days_overdue', 0)
        ->assertJsonPath('0.estimated_fine', 0);
});

/*
|--------------------------------------------------------------------------
| Own balance
|--------------------------------------------------------------------------
*/

test('F3: a borrower can read their own balance', function () {
    $this->student->update(['total_fines' => 45.50]);

    $this->withHeaders(finesAs('Student', 'fine_student'))
        ->getJson('/api/library/fines/me')
        ->assertStatus(200)
        ->assertJsonPath('total_fines', 45.5)
        ->assertJsonPath('daily_rate', 10);
});

test('F4: the self summary reports balance, active loans and the role limit', function () {
    finesLoan($this->student, -1);
    $this->student->update(['total_fines' => 20]);

    $this->withHeaders(finesAs('Student', 'fine_student'))
        ->getJson('/api/library/me/summary')
        ->assertStatus(200)
        ->assertJsonPath('total_fines', 20)
        ->assertJsonPath('active_loans', 1)
        ->assertJsonPath('overdue_loans', 1)
        ->assertJsonPath('borrow_limit', 3)
        ->assertJsonPath('role', 'student');
});

test('F5: a librarian summary reports no borrowing limit', function () {
    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->getJson('/api/library/me/summary')
        ->assertStatus(200)
        ->assertJsonPath('borrow_limit', null);
});

/*
|--------------------------------------------------------------------------
| Admin fines list
|--------------------------------------------------------------------------
*/

test('F6: admin sees everyone who owes money', function () {
    $this->student->update(['total_fines' => 30]);
    finesUser('fine_clean', 'student', 0);

    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->getJson('/api/library/fines')
        ->assertStatus(200)
        ->assertJsonPath('total_outstanding', 30)
        ->assertJsonCount(1, 'debtors');
});

test('F7: a student cannot read the admin fines list', function () {
    $this->withHeaders(finesAs('Student', 'fine_student'))
        ->getJson('/api/library/fines')
        ->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| Settlement — Paid and Waived
|--------------------------------------------------------------------------
*/

test('F8: partial payment reduces the balance', function () {
    $this->student->update(['total_fines' => 100]);

    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id,
            'amount' => 40,
            'type' => 'paid',
        ])
        ->assertStatus(200)
        ->assertJsonPath('settlement.new_balance', 60);

    expect((float) $this->student->fresh()->total_fines)->toBe(60.00);
});

test('F9: waiving is a distinct action that also reduces the balance', function () {
    $this->student->update(['total_fines' => 100]);

    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id,
            'amount' => 100,
            'type' => 'waived',
        ])
        ->assertStatus(200)
        ->assertJsonPath('settlement.type', 'waived')
        ->assertJsonPath('settlement.new_balance', 0);

    expect((float) $this->student->fresh()->total_fines)->toBe(0.00);
});

test('F10: settling more than owed never drives the balance below zero', function () {
    $this->student->update(['total_fines' => 25]);

    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id,
            'amount' => 999,
            'type' => 'paid',
        ])
        ->assertStatus(200)
        ->assertJsonPath('settlement.applied_amount', 25)
        ->assertJsonPath('settlement.new_balance', 0);

    expect((float) $this->student->fresh()->total_fines)->toBe(0.00);
});

test('F11: settling a zero balance is rejected', function () {
    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id,
            'amount' => 10,
            'type' => 'paid',
        ])
        ->assertStatus(422);
});

test('F12: an invalid settlement type is rejected', function () {
    $this->student->update(['total_fines' => 50]);

    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id,
            'amount' => 10,
            'type' => 'forgiven',
        ])
        ->assertStatus(422);
});

test('F13: a student cannot settle fines', function () {
    $this->student->update(['total_fines' => 50]);

    $this->withHeaders(finesAs('Student', 'fine_student'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id,
            'amount' => 50,
            'type' => 'waived',
        ])
        ->assertStatus(403);

    expect((float) $this->student->fresh()->total_fines)->toBe(50.00);
});

test('F14: settling unblocks checkout', function () {
    $this->student->update(['total_fines' => 50]);
    $book = Book::create([
        'book_title' => 'T2', 'author' => 'A', 'category' => 'CS',
        'isbn' => 'ISBN-'.uniqid(), 'physical_location' => 'S1', 'total_copies' => 0,
    ]);
    $copy = BookCopy::create(['book_id' => $book->book_id, 'condition' => 'good', 'availability_status' => 'available']);

    // Blocked while owing.
    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->student->user_id, 'copy_id' => $copy->copy_id])
        ->assertStatus(422);

    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/fines/settle', [
            'user_id' => $this->student->user_id, 'amount' => 50, 'type' => 'waived',
        ])->assertStatus(200);

    // Allowed once settled.
    $this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->student->user_id, 'copy_id' => $copy->copy_id])
        ->assertStatus(201);
});

/*
|--------------------------------------------------------------------------
| F-1 / F-4: the BORROWER's role decides the rate, grace and cap
|--------------------------------------------------------------------------
|
| A faculty loan used to be checked in at the student rate with no grace and
| the student cap, so the borrower was charged something other than the
| estimate their own screen had shown them.
*/

/** Apply fine policy settings as an admin. */
function finesPolicy($test, array $settings): void
{
    $test->withHeaders(finesAs('Admin', 'fine_admin'))
        ->putJson('/api/library/settings', ['settings' => $settings])
        ->assertOk();
}

function finesCheckin($test, Transaction $loan)
{
    return $test->withHeaders(finesAs('Admin', 'fine_admin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])
        ->assertOk();
}

test('F15: a faculty loan is charged the faculty rate and grace, not the student one', function () {
    finesPolicy($this, [
        'student_fine_per_day' => 10, 'student_grace_days' => 0,
        'faculty_fine_per_day' => 2, 'faculty_grace_days' => 3,
    ]);

    $faculty = finesUser('fine_faculty', 'faculty');
    $loan = finesLoan($faculty, -5); // five days overdue

    finesCheckin($this, $loan);

    // 5 days late, 3 forgiven, 2 chargeable at PHP 2.
    expect((float) $faculty->fresh()->total_fines)->toBe(4.00);
});

test('F16: the student policy still applies to a student loan', function () {
    finesPolicy($this, [
        'student_fine_per_day' => 10, 'student_grace_days' => 0,
        'faculty_fine_per_day' => 2, 'faculty_grace_days' => 3,
    ]);

    $loan = finesLoan($this->student, -5);

    finesCheckin($this, $loan);

    expect((float) $this->student->fresh()->total_fines)->toBe(50.00);
});

test('F17: the faculty grace period can forgive the fine entirely', function () {
    finesPolicy($this, ['faculty_fine_per_day' => 2, 'faculty_grace_days' => 3]);

    $faculty = finesUser('fine_faculty2', 'faculty');
    $loan = finesLoan($faculty, -2);

    finesCheckin($this, $loan);

    expect((float) $faculty->fresh()->total_fines)->toBe(0.00);
});

test('F18: the faculty cap limits a single faculty loan', function () {
    finesPolicy($this, [
        'faculty_fine_per_day' => 10, 'faculty_grace_days' => 0, 'faculty_max_fine' => 25,
        'student_fine_per_day' => 10, 'student_max_fine' => 500,
    ]);

    $faculty = finesUser('fine_faculty3', 'faculty');
    $loan = finesLoan($faculty, -10);

    finesCheckin($this, $loan);

    expect((float) $faculty->fresh()->total_fines)->toBe(25.00);
});

test('F19: the amount charged follows the borrower, whoever runs the desk', function () {
    finesPolicy($this, [
        'student_fine_per_day' => 10, 'student_grace_days' => 0,
        'faculty_fine_per_day' => 2, 'faculty_grace_days' => 3,
    ]);

    $faculty = finesUser('fine_faculty4', 'faculty');
    $loan = finesLoan($faculty, -5);

    // The desk operator here is an Admin, whose own policy would be the
    // student one. The borrower's policy is what counts.
    finesCheckin($this, $loan);

    expect((float) $faculty->fresh()->total_fines)->toBe(4.00);

    // NOTE: Transaction::estimated_fine still reads the student policy unless
    // the `user` relation was eager-loaded (finding F-8, deliberately left
    // out of this pass), so the borrowing screen can still quote 50.00 here.
});

test('F20: a recorded balance is not re-rated when the policy changes later', function () {
    finesPolicy($this, ['faculty_fine_per_day' => 2, 'faculty_grace_days' => 0]);

    $faculty = finesUser('fine_faculty5', 'faculty');
    finesCheckin($this, finesLoan($faculty, -5));

    expect((float) $faculty->fresh()->total_fines)->toBe(10.00);

    finesPolicy($this, ['faculty_fine_per_day' => 99]);

    expect((float) $faculty->fresh()->total_fines)->toBe(10.00);
});

test('F21: fines/me quotes each role its own configured rate', function () {
    finesPolicy($this, ['student_fine_per_day' => 15, 'faculty_fine_per_day' => 7]);

    $faculty = finesUser('fine_faculty6', 'faculty');

    $rate = fn (array $headers) => $this->withHeaders($headers)
        ->getJson('/api/library/fines/me')->assertOk()->json('daily_rate');

    expect((float) $rate(finesAs('Student', 'fine_student')))->toBe(15.0)
        ->and((float) $rate(finesAs('Faculty', 'fine_faculty6')))->toBe(7.0)
        // A librarian is not a borrower category of its own; the default stands.
        ->and((float) $rate(finesAs('Admin', 'fine_admin')))->toBe(15.0);
});

/*
|--------------------------------------------------------------------------
| F-8: the ESTIMATE a borrower is shown matches what they will be charged
|--------------------------------------------------------------------------
|
| estimated_fine reads the borrower's policy from the `user` relation, and
| falls back to the student policy when that relation was not eager-loaded.
| The borrower-facing lists therefore have to load it.
*/

test('F22: a faculty borrower sees an estimate under the faculty rate, grace and cap', function () {
    finesPolicy($this, [
        'student_fine_per_day' => 10, 'student_grace_days' => 0,
        'faculty_fine_per_day' => 2, 'faculty_grace_days' => 3,
    ]);

    $faculty = finesUser('fine_est_faculty', 'faculty');
    finesLoan($faculty, -5);

    $loan = $this->withHeaders(finesAs('Faculty', 'fine_est_faculty'))
        ->getJson('/api/library/loans/me')->assertOk()->json('0');

    // 5 days late, 3 forgiven, 2 chargeable at PHP 2 — not 5 x PHP 10.
    expect((float) $loan['estimated_fine'])->toBe(4.00)
        ->and((int) $loan['days_overdue'])->toBe(2);
});

test('F23: the faculty cap also applies to the estimate', function () {
    finesPolicy($this, [
        'faculty_fine_per_day' => 10, 'faculty_grace_days' => 0, 'faculty_max_fine' => 25,
    ]);

    $faculty = finesUser('fine_est_capped', 'faculty');
    finesLoan($faculty, -10);

    expect((float) $this->withHeaders(finesAs('Faculty', 'fine_est_capped'))
        ->getJson('/api/library/loans/me')->assertOk()->json('0.estimated_fine'))->toBe(25.00);
});

test('F24: a student estimate still follows the student policy', function () {
    finesPolicy($this, [
        'student_fine_per_day' => 10, 'student_grace_days' => 0,
        'faculty_fine_per_day' => 2, 'faculty_grace_days' => 3,
    ]);

    finesLoan($this->student, -5);

    expect((float) $this->withHeaders(finesAs('Student', 'fine_student'))
        ->getJson('/api/library/loans/me')->assertOk()->json('0.estimated_fine'))->toBe(50.00);
});

test('F25: the estimate shown before return equals the fine charged at check-in', function () {
    finesPolicy($this, [
        'student_fine_per_day' => 10, 'student_grace_days' => 0,
        'faculty_fine_per_day' => 2, 'faculty_grace_days' => 3,
    ]);

    $faculty = finesUser('fine_est_match', 'faculty');
    $loan = finesLoan($faculty, -5);

    $estimate = (float) $this->withHeaders(finesAs('Faculty', 'fine_est_match'))
        ->getJson('/api/library/loans/me')->assertOk()->json('0.estimated_fine');

    finesCheckin($this, $loan);

    expect($estimate)->toBe(4.00)
        ->and((float) $faculty->fresh()->total_fines)->toBe($estimate);
});

test('F26: the borrowing history carries the same borrower-correct estimate', function () {
    finesPolicy($this, ['student_fine_per_day' => 10, 'faculty_fine_per_day' => 2, 'faculty_grace_days' => 3]);

    $faculty = finesUser('fine_est_history', 'faculty');
    finesLoan($faculty, -5);

    expect((float) $this->withHeaders(finesAs('Faculty', 'fine_est_history'))
        ->getJson('/api/library/loans/me/history')->assertOk()->json('0.estimated_fine'))->toBe(4.00);
});

test('F27: the desk list estimates by the borrower, not by the librarian reading it', function () {
    finesPolicy($this, [
        'student_fine_per_day' => 10, 'student_grace_days' => 0,
        'faculty_fine_per_day' => 2, 'faculty_grace_days' => 3,
    ]);

    $faculty = finesUser('fine_desk_faculty', 'faculty');
    finesLoan($faculty, -5);
    finesLoan($this->student, -5);

    $rows = collect($this->withHeaders(finesAs('Admin', 'fine_admin'))
        ->getJson('/api/library/circulation?status=active')->assertOk()->json())
        ->keyBy(fn ($row) => $row['user']['username']);

    expect((float) $rows['fine_desk_faculty']['estimated_fine'])->toBe(4.00)
        ->and((float) $rows['fine_student']['estimated_fine'])->toBe(50.00);
});

test('F28: loading the borrower costs one query, not one per loan', function () {
    foreach (range(1, 6) as $i) {
        finesLoan($this->student, -2);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->withHeaders(finesAs('Student', 'fine_student'))->getJson('/api/library/loans/me')->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Identity, the loans, and one eager load per relation — flat in the
    // number of rows. (The desk list's separate N+1 is finding F-7.)
    expect($queries)->toBeLessThanOrEqual(8);
});
