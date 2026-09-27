<?php

/**
 * Course reserve lifecycle.
 *
 *   H-1  releasing a reserve must not leave stale or orphaned holds
 *   H-2  only pending->approved, pending->denied, approved->released; ending a
 *        reserve detaches its copies without stranding them
 *   M-1  removing a student closes their reserve holds and frees their copy
 *   M-2  allocation may not take the last usable general copy while the
 *        general waitlist is waiting
 *
 * The queue, Ready for Pickup and final Admin checkout behave exactly as before.
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

/* ------------------------------------------------------------------ helpers */

function rlAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function rlUser(string $username, string $dbRole, bool $isSuperAdmin = false): User
{
    $user = User::create([
        'username' => $username, 'password' => 'password', 'role' => $dbRole,
        'status' => 'active', 'total_fines' => 0,
    ]);
    $user->forceFill(['is_super_admin' => $isSuperAdmin])->save();

    return $user;
}

function rlBook(): Book
{
    return Book::create([
        'book_title' => 'Lifecycle Title', 'author' => 'Author', 'category' => 'Computer Science',
        'isbn' => 'ISBN-'.uniqid(), 'physical_location' => 'Shelf L1', 'total_copies' => 0,
    ]);
}

function rlCopy(Book $book, string $status = 'available', string $condition = 'good', ?int $reserveId = null): BookCopy
{
    return BookCopy::create([
        'book_id' => $book->book_id, 'condition' => $condition,
        'availability_status' => $status, 'reserve_id' => $reserveId,
    ]);
}

/** A reserve on $book for a section owned by $teacher, with $students enrolled. */
function rlReserve(Book $book, User $teacher, string $status = 'approved', array $students = []): CourseReserve
{
    $section = CourseSection::create(['teacher_id' => $teacher->user_id, 'name' => 'Section '.uniqid()]);

    foreach ($students as $student) {
        CourseSectionStudent::create(['section_id' => $section->section_id, 'student_id' => $student->user_id]);
    }

    return CourseReserve::create([
        'section_id' => $section->section_id, 'book_id' => $book->book_id,
        'user_id' => $teacher->user_id, 'copies_requested' => 3, 'status' => $status,
    ]);
}

function rlHold(User $user, Book $book, string $status, ?int $copyId = null, ?int $reserveId = null, int $position = 0): Hold
{
    return Hold::create([
        'user_id' => $user->user_id, 'book_id' => $book->book_id, 'copy_id' => $copyId,
        'reserve_id' => $reserveId, 'request_date' => now(), 'status' => $status,
        'queue_position' => $position,
    ]);
}

function rlStatus($test, CourseReserve $reserve, string $to, string $role = 'Admin', string $user = 'rl_admin')
{
    return $test->withHeaders(rlAs($role, $user))
        ->putJson("/api/library/reserves/{$reserve->reserve_id}/status", ['status' => $to]);
}

function rlLiveHolds(CourseReserve $reserve): int
{
    return Hold::where('reserve_id', $reserve->reserve_id)
        ->whereIn('status', ['pending', 'pending_approval', 'fulfilled'])
        ->count();
}

beforeEach(function () {
    $this->admin = rlUser('rl_admin', 'administrator');
    $this->super = rlUser('rl_super', 'administrator', true);
    $this->faculty = rlUser('rl_faculty', 'faculty');
    $this->a = rlUser('rl_a', 'student');
    $this->b = rlUser('rl_b', 'student');
    $this->c = rlUser('rl_c', 'student');
    $this->book = rlBook();
});

/* ----------------------------------------------------------- RESERVE RELEASE */

test('RL1: releasing cancels the reserve\'s pending queue', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved', [$this->a, $this->b]);
    rlCopy($this->book, 'checked_out', 'good', $reserve->reserve_id);
    $queued = rlHold($this->b, $this->book, 'pending', null, $reserve->reserve_id, 1);

    rlStatus($this, $reserve, 'released')->assertOk();

    expect($queued->fresh()->status)->toBe('cancelled');
});

test('RL2: releasing cancels a Ready for Pickup hold and frees its copy', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved', [$this->a]);
    $copy = rlCopy($this->book, 'on_hold', 'good', $reserve->reserve_id);
    $ready = rlHold($this->a, $this->book, 'fulfilled', $copy->copy_id, $reserve->reserve_id);

    rlStatus($this, $reserve, 'released')->assertOk();

    expect($ready->fresh()->status)->toBe('cancelled')
        ->and($copy->fresh()->availability_status)->toBe('available')
        ->and($copy->fresh()->reserve_id)->toBeNull();
});

