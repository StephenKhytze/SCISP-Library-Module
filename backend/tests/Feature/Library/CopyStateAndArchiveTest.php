<?php

/**
 * Damaged / lost copy rules and individual copy archive.
 *
 *   D-1  condition=damaged forces availability=damaged; moving condition away
 *        from damaged does NOT make the copy available again.
 *   D-2  copy archive is its own lifecycle state (archived_at/by/reason),
 *        orthogonal to condition and availability.
 *   D-3  a copy cannot be archived if that strands a waiting general queue.
 *   D-4  a copy cannot be restored while its title is archived.
 *
 * Plus the check-in bug this work closes: a lost or damaged copy used to be
 * reset to available, or handed to the next borrower, when it was returned.
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

function csAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function csUser(string $username, string $dbRole, bool $isSuperAdmin = false): User
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

function csBook(): Book
{
    return Book::create([
        'book_title' => 'Copy State Title',
        'author' => 'Author',
        'category' => 'Computer Science',
        'isbn' => 'ISBN-'.uniqid(),
        'physical_location' => 'Shelf C1',
        'total_copies' => 0,
    ]);
}

function csCopy(Book $book, string $status = 'available', string $condition = 'good', ?int $reserveId = null): BookCopy
{
    return BookCopy::create([
        'book_id' => $book->book_id,
        'condition' => $condition,
        'availability_status' => $status,
        'reserve_id' => $reserveId,
    ]);
}

/** An active loan of $copy to $user, with the copy marked checked_out. */
function csLoan(User $user, BookCopy $copy): Transaction
{
    $copy->update(['availability_status' => 'checked_out']);

    return Transaction::create([
        'user_id' => $user->user_id,
        'copy_id' => $copy->copy_id,
        'date_borrowed' => now()->subDay(),
        'due_date' => now()->addDays(6),
        'status' => 'active',
    ]);
}

function csHold(User $user, Book $book, string $status, ?int $copyId = null, ?int $reserveId = null, int $position = 0): Hold
{
    return Hold::create([
        'user_id' => $user->user_id,
        'book_id' => $book->book_id,
        'copy_id' => $copyId,
        'reserve_id' => $reserveId,
        'request_date' => now(),
        'status' => $status,
        'queue_position' => $position,
    ]);
}

function csReserve(Book $book, User $teacher, string $status = 'approved'): CourseReserve
{
    $section = CourseSection::create(['teacher_id' => $teacher->user_id, 'name' => 'Section '.uniqid()]);

    return CourseReserve::create([
        'section_id' => $section->section_id,
        'book_id' => $book->book_id,
        'user_id' => $teacher->user_id,
        'copies_requested' => 2,
        'status' => $status,
    ]);
}

beforeEach(function () {
    $this->admin = csUser('cs_admin', 'administrator');
    $this->super = csUser('cs_super', 'administrator', true);
    $this->student = csUser('cs_student', 'student');
    $this->other = csUser('cs_student2', 'student');
    $this->faculty = csUser('cs_faculty', 'faculty');
    $this->book = csBook();
});

/* ------------------------------------------------------------------ DAMAGED */

test('DM1: damaged is a valid condition', function () {
    $copy = csCopy($this->book);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->putJson("/api/library/copies/{$copy->copy_id}", ['condition' => 'damaged'])
        ->assertOk();

    expect($copy->fresh()->condition)->toBe('damaged');
});

test('DM2: condition damaged forces availability damaged, even if the request says available', function () {
    $copy = csCopy($this->book);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->putJson("/api/library/copies/{$copy->copy_id}", [
            'condition' => 'damaged',
            'availability_status' => 'available',
        ])->assertOk();

    expect($copy->fresh()->availability_status)->toBe('damaged');
});

test('DM3: a damaged copy cannot be checked out', function () {
    $copy = csCopy($this->book);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->putJson("/api/library/copies/{$copy->copy_id}", ['condition' => 'damaged'])->assertOk();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->student->user_id, 'copy_id' => $copy->copy_id])
        ->assertStatus(422);

    expect(Transaction::count())->toBe(0);
});

