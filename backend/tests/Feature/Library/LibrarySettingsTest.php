<?php

/**
 * Library settings.
 *
 * The page is not decorative: these values drive borrowing limits, due dates,
 * fines and renewals. Changing one affects FUTURE operations only — a due date
 * or a fine already recorded is never rewritten.
 */

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\LibrarySetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LibrarySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function setAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function setUser(string $username, string $dbRole, bool $isSuperAdmin = false): User
{
    $user = User::create([
        'username' => $username,
        'password' => 'password',
        'role' => $dbRole,
        'status' => 'active',
        'total_fines' => 0,
    ]);

    $user->forceFill(['is_super_admin' => $isSuperAdmin])->save();

    return $user;
}

function setCopy(string $status = 'available'): BookCopy
{
    $book = Book::create([
        'book_title' => 'Settings Title '.uniqid(),
        'author' => 'Author',
        'category' => 'Computer Science',
        'isbn' => '978'.rand(1000000000, 9999999999),
        'physical_location' => 'Shelf S1',
        'total_copies' => 0,
    ]);

    return BookCopy::create([
        'book_id' => $book->book_id,
        'condition' => 'good',
        'availability_status' => $status,
    ]);
}

beforeEach(function () {
    // Fine arithmetic counts whole days and rounds partials UP, so a due date
    // built from one now() and a check-in a few microseconds later would score
    // an extra day. Freezing the clock keeps these assertions about the rules
    // rather than about timing.
    Carbon::setTestNow(Carbon::parse('2026-09-13 09:00:00'));

    $this->admin = setUser('set_admin', 'administrator');
    $this->superAdmin = setUser('set_super', 'administrator', true);
    $this->student = setUser('set_student', 'student');
    $this->faculty = setUser('set_faculty', 'faculty');
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

test('LS1: a student cannot read or change settings', function () {
    $this->withHeaders(setAs('Student', 'set_student'))
        ->getJson('/api/library/settings')->assertStatus(403);

    $this->withHeaders(setAs('Student', 'set_student'))
        ->putJson('/api/library/settings', ['settings' => ['student_max_books' => 99]])
        ->assertStatus(403);
});

test('LS2: a faculty member cannot change settings', function () {
    $this->withHeaders(setAs('Teacher', 'set_faculty'))
        ->getJson('/api/library/settings')->assertStatus(403);

    $this->withHeaders(setAs('Faculty', 'set_faculty'))
        ->putJson('/api/library/settings', ['settings' => ['student_max_books' => 99]])
        ->assertStatus(403);
});

test('LS3: admin and super admin can both read and change settings', function () {
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->getJson('/api/library/settings')->assertStatus(200);

    $this->withHeaders(setAs('Super Admin', 'set_super'))
        ->getJson('/api/library/settings')->assertStatus(200);

    $this->withHeaders(setAs('Super Admin', 'set_super'))
        ->putJson('/api/library/settings', ['settings' => ['student_max_books' => 5]])
        ->assertStatus(200);
});

/*
|--------------------------------------------------------------------------
| Defaults reflect the behaviour they replaced
|--------------------------------------------------------------------------
*/

test('LS4: the shipped defaults match the previously hardcoded rules', function () {
    $settings = $this->withHeaders(setAs('Admin', 'set_admin'))
        ->getJson('/api/library/settings')->json('settings');

    expect($settings['student_max_books'])->toBe(3);
    expect($settings['faculty_max_books'])->toBe(10);
    expect($settings['student_loan_days'])->toBe(7);
    expect($settings['faculty_loan_days'])->toBe(14);
    expect((float) $settings['student_fine_per_day'])->toBe(10.0);
    expect($settings['reserve_loan_days'])->toBe(14);
});

test('LS5: an untouched setting stores no row', function () {
    $this->withHeaders(setAs('Admin', 'set_admin'))->getJson('/api/library/settings');

    expect(LibrarySetting::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Settings drive real behaviour
|--------------------------------------------------------------------------
*/

test('LS6: the borrowing limit comes from settings', function () {
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', ['settings' => ['student_max_books' => 1]])
        ->assertStatus(200);

    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => setCopy()->copy_id,
        ])->assertStatus(201);

    // The second loan now exceeds the limit that was just set.
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => setCopy()->copy_id,
        ])->assertStatus(422);
});

test('LS7: the loan period comes from settings, for NEW checkouts only', function () {
    $firstCopy = setCopy();

    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $firstCopy->copy_id,
        ])->assertStatus(201);

    $existingLoan = Transaction::where('copy_id', $firstCopy->copy_id)->first();
    $originalDue = $existingLoan->due_date->copy();

    // Change the rule after that loan already exists.
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', ['settings' => ['student_loan_days' => 10]])
        ->assertStatus(200);

    // The loan already out keeps the date it was given.
    expect($existingLoan->fresh()->due_date->eq($originalDue))->toBeTrue();

    // A new checkout uses the new rule.
    $secondCopy = setCopy();

    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $secondCopy->copy_id,
        ])->assertStatus(201);

    $newLoan = Transaction::where('copy_id', $secondCopy->copy_id)->first();

    expect($newLoan->due_date->startOfDay()->eq(now()->addDays(10)->startOfDay()))->toBeTrue();
});