test('RL3: releasing detaches every allocated copy', function () {
    $reserve = rlReserve($this->book, $this->faculty);
    rlCopy($this->book, 'available', 'good', $reserve->reserve_id);
    rlCopy($this->book, 'checked_out', 'good', $reserve->reserve_id);

    rlStatus($this, $reserve, 'released')->assertOk();

    expect(BookCopy::where('reserve_id', $reserve->reserve_id)->count())->toBe(0);
});

test('RL4: after release no live hold remains for the reserve', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved', [$this->a, $this->b, $this->c]);
    $c1 = rlCopy($this->book, 'on_hold', 'good', $reserve->reserve_id);
    $c2 = rlCopy($this->book, 'on_hold', 'good', $reserve->reserve_id);
    rlHold($this->a, $this->book, 'fulfilled', $c1->copy_id, $reserve->reserve_id);
    rlHold($this->b, $this->book, 'pending_approval', $c2->copy_id, $reserve->reserve_id);
    rlHold($this->c, $this->book, 'pending', null, $reserve->reserve_id, 1);

    rlStatus($this, $reserve, 'released')->assertOk();

    expect(rlLiveHolds($reserve))->toBe(0)
        ->and(BookCopy::where('availability_status', 'on_hold')->count())->toBe(0);
});

test('RL5: a released copy goes to the general waitlist if someone is waiting', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved', [$this->a]);
    $copy = rlCopy($this->book, 'on_hold', 'good', $reserve->reserve_id);
    rlHold($this->a, $this->book, 'fulfilled', $copy->copy_id, $reserve->reserve_id);
    $general = rlHold($this->c, $this->book, 'pending', null, null, 1);

    rlStatus($this, $reserve, 'released')->assertOk();

    expect($general->fresh()->status)->toBe('pending_approval')
        ->and((int) $general->fresh()->copy_id)->toBe($copy->copy_id)
        ->and($copy->fresh()->availability_status)->toBe('on_hold');
});

test('RL6: releasing never makes a damaged or lost copy available', function () {
    $reserve = rlReserve($this->book, $this->faculty);
    $damaged = rlCopy($this->book, 'damaged', 'damaged', $reserve->reserve_id);
    $lost = rlCopy($this->book, 'lost', 'good', $reserve->reserve_id);

    rlStatus($this, $reserve, 'released')->assertOk();

    expect($damaged->fresh()->availability_status)->toBe('damaged')
        ->and($damaged->fresh()->condition)->toBe('damaged')
        ->and($lost->fresh()->availability_status)->toBe('lost')
        ->and($damaged->fresh()->reserve_id)->toBeNull()
        ->and($lost->fresh()->reserve_id)->toBeNull();
});

test('RL7: no phantom Ready for Pickup remains for the student', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved', [$this->a]);
    $copy = rlCopy($this->book, 'on_hold', 'good', $reserve->reserve_id);
    rlHold($this->a, $this->book, 'fulfilled', $copy->copy_id, $reserve->reserve_id);

    rlStatus($this, $reserve, 'released')->assertOk();

    $mine = $this->withHeaders(rlAs('Student', 'rl_a'))->getJson('/api/library/holds/me')->json();

    expect(collect($mine)->whereIn('status', ['fulfilled', 'pending_approval', 'pending'])->count())->toBe(0);
});

test('RL8: a released reserve no longer blocks archiving its title', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved', [$this->a]);
    $copy = rlCopy($this->book, 'on_hold', 'good', $reserve->reserve_id);
    rlHold($this->a, $this->book, 'fulfilled', $copy->copy_id, $reserve->reserve_id);

    rlStatus($this, $reserve, 'released')->assertOk();

    $this->withHeaders(rlAs('Admin', 'rl_admin'))
        ->postJson("/api/library/books/{$this->book->book_id}/archive")
        ->assertOk();
});