test('DM4: repairing the condition does NOT return the copy to circulation by itself', function () {
    $copy = csCopy($this->book, 'damaged', 'damaged');

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->putJson("/api/library/copies/{$copy->copy_id}", ['condition' => 'good'])->assertOk();

    expect($copy->fresh()->condition)->toBe('good')
        ->and($copy->fresh()->availability_status)->toBe('damaged');

    // The librarian makes it available deliberately.
    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->putJson("/api/library/copies/{$copy->copy_id}", ['availability_status' => 'available'])->assertOk();

    expect($copy->fresh()->availability_status)->toBe('available');
});

test('DM5: a damaged copy stays damaged after check-in, and the loan still closes', function () {
    $copy = csCopy($this->book);
    $loan = csLoan($this->student, $copy);

    // A pre-existing contradiction the guards now prevent creating via the API.
    $copy->forceFill(['availability_status' => 'damaged'])->save();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])->assertOk();

    expect($loan->fresh()->status)->toBe('returned')
        ->and($copy->fresh()->availability_status)->toBe('damaged');
});

test('DM6: checking in a damaged copy does not promote the next hold onto it', function () {
    $copy = csCopy($this->book);
    $loan = csLoan($this->student, $copy);
    $waiting = csHold($this->other, $this->book, 'pending', null, null, 1);

    $copy->forceFill(['availability_status' => 'damaged'])->save();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])->assertOk();

    expect($waiting->fresh()->status)->toBe('pending')
        ->and($waiting->fresh()->copy_id)->toBeNull()
        ->and($copy->fresh()->availability_status)->toBe('damaged');
});

test('DM7: a copy whose CONDITION is damaged is returned as damaged, never available', function () {
    $copy = csCopy($this->book);
    $loan = csLoan($this->student, $copy);
    csHold($this->other, $this->book, 'pending', null, null, 1);

    $copy->forceFill(['condition' => 'damaged'])->save();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])->assertOk();

    expect($copy->fresh()->availability_status)->toBe('damaged');
});

test('DM8: a copy on loan cannot be marked damaged by hand', function () {
    $copy = csCopy($this->book);
    csLoan($this->student, $copy);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->putJson("/api/library/copies/{$copy->copy_id}", ['condition' => 'damaged'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'This copy is on loan. Check it in first, then update its status.');

    expect($copy->fresh()->availability_status)->toBe('checked_out')
        ->and($copy->fresh()->condition)->toBe('good');
});

test('DM9: a copy on loan may still have a non-damaging condition noted', function () {
    $copy = csCopy($this->book);
    csLoan($this->student, $copy);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->putJson("/api/library/copies/{$copy->copy_id}", ['condition' => 'fair'])->assertOk();

    expect($copy->fresh()->condition)->toBe('fair')
        ->and($copy->fresh()->availability_status)->toBe('checked_out');
});

/* --------------------------------------------------------------------- LOST */

test('LO1: a lost copy cannot be checked out', function () {
    $copy = csCopy($this->book);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->putJson("/api/library/copies/{$copy->copy_id}", ['availability_status' => 'lost'])->assertOk();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->student->user_id, 'copy_id' => $copy->copy_id])
        ->assertStatus(422);
});

test('LO2: a lost copy stays lost after check-in', function () {
    $copy = csCopy($this->book);
    $loan = csLoan($this->student, $copy);
    $copy->forceFill(['availability_status' => 'lost'])->save();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])->assertOk();

    expect($loan->fresh()->status)->toBe('returned')
        ->and($copy->fresh()->availability_status)->toBe('lost');
});

test('LO3: checking in a lost copy does not promote the next hold', function () {
    $copy = csCopy($this->book);
    $loan = csLoan($this->student, $copy);
    $waiting = csHold($this->other, $this->book, 'pending', null, null, 1);
    $copy->forceFill(['availability_status' => 'lost'])->save();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])->assertOk();

    expect($waiting->fresh()->status)->toBe('pending');
});