test('LS8: the fine rate comes from settings, for NEW check-ins only', function () {
    $copy = setCopy('checked_out');

    $loan = Transaction::create([
        'user_id' => $this->student->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDays(10),
        'due_date' => now()->subDays(3),
        'status' => 'active',
    ]);

    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', ['settings' => ['student_fine_per_day' => 15]])
        ->assertStatus(200);

    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(200);

    // 3 days at the new rate, not the old one.
    expect((float) $this->student->fresh()->total_fines)->toBe(45.0);
});

test('LS9: a recorded balance is never recalculated by a later rate change', function () {
    $copy = setCopy('checked_out');

    $loan = Transaction::create([
        'user_id' => $this->student->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDays(10),
        'due_date' => now()->subDays(2),
        'status' => 'active',
    ]);

    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(200);

    $charged = (float) $this->student->fresh()->total_fines;
    expect($charged)->toBe(20.0); // 2 days at the default 10.00

    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', ['settings' => ['student_fine_per_day' => 100]])
        ->assertStatus(200);

    // History is history.
    expect((float) $this->student->fresh()->total_fines)->toBe($charged);
});

test('LS10: a grace period forgives days rather than shifting the due date', function () {
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', ['settings' => ['student_grace_days' => 2]])
        ->assertStatus(200);

    $copy = setCopy('checked_out');

    $loan = Transaction::create([
        'user_id' => $this->student->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDays(10),
        'due_date' => now()->subDays(3),
        'status' => 'active',
    ]);

    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(200);

    // 3 days late, 2 forgiven -> 1 chargeable day.
    expect((float) $this->student->fresh()->total_fines)->toBe(10.0);
});

test('LS11: a maximum fine caps a single loan', function () {
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', ['settings' => ['student_max_fine' => 25]])
        ->assertStatus(200);

    $copy = setCopy('checked_out');

    $loan = Transaction::create([
        'user_id' => $this->student->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDays(30),
        'due_date' => now()->subDays(20),
        'status' => 'active',
    ]);

    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(200);

    // 20 days would be 200.00 uncapped.
    expect((float) $this->student->fresh()->total_fines)->toBe(25.0);
});

test('LS12: borrowing can be switched off for a whole role', function () {
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', ['settings' => ['student_borrowing_enabled' => false]])
        ->assertStatus(200);

    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => setCopy()->copy_id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'Borrowing is currently disabled for this borrower category.');

    // Faculty are governed separately and are unaffected.
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->faculty->user_id,
            'copy_id' => setCopy()->copy_id,
        ])->assertStatus(201);
});