test('RL9: a copy left on_hold with no hold behind it is released too', function () {
    $reserve = rlReserve($this->book, $this->faculty);
    $orphan = rlCopy($this->book, 'on_hold', 'good', $reserve->reserve_id);

    rlStatus($this, $reserve, 'released')->assertOk();

    expect($orphan->fresh()->availability_status)->toBe('available')
        ->and($orphan->fresh()->reserve_id)->toBeNull();
});

test('RL10: the owning faculty member can still release, with the same clean-up', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved', [$this->a]);
    $copy = rlCopy($this->book, 'on_hold', 'good', $reserve->reserve_id);
    rlHold($this->a, $this->book, 'fulfilled', $copy->copy_id, $reserve->reserve_id);

    rlStatus($this, $reserve, 'released', 'Teacher', 'rl_faculty')->assertOk();

    expect(rlLiveHolds($reserve))->toBe(0)
        ->and($copy->fresh()->availability_status)->toBe('available');
});

/* -------------------------------------------------------- STATUS TRANSITIONS */

test('TR1: pending -> approved is allowed', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'pending');
    rlStatus($this, $reserve, 'approved')->assertOk();
    expect($reserve->fresh()->status)->toBe('approved');
});

test('TR2: pending -> denied is allowed', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'pending');
    rlStatus($this, $reserve, 'denied')->assertOk();
    expect($reserve->fresh()->status)->toBe('denied');
});

test('TR3: approved -> released is allowed', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved');
    rlStatus($this, $reserve, 'released')->assertOk();
    expect($reserve->fresh()->status)->toBe('released');
});

test('TR4: approved -> denied is rejected', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved');
    $copy = rlCopy($this->book, 'available', 'good', $reserve->reserve_id);

    rlStatus($this, $reserve, 'denied')
        ->assertStatus(422)
        ->assertJsonPath('error', 'An approved course reserve can only be released, not denied.');

    expect($reserve->fresh()->status)->toBe('approved')
        ->and((int) $copy->fresh()->reserve_id)->toBe($reserve->reserve_id);
});

test('TR5: released -> approved is rejected', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'released');
    rlStatus($this, $reserve, 'approved')->assertStatus(422);
    expect($reserve->fresh()->status)->toBe('released');
});

test('TR6: denied -> approved is rejected', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'denied');
    rlStatus($this, $reserve, 'approved')->assertStatus(422);
    expect($reserve->fresh()->status)->toBe('denied');
});

test('TR7: denied -> released is rejected', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'denied');
    rlStatus($this, $reserve, 'released')->assertStatus(422);
    expect($reserve->fresh()->status)->toBe('denied');
});

test('TR8: a reserve cannot be moved to the state it is already in', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved');
    rlStatus($this, $reserve, 'approved')
        ->assertStatus(422)
        ->assertJsonPath('error', 'This course reserve is already approved.');
});

test('TR9: denying a pending reserve clears legacy allocations and holds safely', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'pending', [$this->a]);
    $stale = rlCopy($this->book, 'damaged', 'damaged', $reserve->reserve_id);
    $staleHold = rlHold($this->a, $this->book, 'pending', null, $reserve->reserve_id, 1);

    rlStatus($this, $reserve, 'denied')->assertOk();

    expect($stale->fresh()->reserve_id)->toBeNull()
        ->and($stale->fresh()->availability_status)->toBe('damaged')
        ->and($stale->fresh()->condition)->toBe('damaged')
        ->and($staleHold->fresh()->status)->toBe('cancelled');
});

/* ---------------------------------------------------------- CHECKOUT CLEANUP */

