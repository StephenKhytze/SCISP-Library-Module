<?php

/**
 * D-3: borrowing from a course reserve.
 *
 * Confirmed rule — the two copy pools never cross:
 *   course reserve request -> ONLY copies where reserve_id = that reserve
 *   general hold           -> ONLY copies where reserve_id IS NULL
 *
 * A free allocated copy is set aside for the student (ready for pickup);
 * when every allocated copy is out, the student queues for THAT reserve, not
 * for the title. Final physical checkout stays with Admin / Super Admin.
 */

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\CourseReserve;
use App\Models\CourseSection;
use App\Models\CourseSectionStudent;
use App\Models\Hold;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function crrAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function crrUser(string $username, string $dbRole, bool $isSuperAdmin = false): User
{
    $user = User::create([
        'username' => $username,
        'password' => 'password',
        'role' => $dbRole,
        'status' => 'active',
        'total_fines' => 0,
    ]);

    // Not mass-assignable: forced here because this is trusted test setup.
    $user->forceFill(['is_super_admin' => $isSuperAdmin])->save();

    return $user;
}

function crrBook(): Book
{
    return Book::create([
        'book_title' => 'Reserve Title',
        'author' => 'Author',
        'category' => 'Computer Science',
        'isbn' => 'ISBN-'.uniqid(),
        'physical_location' => 'Shelf C1',
        'total_copies' => 0,
    ]);
}

function crrCopy(Book $book, ?int $reserveId = null, string $status = 'available'): BookCopy
{
    return BookCopy::create([
        'book_id' => $book->book_id,
        'condition' => 'good',
        'availability_status' => $status,
        'reserve_id' => $reserveId,
    ]);
}

beforeEach(function () {
    $this->teacher = crrUser('crr_teacher', 'faculty');
    $this->student = crrUser('crr_student', 'student');
    $this->classmate = crrUser('crr_classmate', 'student');
    $this->outsider = crrUser('crr_outsider', 'student');
    $this->admin = crrUser('crr_admin', 'administrator');

    $this->section = CourseSection::create([
        'teacher_id' => $this->teacher->user_id,
        'name' => 'Algorithms 101',
    ]);

    foreach ([$this->student, $this->classmate] as $enrolled) {
        CourseSectionStudent::create([
            'section_id' => $this->section->section_id,
            'student_id' => $enrolled->user_id,
        ]);
    }

    $this->book = crrBook();

    $this->reserve = CourseReserve::create([
        'section_id' => $this->section->section_id,
        'book_id' => $this->book->book_id,
        'user_id' => $this->teacher->user_id,
        'copies_requested' => 1,
        'status' => 'approved',
    ]);

    // One copy allocated to the reserve, one left in general circulation.
    $this->reservedCopy = crrCopy($this->book, $this->reserve->reserve_id);
    $this->generalCopy = crrCopy($this->book, null);
});

/*
|--------------------------------------------------------------------------
| Happy path
|--------------------------------------------------------------------------
*/

test('CR1: an enrolled student can request the reserve and gets its own copy', function () {
    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")
        ->assertStatus(201)
        ->assertJsonPath('status', 'ready_for_pickup');

    $hold = Hold::where('user_id', $this->student->user_id)->first();

    expect((int) $hold->reserve_id)->toBe((int) $this->reserve->reserve_id);
    // The set-aside copy must be the ALLOCATED one, never general stock.
    expect((int) $hold->copy_id)->toBe((int) $this->reservedCopy->copy_id);
    expect($this->reservedCopy->fresh()->availability_status)->toBe('on_hold');
    expect($this->generalCopy->fresh()->availability_status)->toBe('available');
});