test('LO4: lost is not a condition', function () {
    $copy = csCopy($this->book);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->putJson("/api/library/copies/{$copy->copy_id}", ['condition' => 'lost'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('condition');
});

test('LO5: a normal check-in still promotes the next borrower (queue flow unchanged)', function () {
    $copy = csCopy($this->book);
    $loan = csLoan($this->student, $copy);
    $waiting = csHold($this->other, $this->book, 'pending', null, null, 1);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])->assertOk();

    expect($waiting->fresh()->status)->toBe('pending_approval')
        ->and((int) $waiting->fresh()->copy_id)->toBe($copy->copy_id)
        ->and($copy->fresh()->availability_status)->toBe('on_hold');
});

/* ---------------------------------------------------------- HOLD PROMOTION */

test('PR1: a freed copy that is damaged is never promoted to the next borrower', function () {
    // A copy set aside for one borrower, then (legacy row) marked damaged.
    $copy = csCopy($this->book, 'on_hold');
    $first = csHold($this->student, $this->book, 'pending_approval', $copy->copy_id);
    $waiting = csHold($this->other, $this->book, 'pending', null, null, 1);
    $copy->forceFill(['availability_status' => 'damaged'])->save();

    // Cancelling the first hold frees the copy and tries to promote the queue.
    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->deleteJson("/api/library/holds/{$first->hold_id}")
        ->assertOk();

    expect($waiting->fresh()->status)->toBe('pending')
        ->and($waiting->fresh()->copy_id)->toBeNull()
        ->and($copy->fresh()->availability_status)->toBe('damaged');
});

test('PR2: a freed copy that is archived is never promoted to the next borrower', function () {
    $copy = csCopy($this->book, 'on_hold');
    $first = csHold($this->student, $this->book, 'pending_approval', $copy->copy_id);
    $waiting = csHold($this->other, $this->book, 'pending', null, null, 1);
    $copy->forceFill(['archived_at' => now()])->save();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->deleteJson("/api/library/holds/{$first->hold_id}")
        ->assertOk();

    expect($waiting->fresh()->status)->toBe('pending');
});

test('PR3: a freed usable copy is still promoted (unchanged flow)', function () {
    $copy = csCopy($this->book, 'on_hold');
    $first = csHold($this->student, $this->book, 'pending_approval', $copy->copy_id);
    $waiting = csHold($this->other, $this->book, 'pending', null, null, 1);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->deleteJson("/api/library/holds/{$first->hold_id}")
        ->assertOk();

    expect($waiting->fresh()->status)->toBe('pending_approval')
        ->and((int) $waiting->fresh()->copy_id)->toBe($copy->copy_id);
});

/* ------------------------------------------------------------ MANUAL STATUS */