test('CO1: checking out a Ready for Pickup copy clears its hold even if the copy lost its reserve link', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved', [$this->a]);
    $copy = rlCopy($this->book, 'on_hold', 'good', $reserve->reserve_id);
    $ready = rlHold($this->a, $this->book, 'fulfilled', $copy->copy_id, $reserve->reserve_id);

    // The old failure: the copy's reserve_id was cleared while it waited, so
    // the pool-based lookup missed the reserve hold and left it fulfilled.
    $copy->forceFill(['reserve_id' => null])->save();

    $this->withHeaders(rlAs('Admin', 'rl_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->a->user_id, 'copy_id' => $copy->copy_id])
        ->assertStatus(201);

    expect(Hold::find($ready->hold_id))->toBeNull()
        ->and(Hold::where('user_id', $this->a->user_id)->where('status', 'fulfilled')->count())->toBe(0);
});

test('CO2: a normal reserve pickup still clears the hold', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved', [$this->a]);
    $copy = rlCopy($this->book, 'on_hold', 'good', $reserve->reserve_id);
    $ready = rlHold($this->a, $this->book, 'fulfilled', $copy->copy_id, $reserve->reserve_id);

    $this->withHeaders(rlAs('Admin', 'rl_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->a->user_id, 'copy_id' => $copy->copy_id])
        ->assertStatus(201);

    expect(Hold::find($ready->hold_id))->toBeNull()
        ->and($copy->fresh()->availability_status)->toBe('checked_out');
});

test('CO3: the general hold flow still ends in a clean checkout', function () {
    $copy = rlCopy($this->book);

    $this->withHeaders(rlAs('Student', 'rl_a'))
        ->postJson("/api/library/books/{$this->book->book_id}/holds")->assertStatus(201);

    $hold = Hold::first();
    expect($hold->status)->toBe('pending_approval');

    $this->withHeaders(rlAs('Admin', 'rl_admin'))->putJson("/api/library/holds/{$hold->hold_id}/accept")->assertOk();
    $this->withHeaders(rlAs('Admin', 'rl_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->a->user_id, 'copy_id' => $copy->copy_id])
        ->assertStatus(201);

    expect(Hold::count())->toBe(0)
        ->and(Transaction::where('status', 'active')->count())->toBe(1);
});

test('CO4: checking out a different copy frees the held one for the next borrower', function () {
    $held = rlCopy($this->book, 'on_hold');
    $other = rlCopy($this->book);
    rlHold($this->a, $this->book, 'fulfilled', $held->copy_id);
    $next = rlHold($this->b, $this->book, 'pending', null, null, 1);

    $this->withHeaders(rlAs('Admin', 'rl_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->a->user_id, 'copy_id' => $other->copy_id])
        ->assertStatus(201);

    expect($next->fresh()->status)->toBe('pending_approval')
        ->and((int) $next->fresh()->copy_id)->toBe($held->copy_id);
});

/* ----------------------------------------------------------- SECTION REMOVAL */

function rlRemove($test, CourseReserve $reserve, User $student)
{
    return $test->withHeaders(rlAs('Teacher', 'rl_faculty'))
        ->deleteJson("/api/library/sections/{$reserve->section_id}/students/{$student->user_id}");
}

test('SR1: removing a student cancels their queued reserve hold', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved', [$this->a]);
    rlCopy($this->book, 'checked_out', 'good', $reserve->reserve_id);
    $queued = rlHold($this->a, $this->book, 'pending', null, $reserve->reserve_id, 1);

    rlRemove($this, $reserve, $this->a)->assertOk();

    expect($queued->fresh()->status)->toBe('cancelled');
});

test('SR2: removing a student with a Ready for Pickup copy passes it to the next enrolled student', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved', [$this->a, $this->b]);
    $copy = rlCopy($this->book, 'on_hold', 'good', $reserve->reserve_id);
    $ready = rlHold($this->a, $this->book, 'fulfilled', $copy->copy_id, $reserve->reserve_id);
    $next = rlHold($this->b, $this->book, 'pending', null, $reserve->reserve_id, 1);

    rlRemove($this, $reserve, $this->a)->assertOk();

    expect($ready->fresh()->status)->toBe('cancelled')
        ->and($next->fresh()->status)->toBe('fulfilled')
        ->and((int) $next->fresh()->copy_id)->toBe($copy->copy_id)
        ->and($copy->fresh()->availability_status)->toBe('on_hold');
});

test('SR3: with nobody waiting, the freed copy goes back on the shelf in its reserve', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved', [$this->a]);
    $copy = rlCopy($this->book, 'on_hold', 'good', $reserve->reserve_id);
    rlHold($this->a, $this->book, 'fulfilled', $copy->copy_id, $reserve->reserve_id);

    rlRemove($this, $reserve, $this->a)->assertOk();

    expect($copy->fresh()->availability_status)->toBe('available')
        ->and((int) $copy->fresh()->reserve_id)->toBe($reserve->reserve_id);
});

test('SR4: a removed student can no longer request the reserve', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved', [$this->a]);
    rlCopy($this->book, 'available', 'good', $reserve->reserve_id);

    rlRemove($this, $reserve, $this->a)->assertOk();

    $this->withHeaders(rlAs('Student', 'rl_a'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/request")
        ->assertStatus(422);
});

test('SR5: a student no longer enrolled is skipped when the reserve queue advances', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved', [$this->a, $this->c]);
    $copy = rlCopy($this->book, 'on_hold', 'good', $reserve->reserve_id);
    $ready = rlHold($this->a, $this->book, 'fulfilled', $copy->copy_id, $reserve->reserve_id);
    $ineligible = rlHold($this->b, $this->book, 'pending', null, $reserve->reserve_id, 1); // B was never enrolled
    $eligible = rlHold($this->c, $this->book, 'pending', null, $reserve->reserve_id, 2);

    $this->withHeaders(rlAs('Admin', 'rl_admin'))->deleteJson("/api/library/holds/{$ready->hold_id}")->assertOk();

    expect($ineligible->fresh()->status)->toBe('cancelled')
        ->and($eligible->fresh()->status)->toBe('fulfilled')
        ->and((int) $eligible->fresh()->copy_id)->toBe($copy->copy_id);
});

test('SR6: removing a student never makes a damaged copy available', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved', [$this->a]);
    $copy = rlCopy($this->book, 'on_hold', 'good', $reserve->reserve_id);
    rlHold($this->a, $this->book, 'fulfilled', $copy->copy_id, $reserve->reserve_id);
    $copy->forceFill(['availability_status' => 'damaged', 'condition' => 'damaged'])->save(); // legacy row

    rlRemove($this, $reserve, $this->a)->assertOk();

    expect($copy->fresh()->availability_status)->toBe('damaged');
});

/* -------------------------------------------------- GENERAL QUEUE PROTECTION */

test('GQ1: auto-allocation of the last usable general copy is refused while borrowers wait', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved');
    $copy = rlCopy($this->book);
    rlHold($this->c, $this->book, 'pending', null, null, 1);

    $this->withHeaders(rlAs('Admin', 'rl_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate")
        ->assertStatus(422)
        ->assertJsonPath('error', 'Cannot allocate the last usable general copy while borrowers are waiting in the general queue.');

    expect($copy->fresh()->reserve_id)->toBeNull();
});

test('GQ2: explicit allocation of the last usable general copy is refused while borrowers wait', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved');
    $copy = rlCopy($this->book);
    rlHold($this->c, $this->book, 'pending', null, null, 1);

    $this->withHeaders(rlAs('Admin', 'rl_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate", ['copy_ids' => [$copy->copy_id]])
        ->assertStatus(422);

    expect($copy->fresh()->reserve_id)->toBeNull();
});

test('GQ3: allocation succeeds when another usable general copy remains', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved');
    $copy = rlCopy($this->book);
    rlCopy($this->book);
    rlHold($this->c, $this->book, 'pending', null, null, 1);

    $this->withHeaders(rlAs('Admin', 'rl_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate", ['copy_ids' => [$copy->copy_id]])
        ->assertOk();

    expect((int) $copy->fresh()->reserve_id)->toBe($reserve->reserve_id);
});

test('GQ4: damaged, lost and archived copies do not count as usable general copies', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved');
    $copy = rlCopy($this->book);
    rlCopy($this->book, 'damaged', 'damaged');
    rlCopy($this->book, 'lost');
    rlCopy($this->book)->forceFill(['archived_at' => now()])->save();
    rlHold($this->c, $this->book, 'pending', null, null, 1);

    $this->withHeaders(rlAs('Admin', 'rl_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate", ['copy_ids' => [$copy->copy_id]])
        ->assertStatus(422);
});

test('GQ5: a copy on loan does not count either — only one on the shelf can serve the queue now', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved');
    $copy = rlCopy($this->book);
    rlCopy($this->book, 'checked_out');
    rlHold($this->c, $this->book, 'pending', null, null, 1);

    $this->withHeaders(rlAs('Admin', 'rl_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate", ['copy_ids' => [$copy->copy_id]])
        ->assertStatus(422);
});

test('GQ6: with nobody waiting, the last general copy may be allocated', function () {
    $reserve = rlReserve($this->book, $this->faculty, 'approved');
    $copy = rlCopy($this->book);

    $this->withHeaders(rlAs('Admin', 'rl_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate")
        ->assertOk();

    expect((int) $copy->fresh()->reserve_id)->toBe($reserve->reserve_id);
});