test('CR2: requesting does not create a loan — the librarian still checks out', function () {
    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")
        ->assertStatus(201);

    expect(Transaction::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

test('CR3: a student from another section is refused', function () {
    $this->withHeaders(crrAs('Student', 'crr_outsider'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")
        ->assertStatus(422)
        ->assertJsonPath('error', 'You are not enrolled in this course section.');

    expect(Hold::count())->toBe(0);
});

test('CR4: only students may use this route', function () {
    $this->withHeaders(crrAs('Teacher', 'crr_teacher'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")
        ->assertStatus(403);

    $this->withHeaders(crrAs('Admin', 'crr_admin'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")
        ->assertStatus(403);
});

test('CR5: a reserve that is not approved cannot be borrowed from', function () {
    $this->reserve->update(['status' => 'pending']);

    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")
        ->assertStatus(422)
        ->assertJsonPath('error', 'This course reserve is not available for borrowing.');
});

test('CR6: a reserve with no allocated copies is refused', function () {
    $this->reservedCopy->update(['reserve_id' => null]);

    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")
        ->assertStatus(422)
        ->assertJsonPath('error', 'No physical copies have been allocated to this course reserve yet.');
});

test('CR7: a duplicate request is refused', function () {
    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")->assertStatus(201);

    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")
        ->assertStatus(422)
        ->assertJsonPath('error', 'You already have an active request for this course reserve.');

    expect(Hold::where('user_id', $this->student->user_id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The two pools never cross
|--------------------------------------------------------------------------
*/

test('CR8: a general hold never consumes a course-reserved copy', function () {
    // Take the only general copy out of circulation.
    $this->generalCopy->update(['availability_status' => 'checked_out']);

    $this->withHeaders(crrAs('Student', 'crr_outsider'))
        ->postJson("/api/library/books/{$this->book->book_id}/holds")
        ->assertStatus(201);

    $hold = Hold::where('user_id', $this->outsider->user_id)->first();

    // The reserved copy was free, but must not have been handed over. The
    // borrower waits instead.
    expect($hold->status)->toBe('pending');
    expect($hold->copy_id)->toBeNull();
    expect($this->reservedCopy->fresh()->availability_status)->toBe('available');
});

test('CR9: a reserve request never consumes a general copy', function () {
    // Only the general copy is free.
    $this->reservedCopy->update(['availability_status' => 'checked_out']);

    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")
        ->assertStatus(201)
        ->assertJsonPath('status', 'queued');

    expect($this->generalCopy->fresh()->availability_status)->toBe('available');
});

test('CR10: the reserve queue is separate from the titles general queue', function () {
    $this->reservedCopy->update(['availability_status' => 'checked_out']);
    $this->generalCopy->update(['availability_status' => 'checked_out']);

    // An outsider queues for the title in general.
    $this->withHeaders(crrAs('Student', 'crr_outsider'))
        ->postJson("/api/library/books/{$this->book->book_id}/holds")->assertStatus(201);

    // An enrolled student queues for the reserve.
    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")->assertStatus(201);

    $general = Hold::whereNull('reserve_id')->where('user_id', $this->outsider->user_id)->first();
    $reserved = Hold::where('reserve_id', $this->reserve->reserve_id)->where('user_id', $this->student->user_id)->first();

    // Each is first in its OWN queue — they are not numbered against each other.
    expect((int) $general->queue_position)->toBe(1);
    expect((int) $reserved->queue_position)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Queueing
|--------------------------------------------------------------------------
*/

test('CR11: when every allocated copy is out, the student queues for that reserve', function () {
    $this->reservedCopy->update(['availability_status' => 'checked_out']);

    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")
        ->assertStatus(201)
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('queue_position', 1);
});

test('CR12: queue positions are ordered within the reserve', function () {
    $this->reservedCopy->update(['availability_status' => 'checked_out']);

    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")
        ->assertJsonPath('queue_position', 1);

    $this->withHeaders(crrAs('Student', 'crr_classmate'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")
        ->assertJsonPath('queue_position', 2);
});

test('CR13: returning a reserve copy serves that reserves queue, not the general one', function () {
    // The reserved copy is on loan to the classmate; the student is queued for
    // the reserve and an outsider is queued for the title in general.
    $this->reservedCopy->update(['availability_status' => 'checked_out']);
    $this->generalCopy->update(['availability_status' => 'checked_out']);

    $loan = Transaction::create([
        'user_id' => $this->classmate->user_id,
        'copy_id' => $this->reservedCopy->copy_id,
        'date_borrowed' => now()->subDay(),
        'due_date' => now()->addDays(13),
        'status' => 'active',
    ]);

    $this->withHeaders(crrAs('Student', 'crr_outsider'))
        ->postJson("/api/library/books/{$this->book->book_id}/holds")->assertStatus(201);

    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")->assertStatus(201);

    $this->withHeaders(crrAs('Admin', 'crr_admin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])
        ->assertStatus(200);

    $reserveHold = Hold::where('reserve_id', $this->reserve->reserve_id)
        ->where('user_id', $this->student->user_id)->first();
    $generalHold = Hold::whereNull('reserve_id')
        ->where('user_id', $this->outsider->user_id)->first();

    // The enrolled student is served; the general waiter is untouched.
    expect($reserveHold->status)->toBe('fulfilled');
    expect((int) $reserveHold->copy_id)->toBe((int) $this->reservedCopy->copy_id);
    expect($generalHold->status)->toBe('pending');
});

/*
|--------------------------------------------------------------------------
| Checkout still belongs to the librarian
|--------------------------------------------------------------------------
*/

test('CR14: the librarian completes the checkout of a set-aside reserve copy', function () {
    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")->assertStatus(201);

    $this->withHeaders(crrAs('Admin', 'crr_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $this->reservedCopy->copy_id,
        ])
        ->assertStatus(201);

    expect($this->reservedCopy->fresh()->availability_status)->toBe('checked_out');
});

test('CR15: a student still cannot check the copy out themselves', function () {
    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")->assertStatus(201);

    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $this->reservedCopy->copy_id,
        ])
        ->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| D-2: the caller's own state on each reserve
|--------------------------------------------------------------------------
*/

test('CR16: sections/me reports available before any request', function () {
    $response = $this->withHeaders(crrAs('Student', 'crr_student'))
        ->getJson('/api/library/sections/me')
        ->assertStatus(200);

    $reserve = $response->json('0.reserves.0');

    expect($reserve['student_request_status'])->toBe('available');
    expect($reserve['allocated_copies'])->toBe(1);
    expect($reserve['available_for_section'])->toBe(1);
    expect($reserve['queue_position'])->toBeNull();
});

test('CR17: sections/me reports ready_for_pickup after a successful request', function () {
    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")->assertStatus(201);

    $reserve = $this->withHeaders(crrAs('Student', 'crr_student'))
        ->getJson('/api/library/sections/me')->json('0.reserves.0');

    expect($reserve['student_request_status'])->toBe('ready_for_pickup');
    // The copy is set aside, so it is no longer free for the section.
    expect($reserve['available_for_section'])->toBe(0);
});

test('CR18: sections/me reports queued with a position', function () {
    $this->reservedCopy->update(['availability_status' => 'checked_out']);

    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")->assertStatus(201);

    $reserve = $this->withHeaders(crrAs('Student', 'crr_student'))
        ->getJson('/api/library/sections/me')->json('0.reserves.0');

    expect($reserve['student_request_status'])->toBe('queued');
    expect($reserve['queue_position'])->toBe(1);
});

test('CR19: sections/me reports on_loan once the copy is checked out to them', function () {
    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")->assertStatus(201);

    $this->withHeaders(crrAs('Admin', 'crr_admin'))
        ->postJson('/api/library/checkout', [
            'user_id' => $this->student->user_id,
            'copy_id' => $this->reservedCopy->copy_id,
        ])->assertStatus(201);

    $reserve = $this->withHeaders(crrAs('Student', 'crr_student'))
        ->getJson('/api/library/sections/me')->json('0.reserves.0');

    expect($reserve['student_request_status'])->toBe('on_loan');
});

test('CR20: a classmate sees the reserve as unavailable while it is out', function () {
    $this->withHeaders(crrAs('Student', 'crr_student'))
        ->postJson("/api/library/reserves/{$this->reserve->reserve_id}/request")->assertStatus(201);

    $reserve = $this->withHeaders(crrAs('Student', 'crr_classmate'))
        ->getJson('/api/library/sections/me')->json('0.reserves.0');

    expect($reserve['student_request_status'])->toBe('unavailable');
    expect($reserve['available_for_section'])->toBe(0);
});