test('LS13: renewal requests can be switched off per role', function () {
    $copy = setCopy('checked_out');

    $loan = Transaction::create([
        'user_id' => $this->student->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDay(),
        'due_date' => now()->addDays(5),
        'status' => 'active',
    ]);

    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', ['settings' => ['student_renewal_enabled' => false]])
        ->assertStatus(200);

    $this->withHeaders(setAs('Student', 'set_student'))
        ->postJson('/api/library/renewals', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(422)
        ->assertJsonPath('error', 'Renewal requests are currently disabled for this borrower category.');
});

test('LS14: the renewal cap counts approved renewals only', function () {
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', ['settings' => ['max_renewals_per_loan' => 1]])
        ->assertStatus(200);

    $copy = setCopy('checked_out');

    $loan = Transaction::create([
        'user_id' => $this->student->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDay(),
        'due_date' => now()->addDays(5),
        'status' => 'active',
    ]);

    $first = $this->withHeaders(setAs('Student', 'set_student'))
        ->postJson('/api/library/renewals', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(201)->json('renewal_request.renewal_request_id');

    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson("/api/library/renewals/{$first}/approve")->assertStatus(200);

    // The cap is now used up.
    $this->withHeaders(setAs('Student', 'set_student'))
        ->postJson('/api/library/renewals', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(422)
        ->assertJsonPath('error', 'This loan has already been renewed once and cannot be renewed again.');
});

test('LS15: the course reserve loan period comes from settings', function () {
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', ['settings' => ['reserve_loan_days' => 3]])
        ->assertStatus(200);

    expect(app(LibrarySettingsService::class)->reserveLoanDays())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Change history
|--------------------------------------------------------------------------
*/

test('LS16: the response reports exactly what changed', function () {
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', ['settings' => ['student_fine_per_day' => 15]])
        ->assertOk()
        ->assertJsonPath('changed.0.key', 'student_fine_per_day')
        ->assertJsonPath('changed.0.previous_value', '10')
        ->assertJsonPath('changed.0.new_value', '15');

    expect(app(LibrarySettingsService::class)->finePerDayFor('student'))->toBe(15.0);
});

test('LS17: a no-op edit reports no changes', function () {
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', ['settings' => ['student_fine_per_day' => 10]])
        ->assertOk()
        ->assertJsonPath('message', 'No changes to save.')
        ->assertJsonCount(0, 'changed');
});

test('LS19: an unknown key is ignored, never stored', function () {
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', [
            'settings' => ['is_super_admin' => true, 'student_max_books' => 4],
        ])
        ->assertStatus(200);

    expect(LibrarySetting::where('key', 'is_super_admin')->exists())->toBeFalse();
    expect(LibrarySetting::where('key', 'student_max_books')->exists())->toBeTrue();
});

test('LS19b: a payload of ONLY unknown keys is a safe no-op, not a 500', function () {
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', [
            'settings' => ['not_a_setting' => 1, 'is_super_admin' => true],
        ])
        ->assertStatus(200)
        ->assertJson(['message' => 'No changes to save.']);

    expect(LibrarySetting::whereIn('key', ['not_a_setting', 'is_super_admin'])->count())->toBe(0);
});

test('LS19c: the unknown key is dropped while the valid one beside it is saved', function () {
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', [
            'settings' => ['not_a_setting' => 1, 'student_loan_days' => 9],
        ])
        ->assertStatus(200)
        ->assertJson(['message' => '1 setting updated.']);

    expect(LibrarySetting::where('key', 'not_a_setting')->exists())->toBeFalse()
        ->and(LibrarySetting::where('key', 'student_loan_days')->value('value'))->toBe('9');
});

test('LS20: out-of-range values are rejected', function () {
    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', ['settings' => ['student_loan_days' => 0]])
        ->assertStatus(422);

    $this->withHeaders(setAs('Admin', 'set_admin'))
        ->putJson('/api/library/settings', ['settings' => ['student_fine_per_day' => -5]])
        ->assertStatus(422);
});