test('MS1: checked_out cannot be set by hand', function () {
    $copy = csCopy($this->book);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->putJson("/api/library/copies/{$copy->copy_id}", ['availability_status' => 'checked_out'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('availability_status');

    expect($copy->fresh()->availability_status)->toBe('available');
});

test('MS2: on_hold cannot be set by hand', function () {
    $copy = csCopy($this->book);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->putJson("/api/library/copies/{$copy->copy_id}", ['availability_status' => 'on_hold'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('availability_status');
});

test('MS3: a copy set aside for pickup cannot be hand-changed', function () {
    $copy = csCopy($this->book, 'on_hold');
    csHold($this->other, $this->book, 'fulfilled', $copy->copy_id);

    foreach (['available', 'lost', 'damaged'] as $status) {
        $this->withHeaders(csAs('Admin', 'cs_admin'))
            ->putJson("/api/library/copies/{$copy->copy_id}", ['availability_status' => $status])
            ->assertStatus(422);
    }

    expect($copy->fresh()->availability_status)->toBe('on_hold');
});

test('MS4: a copy awaiting hold approval cannot be hand-changed', function () {
    $copy = csCopy($this->book, 'on_hold');
    csHold($this->other, $this->book, 'pending_approval', $copy->copy_id);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->putJson("/api/library/copies/{$copy->copy_id}", ['availability_status' => 'lost'])
        ->assertStatus(422);
});

/* ------------------------------------------------------------------ RESERVE */

test('RS1: a lost copy cannot be explicitly allocated to a reserve', function () {
    $reserve = csReserve($this->book, $this->faculty);
    $copy = csCopy($this->book, 'lost');

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate", ['copy_ids' => [$copy->copy_id]])
        ->assertStatus(422);

    expect($copy->fresh()->reserve_id)->toBeNull();
});

test('RS2: a damaged copy cannot be explicitly allocated to a reserve', function () {
    $reserve = csReserve($this->book, $this->faculty);
    $copy = csCopy($this->book, 'damaged', 'damaged');

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate", ['copy_ids' => [$copy->copy_id]])
        ->assertStatus(422);

    expect($copy->fresh()->reserve_id)->toBeNull();
});

test('RS3: an archived copy cannot be explicitly allocated to a reserve', function () {
    $reserve = csReserve($this->book, $this->faculty);
    $copy = csCopy($this->book);
    $copy->forceFill(['archived_at' => now()])->save();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate", ['copy_ids' => [$copy->copy_id]])
        ->assertStatus(422);

    expect($copy->fresh()->reserve_id)->toBeNull();
});

test('RS4: a checked-out copy cannot be explicitly allocated to a reserve', function () {
    $reserve = csReserve($this->book, $this->faculty);
    $copy = csCopy($this->book);
    csLoan($this->student, $copy);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate", ['copy_ids' => [$copy->copy_id]])
        ->assertStatus(422);
});

test('RS5: an available copy is still allocated, and re-submitting it is harmless', function () {
    $reserve = csReserve($this->book, $this->faculty);
    $copy = csCopy($this->book);

    foreach ([1, 2] as $attempt) {
        $this->withHeaders(csAs('Admin', 'cs_admin'))
            ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate", ['copy_ids' => [$copy->copy_id]])
            ->assertOk();
    }

    expect((int) $copy->fresh()->reserve_id)->toBe($reserve->reserve_id);
});

test('RS6: auto-allocation never picks an archived copy', function () {
    $reserve = csReserve($this->book, $this->faculty);
    $copy = csCopy($this->book);
    $copy->forceFill(['archived_at' => now()])->save();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/allocate")
        ->assertStatus(422);

    expect($copy->fresh()->reserve_id)->toBeNull();
});

/* ------------------------------------------------------------- COPY ARCHIVE */

test('AC1: a free copy can be archived, with who and why recorded', function () {
    $copy = csCopy($this->book);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/copies/{$copy->copy_id}/archive", ['reason' => 'Withdrawn: water damage'])
        ->assertOk()
        ->assertJsonPath('copy.is_archived', true);

    $fresh = $copy->fresh();
    expect($fresh->archived_at)->not->toBeNull()
        ->and((int) $fresh->archived_by)->toBe($this->admin->user_id)
        ->and($fresh->archive_reason)->toBe('Withdrawn: water damage');
});

test('AC2: archiving keeps the accession number, condition and status', function () {
    $copy = csCopy($this->book, 'damaged', 'damaged');
    $copy->accession_number = 'ABC-LIB-000777';
    $copy->save();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/copies/{$copy->copy_id}/archive")->assertOk();

    $fresh = $copy->fresh();
    expect($fresh->accession_number)->toBe('ABC-LIB-000777')
        ->and($fresh->condition)->toBe('damaged')
        ->and($fresh->availability_status)->toBe('damaged');
});

test('AC3: an archived copy is not counted as available', function () {
    $copy = csCopy($this->book);
    csCopy($this->book);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/copies/{$copy->copy_id}/archive")->assertOk();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->getJson("/api/library/books/{$this->book->book_id}")
        ->assertOk()
        ->assertJsonPath('available_copies_count', 1)
        ->assertJsonPath('active_copies_count', 1);
});

test('AC4: a hold never picks an archived copy', function () {
    $copy = csCopy($this->book);
    $copy->forceFill(['archived_at' => now()])->save();

    $this->withHeaders(csAs('Student', 'cs_student'))
        ->postJson("/api/library/books/{$this->book->book_id}/holds")
        ->assertStatus(201);

    expect(Hold::first()->status)->toBe('pending')
        ->and(Hold::first()->copy_id)->toBeNull()
        ->and($copy->fresh()->availability_status)->toBe('available');
});

test('AC5: a course reserve request never picks an archived copy', function () {
    $reserve = csReserve($this->book, $this->faculty);
    CourseSectionStudent::create(['section_id' => $reserve->section_id, 'student_id' => $this->student->user_id]);

    $copy = csCopy($this->book, 'available', 'good', $reserve->reserve_id);
    $copy->forceFill(['archived_at' => now()])->save();

    $this->withHeaders(csAs('Student', 'cs_student'))
        ->postJson("/api/library/reserves/{$reserve->reserve_id}/request")
        ->assertStatus(422);

    expect($copy->fresh()->availability_status)->toBe('available');
});

test('AC6: an archived copy cannot be checked out', function () {
    $copy = csCopy($this->book);
    $copy->forceFill(['archived_at' => now()])->save();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->student->user_id, 'copy_id' => $copy->copy_id])
        ->assertStatus(422);

    expect(Transaction::count())->toBe(0);
});

test('AC7: an archived copy stays in the librarian view of the title', function () {
    $copy = csCopy($this->book);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/copies/{$copy->copy_id}/archive")->assertOk();

    $copies = $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->getJson("/api/library/books/{$this->book->book_id}")
        ->json('copies');

    expect(collect($copies)->firstWhere('copy_id', $copy->copy_id)['is_archived'])->toBeTrue();
});

test('AC8: archiving keeps the copy\'s loan history', function () {
    $copy = csCopy($this->book);
    $loan = csLoan($this->student, $copy);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson('/api/library/checkin', ['transaction_id' => $loan->transaction_id])->assertOk();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/copies/{$copy->copy_id}/archive")->assertOk();

    expect(Transaction::where('copy_id', $copy->copy_id)->count())->toBe(1);
});

/* ----------------------------------------------------------------- BLOCKERS */

test('BK1: an active loan blocks archiving', function () {
    $copy = csCopy($this->book);
    csLoan($this->student, $copy);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/copies/{$copy->copy_id}/archive")
        ->assertStatus(422);

    expect($copy->fresh()->archived_at)->toBeNull();
});

test('BK2: a pending_approval hold on the copy blocks archiving', function () {
    $copy = csCopy($this->book, 'on_hold');
    csHold($this->other, $this->book, 'pending_approval', $copy->copy_id);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/copies/{$copy->copy_id}/archive")
        ->assertStatus(422);
});

test('BK3: a Ready for Pickup hold on the copy blocks archiving', function () {
    $copy = csCopy($this->book, 'on_hold');
    csHold($this->other, $this->book, 'fulfilled', $copy->copy_id);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/copies/{$copy->copy_id}/archive")
        ->assertStatus(422);
});

test('BK4: allocation to a live reserve blocks archiving', function () {
    foreach (['approved', 'pending'] as $status) {
        $reserve = csReserve($this->book, $this->faculty, $status);
        $copy = csCopy($this->book, 'available', 'good', $reserve->reserve_id);

        $this->withHeaders(csAs('Admin', 'cs_admin'))
            ->postJson("/api/library/copies/{$copy->copy_id}/archive")
            ->assertStatus(422);

        expect($copy->fresh()->archived_at)->toBeNull();
    }
});

test('BK5: an already archived copy cannot be archived again', function () {
    $copy = csCopy($this->book);

    $this->withHeaders(csAs('Admin', 'cs_admin'))->postJson("/api/library/copies/{$copy->copy_id}/archive")->assertOk();
    $this->withHeaders(csAs('Admin', 'cs_admin'))->postJson("/api/library/copies/{$copy->copy_id}/archive")->assertStatus(422);
});

test('BK6: the last usable copy cannot be archived while borrowers are waiting', function () {
    $copy = csCopy($this->book);
    csCopy($this->book, 'lost');
    csHold($this->other, $this->book, 'pending', null, null, 1);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/copies/{$copy->copy_id}/archive")
        ->assertStatus(422)
        ->assertJsonPath('error', fn ($e) => str_contains($e, 'last copy that can serve them'));
});

test('BK7: a copy may be archived when another usable copy can still serve the queue', function () {
    $copy = csCopy($this->book);
    $onLoan = csCopy($this->book);
    csLoan($this->student, $onLoan); // will come back — still counts as usable
    csHold($this->other, $this->book, 'pending', null, null, 1);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/copies/{$copy->copy_id}/archive")
        ->assertOk();
});

test('BK8: archiving an already-damaged copy is allowed even with a waiting queue', function () {
    $copy = csCopy($this->book, 'damaged', 'damaged');
    csHold($this->other, $this->book, 'pending', null, null, 1);

    // It could never have served the queue, so archiving it changes nothing for them.
    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/copies/{$copy->copy_id}/archive")
        ->assertOk();
});

/* ------------------------------------------------------------ STALE RESERVE */

test('ST1: a pointer to a denied or released reserve is cleared on archive', function () {
    foreach (['denied', 'released'] as $status) {
        $reserve = csReserve($this->book, $this->faculty, $status);
        $copy = csCopy($this->book, 'available', 'good', $reserve->reserve_id);

        $this->withHeaders(csAs('Admin', 'cs_admin'))
            ->postJson("/api/library/copies/{$copy->copy_id}/archive")
            ->assertOk();

        expect($copy->fresh()->reserve_id)->toBeNull()
            ->and($copy->fresh()->archived_at)->not->toBeNull();
    }
});

/* ------------------------------------------------------------------ RESTORE */

test('RE1: restoring keeps a damaged copy damaged', function () {
    $copy = csCopy($this->book, 'damaged', 'damaged');
    $this->withHeaders(csAs('Admin', 'cs_admin'))->postJson("/api/library/copies/{$copy->copy_id}/archive")->assertOk();

    $this->withHeaders(csAs('Admin', 'cs_admin'))->postJson("/api/library/copies/{$copy->copy_id}/restore")->assertOk();

    $fresh = $copy->fresh();
    expect($fresh->archived_at)->toBeNull()
        ->and($fresh->archived_by)->toBeNull()
        ->and($fresh->archive_reason)->toBeNull()
        ->and($fresh->condition)->toBe('damaged')
        ->and($fresh->availability_status)->toBe('damaged');
});

test('RE2: restoring keeps a lost copy lost', function () {
    $copy = csCopy($this->book, 'lost');
    $this->withHeaders(csAs('Admin', 'cs_admin'))->postJson("/api/library/copies/{$copy->copy_id}/archive")->assertOk();
    $this->withHeaders(csAs('Admin', 'cs_admin'))->postJson("/api/library/copies/{$copy->copy_id}/restore")->assertOk();

    expect($copy->fresh()->availability_status)->toBe('lost');
});

test('RE3: restoring an available copy leaves it available and borrowable', function () {
    $copy = csCopy($this->book);
    $this->withHeaders(csAs('Admin', 'cs_admin'))->postJson("/api/library/copies/{$copy->copy_id}/archive")->assertOk();
    $this->withHeaders(csAs('Admin', 'cs_admin'))->postJson("/api/library/copies/{$copy->copy_id}/restore")->assertOk();

    expect($copy->fresh()->availability_status)->toBe('available');

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson('/api/library/checkout', ['user_id' => $this->student->user_id, 'copy_id' => $copy->copy_id])
        ->assertStatus(201);
});

test('RE4: a copy cannot be restored while its title is archived', function () {
    $copy = csCopy($this->book);
    $this->withHeaders(csAs('Admin', 'cs_admin'))->postJson("/api/library/copies/{$copy->copy_id}/archive")->assertOk();

    $this->book->archived_at = now();
    $this->book->save();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/copies/{$copy->copy_id}/restore")
        ->assertStatus(422)
        ->assertJsonPath('error', fn ($e) => str_contains($e, 'Restore the title first.'));

    expect($copy->fresh()->archived_at)->not->toBeNull();
});

test('RE5: a copy that is not archived cannot be restored', function () {
    $copy = csCopy($this->book);

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/copies/{$copy->copy_id}/restore")
        ->assertStatus(422);
});

/* ------------------------------------------------------------------- COUNTS */

test('CT1: total includes archived, active excludes it, available excludes archived, lost and damaged', function () {
    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->postJson("/api/library/books/{$this->book->book_id}/copies", ['quantity' => 4])
        ->assertStatus(201);

    [$a, $b, $c] = BookCopy::where('book_id', $this->book->book_id)->orderBy('copy_id')->take(3)->get()->all();

    $this->withHeaders(csAs('Admin', 'cs_admin'))->postJson("/api/library/copies/{$a->copy_id}/archive")->assertOk();
    $this->withHeaders(csAs('Admin', 'cs_admin'))->putJson("/api/library/copies/{$b->copy_id}", ['availability_status' => 'lost'])->assertOk();
    $this->withHeaders(csAs('Admin', 'cs_admin'))->putJson("/api/library/copies/{$c->copy_id}", ['condition' => 'damaged'])->assertOk();

    $this->withHeaders(csAs('Admin', 'cs_admin'))
        ->getJson("/api/library/books/{$this->book->book_id}")
        ->assertOk()
        ->assertJsonPath('total_copies', 4)          // physical copies, archived included
        ->assertJsonPath('active_copies_count', 3)   // archived excluded
        ->assertJsonPath('available_copies_count', 1); // only the untouched copy
});

/* ----------------------------------------------------------------- SECURITY */

test('SC1: only Admin and Super Admin may archive or restore a copy', function () {
    $copy = csCopy($this->book);

    foreach ([['Student', 'cs_student'], ['Teacher', 'cs_faculty']] as [$role, $username]) {
        $this->withHeaders(csAs($role, $username))->postJson("/api/library/copies/{$copy->copy_id}/archive")->assertStatus(403);
        $this->withHeaders(csAs($role, $username))->postJson("/api/library/copies/{$copy->copy_id}/restore")->assertStatus(403);
    }

    expect($copy->fresh()->archived_at)->toBeNull();

    $this->withHeaders(csAs('Super Admin', 'cs_super'))->postJson("/api/library/copies/{$copy->copy_id}/archive")->assertOk();
    $this->withHeaders(csAs('Super Admin', 'cs_super'))->postJson("/api/library/copies/{$copy->copy_id}/restore")->assertOk();
});

test('SC2: an archived title is not found for Student or Faculty, but is for librarians', function () {
    $this->book->archived_at = now();
    $this->book->save();

    $id = $this->book->book_id;

    $this->withHeaders(csAs('Student', 'cs_student'))->getJson("/api/library/books/{$id}")->assertStatus(404);
    $this->withHeaders(csAs('Teacher', 'cs_faculty'))->getJson("/api/library/books/{$id}")->assertStatus(404);
    $this->withHeaders(csAs('Admin', 'cs_admin'))->getJson("/api/library/books/{$id}")->assertOk();
    $this->withHeaders(csAs('Super Admin', 'cs_super'))->getJson("/api/library/books/{$id}")->assertOk();
});

test('SC3: a live title is still visible to borrowers', function () {
    $this->withHeaders(csAs('Student', 'cs_student'))
        ->getJson("/api/library/books/{$this->book->book_id}")
        ->assertOk();
});
