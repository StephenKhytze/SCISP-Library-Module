# Library Module — Final Audit

**Date:** 2026-09-12
**Type:** Read-only audit. No source changed, no migrations run, no live data mutated.
**Basis:** current repository state after the frontend polish, frontend completion and backend integration passes.

Everything below was verified against the code as it stands, not against the prior reports. Where I could not reach a state, I say so.

---

## Summary

| Severity | Count |
|---|---|
| CRITICAL | 0 |
| HIGH | 4 |
| MEDIUM | 8 |
| LOW | 7 |
| PRODUCT DECISION | 5 |
| DEFER | 2 |

The core workflows are sound. The serious findings are in **authorization on course sections** and in **course-reserve lifecycle edges** (release and deny), not in the flows a UAT script will walk.

---

## 1. ROLE / RBAC AUDIT

### Verified as correct

| Check | Result | Evidence |
|---|---|---|
| Student cannot reach librarian actions | PASS | admin group gate `:Super Admin,Admin` on checkout, checkin, circulation, fines, holds, reserves index, allocate, renewals decisions |
| Student sees only own loans / holds / fines | PASS | `myLoans`, `myHistory`, `myHolds`, `myFines`, `renewals/me` all scope on `attributes.user_id` |
| Faculty can borrow | PASS | no role restriction on the borrower beyond `canBorrow()`; limit 10 |
| Faculty can request a renewal | PASS | `POST /renewals` gate includes `Faculty,Teacher` |
| Faculty manage only their own sections | PASS | `index`, `addStudent`, `removeStudent` all filter `teacher_id = caller` |
| Faculty may release only their own reserve | PASS | `ReserveController::updateStatus` ownership check |
| Admin can borrow and run the desk | PASS | verified by test SA10/SA11 |
| Admin can approve/deny renewals | PASS | admin group gate |
| Super Admin cannot borrow / hold / renew / be a borrower | PASS | `User::canBorrow()` in `CirculationService::checkout`, `HoldService::placeHold`, `requestReserveCopy`, `RenewalService::request`, plus the route gate |
| Super Admin keeps librarian powers | PASS | test SA12–SA15 |
| Persona masquerade in both directions | PASS | `MockAuthMiddleware` compares the claimed persona to `users.is_super_admin` |

### Findings

---

#### F-01 · HIGH · Any authenticated user can create a course section and become its owner

**Files:** `backend/routes/api.php` (`POST /library/sections`), `backend/app/Http/Controllers/Api/CourseSectionController.php` (`store`, `addStudent`, `index`)

`POST /library/sections` sits in the bare-middleware group with **no role list**. `store()` sets `teacher_id` to the caller unconditionally. A Student can therefore create a section and become its "teacher".

That alone is only clutter. Combined with the two ownership-only checks beside it, it becomes a user-enumeration bypass of SEC-05:

1. `POST /library/sections {"name":"x"}` → 201, section owned by the student
2. `POST /library/sections/{id}/students {"student_id": N}` → the lookup is `where('teacher_id', caller)`, which matches. `student_id` is validated only as `exists:users,user_id` — **it is never checked to be a student**, so any user id can be enrolled.
3. `GET /library/sections` → returns the section eager-loading `students.student`, i.e. **whole `User` models**

`User` hides only `password` and the two-factor columns, so step 3 exposes `username`, `role`, `status`, `total_fines` and `is_super_admin` for **any user id the attacker chooses**. `GET /sections/{id}/classmates` gives the same names more conveniently (though it correctly returns only `user_id` + `username`).

**Why it matters:** SEC-05 deliberately closed `GET /library/students` to ordinary students. This route reopens it, and leaks more fields than the directory ever did — including other borrowers' fine balances.

**Note:** the hole pre-dates this pass (via `GET /sections`); the D-1 classmates endpoint did not create it.

**Minimal fix:** gate `POST /library/sections` to `Super Admin,Admin,Teacher,Faculty`, and restrict `addStudent`'s `student_id` to users whose role is a student. Separately, narrow the `students.student` eager load in `index` to `user_id,username`.

**Blocks final UAT:** no — UAT does not exercise it.
**Blocks JWT:** no. JWT authenticates; this is an authorization gap and would survive the swap unchanged.

---

#### F-02 · MEDIUM · `GET /library/sections` over-shares user records

**File:** `backend/app/Http/Controllers/Api/CourseSectionController.php:19`

`CourseSection::with(['students.student', ...])` serialises full `User` models to the owning teacher — including `total_fines`, `status` and `is_super_admin`. A teacher has no business seeing a student's fine balance in a roster.

**Minimal fix:** `with(['students.student:user_id,username', ...])`, matching what `classmates` already does.

**Blocks final UAT:** no. **Blocks JWT:** no.

---

#### F-03 · LOW · `addStudent` does not verify the target is a student

**File:** `CourseSectionController::addStudent`

`'student_id' => 'required|exists:users,user_id'` will happily enrol a faculty member or an administrator into a section. Harmless on its own; it is the amplifier for F-01.

**Minimal fix:** add an existence rule scoped to the student role.

---

## 2. RENEWAL WORKFLOW AUDIT

### Verified as correct

| Rule | Result | Evidence |
|---|---|---|
| Request does not move the due date | PASS | `RenewalService::request` writes only `renewal_requests`; test R3 asserts the date is unchanged |
| Approve moves it exactly once | PASS | status re-checked under the row lock; second approve → "already been decided" (R14) |
| Deny leaves the date alone | PASS | R15 |
| Duplicate pending blocked | PASS | R4, enforced under the transaction row lock |
| Returned / inactive loan blocked | PASS | R5 at request time, R13 at approval time |
| Wrong owner blocked | PASS | R6 |
| Waiting borrower blocks approval | PASS | R17; the borrower's own hold does not block (R18) |
| Reserve loan uses its own reserve queue | PASS | `hasWaitingBorrower` branches on `reserve_id`, not the title |
| Due date computed from the **borrower's** role | PASS | R9 — a faculty loan approved by an Admin still gets 14 days |

### Concurrency — reasoned through the lock order

| Race | Outcome | Why |
|---|---|---|
| Two admins approve the same request | Safe | `RenewalRequest::lockForUpdate()` then a **re-read** of `status`; the loser sees `approved` |
| Approve and deny simultaneously | Safe | same lock, same re-read |
| Loan returned mid-approval | Safe | `approve` also locks the transaction; `checkin` locks it too, so they serialise, and `approve` re-checks `status === 'active'` |
| Two borrowers requesting on the same loan | Not possible | requests are owner-scoped, and `request()` locks the transaction row before the duplicate check |
| Deadlock | Not reachable | `approve` locks renewal → transaction; `checkin` locks transaction → user. No path locks transaction → renewal, so there is no cycle |

No race found in this workflow. The design choice of locking the **transaction** (not the request) before the duplicate check is what makes it safe.

### Findings

---

#### F-04 · MEDIUM · The borrower is never told a renewal was approved or denied

**File:** `frontend/src/modules/library/LibraryPortal.jsx:3015`

The confirmed rule lists three borrower UI states: *Renewal Requested*, *Approved*, *Denied*. Only the first is implemented — the loan modal renders `renewal_status === 'pending' ? "Renewal Requested" : <Request Renewal button>`. After a decision the borrower simply sees the button again, with no indication of what happened. A denial is indistinguishable from never having asked.

The backend already supplies everything needed: `renewal_status` is `approved`/`denied`, and `renewal_requests.decision_note` carries the librarian's reason.

**Minimal fix:** two more branches in that ternary, reading the existing `renewal_status`.

**Blocks final UAT:** **yes, partially** — UAT-H2 asks the tester to confirm a denial, and the borrower-facing half of that is unobservable.
**Blocks JWT:** no.

---

#### F-05 · PRODUCT DECISION · A denied renewal can be re-requested immediately

**File:** `backend/app/Services/RenewalService.php`

The duplicate guard only looks for a `pending` request. Once denied, the borrower can ask again at once, and repeatedly — there is no cooldown and no attempt limit. Test R16 asserts this as current behaviour.

Technically valid, and arguably right (circumstances change). But it lets one borrower flood the librarian queue. Needs a human decision: allow, cap per loan, or require a cooldown.

**Blocks final UAT:** no. **Blocks JWT:** no.

---

#### F-06 · PRODUCT DECISION · Nothing limits how many times a loan may be renewed

There is no renewal counter. A cooperative librarian could extend the same loan indefinitely, 7 or 14 days at a time. The pre-existing `transactions` table has no `renewal_count`, and `renewal_requests` rows would have to be counted to derive one.

**Blocks final UAT:** no. **Blocks JWT:** no.

---

## 3. COURSE RESERVE AUDIT

### Verified as correct

The full chain works end to end — I drove it through the UI during the integration pass and re-read the code here:

faculty request → admin approve → allocate → enrolled student sees it → Request to Borrow → reserve-scoped hold → Ready for Pickup → librarian checkout → return → next in **that reserve's** queue promoted.

| Check | Result |
|---|---|
| General hold never consumes a reserve copy | PASS — `placeHold` filters `whereNull('reserve_id')`; test CR8 |
| Reserve request never consumes a general copy | PASS — `requestReserveCopy` filters `where('reserve_id', $id)`; test CR9 |
| Duplicate reserve request blocked | PASS — CR7 |
| Queue numbering independent per pool | PASS — CR10; `resequence()` branches on `reserve_id` |
| Promotion after return goes to the right queue | PASS — CR13 |
| Unrelated student refused | PASS — CR3 |
| Student cannot self-checkout | PASS — CR15 |
| Copy allocated to a different reserve | PASS — `allocateCopies` filters `where('book_id', $reserve->book_id)->whereNull('reserve_id')` |

### Findings

---

#### F-07 · HIGH · Releasing a reserve orphans its queue and its outstanding loans

**File:** `backend/app/Http/Controllers/Api/ReserveController.php:89-91`

```php
if ($validated['status'] === 'released') {
    BookCopy::where('reserve_id', $reserve->reserve_id)->update(['reserve_id' => null]);
}
```

That is the whole implementation. It ignores every dependent record:

- **Queued students are stranded.** Their holds keep `reserve_id` pointing at the released reserve, but no copy carries that `reserve_id` any more. `advanceReserveQueue` will never find a copy to give them, and `advanceQueue` (general) skips them because it filters `whereNull('reserve_id')`. They wait forever, and `sections/me` will keep reporting `queued` with a position.
- **A copy on loan loses its reserve link mid-loan.** On check-in, `CirculationService` computes `$isReserved` from `bookCopy->reserve_id`, which is now null, so it advances the **general** queue — handing a formerly-reserved copy to a general waiter while the reserve's own queue starves.
- **A `fulfilled` reserve hold keeps its `copy_id`** and the copy stays `on_hold`, now belonging to no reserve. Cancelling it calls `advanceReserveQueue`, which promotes the next queued student onto a copy that is no longer part of the reserve.

**Why it matters:** this is the documented, supported way to end a course reserve, and it silently corrupts the queue state rather than failing.

**Minimal fix:** inside a transaction, refuse the release while any allocated copy has an active loan (or handle it explicitly), and cancel-and-notify the reserve's outstanding holds — returning any `on_hold` copies to `available` — before clearing `reserve_id`.

**Blocks final UAT:** no, unless the tester releases a reserve that has queued students — worth adding as a case.
**Blocks JWT:** no.

---

#### F-08 · HIGH · Denying a reserve after allocation strands the copies

**File:** `ReserveController::updateStatus`

`denied` does **not** clear `reserve_id`. The copies stay allocated to a reserve that will never be borrowable: `requestReserveCopy` refuses anything not `approved`, and `available_copies_count` excludes every copy with a `reserve_id`.

The result is inventory that is invisible in the catalog, unborrowable through either pool, and recoverable only by a manual database edit or by re-approving and then releasing.

**Minimal fix:** clear `reserve_id` on `denied` exactly as `released` does (with the same dependent-record handling from F-07).

**Blocks final UAT:** no. **Blocks JWT:** no.

---

#### F-09 · MEDIUM · A student removed from a section keeps their reserve request

**Files:** `CourseSectionController::removeStudent`, `HoldService`

Enrolment is checked only at request time. Removing a student from the section deletes the `course_section_students` row and nothing else, so an existing `fulfilled` hold keeps a copy set aside for them, and a `pending` hold keeps their queue place.

Checkout would still refuse them (`CirculationService` re-checks enrolment for a student borrower), so a copy can sit `on_hold` for someone who can never collect it — with no path back to the section short of a librarian cancelling the hold manually.

**Minimal fix:** when removing a student, cancel their outstanding holds for that section's reserves.

**Blocks final UAT:** no. **Blocks JWT:** no.

---

#### F-10 · MEDIUM · No expiry on a set-aside reserve copy

**Files:** `HoldService::requestReserveCopy`, and the general hold path

The UI states that a Ready-for-Pickup copy is held "for 48 hours", but nothing enforces it. A student can take a reserve copy out of circulation indefinitely by requesting and never collecting. Pre-existing for general holds; D-3 extends it to reserves, where the pool is much smaller and the impact is proportionally larger.

**Blocks final UAT:** no. **Blocks JWT:** no. Needs a scheduled job, which is a separate phase.

---

#### F-11 · PRODUCT DECISION · A reserve request skips the "Getting Approval" step

**File:** `HoldService::requestReserveCopy` — the hold is created as `fulfilled`, not `pending_approval`

Deliberate: the reserve itself was already approved by a librarian, so a second approval is redundant, and it keeps the directly-requesting student and the promoted-from-queue student in the same state. But it does mean a student can set a physical copy aside with no librarian involved at all.

If the desk wants a gate, changing the initial status to `pending_approval` is a one-line change — but then the existing Accept step must also be applied to queue promotions for consistency.

---

#### F-12 · PRODUCT DECISION · Outstanding fines do not block a reserve request

**Files:** `HoldService::requestReserveCopy` / `placeHold` vs `CirculationService::checkout`

Fines block **checkout**, not requesting. A borrower with a balance can set a copy aside and only be refused at the desk. Not incorrect — the rule says fines block borrowing, and no borrowing occurs — but the feedback arrives late and the copy is held out of circulation in the meantime.

---

## 4. FINES / BORROWING RULES

### Verified as correct

| Rule | Result | Evidence |
|---|---|---|
| Fine charged only at check-in | PASS | `CirculationService::checkin` is the only writer of `total_fines` besides settlement |
| Overdue calculation | PASS | `FinesCalculator::overdueDays` — `max(1, ceil(abs(diff)))`, partial day rounds up |
| Partial paid / waived settlement | PASS | `FinesController::settle`, `$applied = min(amount, before)` |
| No negative balance | PASS | floored at zero, under `lockForUpdate` |
| Outstanding fines block checkout | PASS | `CirculationService::checkout` |

Settlement is correctly wrapped in `DB::transaction` with a row lock on the user, so two concurrent settlements cannot double-deduct.

### Interaction findings

- **Holds:** not blocked by fines (see F-12).
- **Course-reserve requests:** not blocked by fines (see F-12).
- **Renewal requests:** not blocked by fines. A borrower with a balance can request, and a librarian can approve, extending a loan for someone who owes money. **PRODUCT DECISION** — arguably right (extending is not new borrowing), arguably wrong (it rewards non-payment). Worth an explicit ruling.

No inconsistency found in the fine arithmetic itself.

---

## 5. INVENTORY / COPY STATE CONSISTENCY

### Verified as correct

| Transition | Result |
|---|---|
| Checkout → `checked_out` | PASS, under a copy row lock |
| Check-in → `available`, or `on_hold` if a queue was advanced | PASS |
| Hold promotion → `on_hold` + hold `pending_approval` (general) / `fulfilled` (reserve) | PASS |
| Copy with an active loan cannot be hand-set to `available` | PASS — guarded in `InventoryService::updateCopyStatus` |
| Reserve-allocated copy excluded from general availability | PASS — `generalAvailabilityCounts()`, tests AV1–AV4 |

### Findings

---

#### F-13 · MEDIUM · Manual copy-status edits can contradict live holds and loans

**Files:** `backend/app/Http/Controllers/Api/BookCopyController.php`, `backend/app/Services/InventoryService.php`

`updateCopyStatus` guards exactly one transition (→ `available` while a loan is active). Everything else in the enum is permitted unconditionally, so a librarian can reach:

| Manual edit | Resulting contradiction |
|---|---|
| `on_hold` copy → `lost` / `damaged` | The borrower's Ready-for-Pickup hold points at a copy that no longer exists to collect. Nothing cancels it. |
| `on_hold` copy → `checked_out` | Copy shows as borrowed with no transaction; invisible in Circulation, unborrowable, and the hold still dangles. |
| copy on loan → `lost` | Check-in later sets it straight back to `available`, silently un-losing it. |
| any copy → `checked_out` | Same orphan state as above. |

**Minimal fix:** in `updateCopyStatus`, refuse a manual transition when an active transaction or an open hold references the copy, or cancel the hold as part of the change.

**Blocks final UAT:** no. **Blocks JWT:** no.

---

#### F-14 · MEDIUM · `updateStatus` for reserves is not transactional

**File:** `ReserveController::updateStatus`

The mass `BookCopy::...->update(['reserve_id' => null])` and the subsequent `$reserve->save()` are two independent writes with no `DB::transaction` around them. A failure between them leaves copies detached from a reserve that is still marked `approved` — the copies vanish from the section while the reserve claims they are allocated.

**Minimal fix:** wrap the method body in `DB::transaction`.

---

#### F-15 · LOW · `books.total_copies` is a cached counter that can drift

**File:** `InventoryService::addCopies` increments it; nothing decrements it

There is no copy-deletion path today, so it cannot drift yet — but the counter is denormalised with no reconciliation, and the frontend renders "X of Y" from it. Worth noting before any delete feature lands.

---

## 6. CONCURRENCY / MULTI-USER AUDIT

Reasoned through the code; not stressed against any database, live or otherwise.

| Scenario | Verdict | Reasoning |
|---|---|---|
| Two admins check out the same copy | **Safe** | `BookCopy::lockForUpdate()` is the first statement inside the transaction; the loser re-reads `checked_out` and is refused |
| Two students request the last general copy | **Safe** | the free-copy select is `lockForUpdate`; the loser's `where availability_status = 'available'` no longer matches and it queues |
| Three students request one reserve copy | **Safe** for the copy; see F-16 for the duplicate-request edge |
| Two admins approve the same renewal | **Safe** | row lock + re-read |
| Two queue promotions race | **Safe** | promotion happens inside the check-in / cancel transaction, which already holds the copy lock |
| Checkout vs manual copy-status change | **Partially unsafe** | `updateCopyStatus` does **not** lock the copy and is not wrapped in a transaction, so it can overwrite a status set by a concurrent checkout. Narrow window, librarian-only. See F-17. |

---

#### F-16 · MEDIUM · Duplicate-request guards are checked outside any lock

**Files:** `HoldService::placeHold`, `HoldService::requestReserveCopy`

Both read their "do you already have one?" check before taking any row lock. Two concurrent identical requests from the same user can both pass it; one then wins the copy lock and gets `fulfilled`, the other queues. The user ends up holding **both** a set-aside copy and a queue place for the same title/reserve.

`RenewalService::request` does not have this problem — it locks the transaction row *before* its duplicate check, which is the pattern the other two should follow.

There is also no database-level uniqueness backing any of these guards: no unique index on `(user_id, book_id, status)` for general holds, `(user_id, reserve_id, status)` for reserve holds, or `(transaction_id)` where `status='pending'` for renewals.

**Minimal fix:** take the lock first (mirror `RenewalService::request`), and optionally add partial unique indexes as a backstop.

**Blocks final UAT:** no — it needs genuinely simultaneous requests from one account.
**Blocks JWT:** no.

---

#### F-17 · LOW · `updateCopyStatus` has no transaction or row lock

**File:** `InventoryService::updateCopyStatus`

Reads the copy, checks for an active loan, then writes — all unlocked. A concurrent checkout between the read and the write would be silently overwritten. Librarian-only and narrow, but it is the one write path in the module with no transaction boundary at all.

---

#### F-18 · LOW · `acceptHold` performs an unguarded read-modify-write

**File:** `HoldController::acceptHold`

Inside a transaction but with no `lockForUpdate`. Two admins accepting the same hold both read `pending_approval` and both write `fulfilled`. Idempotent, so the outcome is correct — but it is the only remaining state change that relies on luck rather than a lock.

---

## 7. PERFORMANCE AUDIT

The role-based and lazy-loading work holds up: Admin went 10 → 6 endpoints, Super Admin 10 → 3.

### Findings

---

#### F-19 · MEDIUM · The `renewal_status` append causes N+1 queries on three endpoints

**File:** `backend/app/Models/Transaction.php` — `$appends = [... 'renewal_status']`

The accessor falls back to `$this->latestRenewalRequest()->first()` when the relation is not loaded. Two endpoints eager-load it (`loans/me`, `loans/me/history`); three do not:

| Endpoint | File | Effect |
|---|---|---|
| `GET /library/circulation` | `CirculationController.php:120` | one extra query **per transaction row** — the largest list in the module |
| `GET /library/sections` | `CourseSectionController.php:19` | one per `copies.activeTransaction` |
| `GET /library/sections/me` | `CourseSectionController.php:93` | one per `copies.activeTransaction` |

Invisible at three books and four transactions; linear with the circulation log.

**Minimal fix:** add `latestRenewalRequest` to those three eager loads, or drop the accessor from `$appends` and expose it only where it is loaded.

**Blocks final UAT:** no. **Blocks JWT:** no.

---

#### F-20 · LOW · Both layouts are always mounted

Carried forward from the frontend verification (F-24 there). `lg:hidden` / `hidden lg:flex` means every control, effect and handler exists twice in the DOM and the accessibility tree. It does not double API calls — fetching lives in one component — but it doubles render work and makes `find`-by-text ambiguous.

**Blocks final UAT:** no. A rewrite, not a fix.

---

#### F-21 · LOW · Lazy panels refetch on every tab visit

`fetchLibrarianTab` runs on each `activeTab` change with no caching, so toggling Circulation → Fines → Circulation issues the circulation query three times. Correct and always fresh; simply not memoised. Acceptable as-is.

---

#### Not findings

- **StrictMode double-fetch** — development only; production builds invoke effects once.
- **CORS preflights** — same-origin in production.
- **`php artisan serve`** — dev server only.

---

## 8. FRONTEND / BACKEND CONTRACT AUDIT

### Fields verified present on both sides

| Field | Backend source | Frontend use | Status |
|---|---|---|---|
| `renewal_status` | `Transaction` append | loan modal | OK (but see F-04, F-19) |
| `student_request_status` | `CourseSectionController::attachCallerReserveState` | `StudentCourseReserveCard` | OK (but see F-22) |
| `queue_position` | same | queued branch | OK |
| `allocated_copies` | same | availability line | OK |
| `available_for_section` | same | availability line | OK |
| `can_borrow` | `mySummary` | hides My Borrowing, borrowing-rule card | OK |
| `is_super_admin` | `mySummary` | role chip | OK |
| `librarian.active_loans` | `mySummary` | Circulation Desk badge | OK |
| `librarian.pending_holds` / `debtors` / `pending_renewals` | `mySummary` | Fines & Queue badge | OK |
| `librarian.total_reserves` | `mySummary` | Course Reserves badge | OK |
| `classmates[]` | `classmates` | `ClassmatesModal` | OK |

---

#### F-22 · LOW · Two unreachable UI states in the reserve card

**File:** `frontend/src/modules/library/StudentCourseReserveCard.jsx:40`

```js
if (reqStatus === 'pending' || reqStatus === 'requested') { … "Requested / Waiting for the librarian" }
```

The backend emits exactly five values — `available`, `queued`, `ready_for_pickup`, `on_loan`, `unavailable`. Neither `pending` nor `requested` is ever produced, because a reserve request goes straight to `fulfilled` (F-11). The REQUESTED branch is dead code.

It becomes reachable only if F-11 is decided the other way. Leave it, or delete it — but it should not stay as an unlabelled maybe.

---

#### F-23 · LOW · `librarian.pending_reserves` is returned and never consumed

**File:** `CirculationController::mySummary`

Computed on every administrator summary call and used nowhere in the frontend. One wasted `COUNT` per request. Either surface it (a pending-reserve badge would be genuinely useful) or drop it.

---

#### F-24 · LOW · `summary.role` still drives the loan-days label

**File:** `LibraryPortal.jsx:686`

`loanDays` is derived from `summary.role` with a hardcoded 14/30/7 map, duplicating `CirculationService::getDueDateForRole`. If the loan periods ever change, two places must change together and nothing enforces it.

---

### No dead endpoint references

`grep -rn "loans/renew" frontend/src` returns nothing. Every endpoint the frontend calls exists in `route:list`.

---

## 9. DATABASE / MIGRATION AUDIT

Three migrations, reviewed statically. **None were run or rolled back during this audit.**

| Migration | Fresh DB | Existing DB | FK / index | Nullable / default |
|---|---|---|---|---|
| `add_is_super_admin_to_users_table` | safe | safe — `default(false)` backfills every existing row | no index; not needed (never filtered on alone) | sensible |
| `add_reserve_id_to_holds_table` | safe | safe — nullable, so existing holds become general holds, which is correct | `constrained(...)->nullOnDelete()` + implicit index | sensible |
| `create_renewal_requests_table` | safe | safe — new table | FKs cascade on transaction/user delete, `nullOnDelete` for `decided_by`; indexes on `(transaction_id,status)` and `(user_id,status)` | sensible |

### Findings

---

#### F-25 · MEDIUM · Rollback is untested

`down()` is written for all three, but none has been executed on either engine. `dropConstrainedForeignId` on SQLite requires a table rebuild; Laravel handles this natively in current versions, but "should work" is not "does work".

**Minimal fix:** run `migrate:refresh` against a throwaway SQLite file — **never** against the live MySQL database.

**Blocks final UAT:** no. **Blocks JWT:** no, but it should be proven before any environment needs a rollback.

---

#### F-26 · DEFER · No unique constraints backing the service-level guards

Covered in F-16. Partial/filtered unique indexes behave differently across MySQL and SQLite, so this is worth deferring to a dedicated data-integrity pass rather than bolting on now.

---

#### F-27 · LOW · `is_super_admin` must be set by hand for any new administrator

Nothing infers it. The seeder covers the four personas and the two live administrator rows were backfilled, but a Super Admin created later without the flag will be treated as an ordinary Admin — and, because the middleware now enforces the match, will be **rejected outright** if they send the `Super Admin` header. That failure mode is safe but opaque.

---

## 10. SECURITY AUDIT

| Check | Result |
|---|---|
| Super Admin masquerading as Admin | **Blocked** — `MockAuthMiddleware`, test SA2 |
| Admin masquerading as Super Admin | **Blocked** — test SA3 |
| Role escalation via route gates | No gap found in the admin group |
| Renewal approval authorization | Correct — admin group only, tests R11/R12 |
| Reserve-request authorization | Correct — `:Student` gate plus enrolment check, test CR3/CR4 |
| Classmates leakage | Correct — enrolled/owner/librarian only, no roster in the 403 body, tests CM5–CM7 |
| Student-directory exposure (SEC-05) | **Bypassable — see F-01** |
| IDOR on loans / holds / fines / renewals | None found; every self endpoint scopes on `attributes.user_id` |
| Mass assignment | `User::$fillable` now includes `is_super_admin` — see F-28 |
| Error leakage | Domain messages only on the new endpoints; `QueryException` is caught and genericised. Older endpoints still return `$e->getMessage()` — see F-29 |

---

#### F-28 · HIGH · `is_super_admin` is mass-assignable

**File:** `backend/app/Models/User.php` — `$fillable` includes `is_super_admin`

No Library endpoint accepts user creation or update, so there is no reachable exploit **from this module**. But `users` is shared: any other SCISP module doing `User::create($request->all())` or `$user->update($request->all())` would now let a caller set `is_super_admin` — and in this module that flag decides whether the middleware accepts a `Super Admin` header.

It is fillable only because the seeder and the test factories write it.

**Minimal fix:** remove it from `$fillable` and set it with `forceFill()` in the seeder and test helpers, as the seeder's backfill path already does.

**Blocks final UAT:** no. **Blocks JWT:** **worth fixing first** — JWT will introduce real user provisioning, which is exactly the path that makes this exploitable.

---

#### F-29 · MEDIUM · Older endpoints still return raw exception messages

**Files:** `CirculationController` (checkout, checkin), `HoldController` (store, destroy, acceptHold), `BookCopyController::update`, `ReserveController::allocateCopies`

These return `'error' => $e->getMessage()` with no filter. Today every throw in those paths is an authored domain message, so nothing leaks — but a `QueryException`, a `ModelNotFoundException` or any future runtime error would be handed to the client verbatim, potentially including SQL.

The renewal and reserve-request endpoints were hardened during the last pass; these were not, so the module is now inconsistent.

**Minimal fix:** apply the same `catch (QueryException)` → generic message guard to the remaining controllers.

**Blocks final UAT:** no. **Blocks JWT:** no.

---

## 11. DOCUMENTATION DRIFT

---

#### F-30 · MEDIUM · `LIBRARY_CURRENT_STATE.md` §14 test results are stale

The per-suite table still lists the eight pre-integration suites totalling **127 passed, 1 skipped**, and §20 repeats "127 automated tests". The current figure is **202 passed, 1 skipped (522 assertions)**, and the five new suites (`RenewalApprovalTest`, `SuperAdminRestrictionTest`, `CourseReserveRequestTest`, `ClassmatesAccessTest`, `AvailabilitySemanticsTest`) are absent.

The §21 section added in the last pass documents the new behaviour but did not reconcile §14 or §20.

---

#### F-31 · MEDIUM · `LIBRARY_CURRENT_STATE.md` §12 route authorization is stale

It states "**16 routes** carry an explicit role list" and enumerates them. The current count is 39 library routes with **6** parameterised middleware declarations, and the list omits `POST /renewals` (`Student,Faculty,Teacher,Admin`), `POST /reserves/{id}/request` (`Student`), and the three renewal decision routes inside the admin group. The claim "the API role matrix passed 63/63" refers to a matrix that predates five new endpoints.

---

#### F-32 · LOW · The 48-hour pickup promise is documented but unenforced

`LIBRARY_CURRENT_STATE.md` and the borrower-facing UI both state that a Ready-for-Pickup copy is held for 48 hours. No code implements it (F-10). The documentation should either describe it as intended-not-implemented or the behaviour should be built.

---

#### F-33 · LOW · `LIBRARY_MANUAL_UAT.md` case count is now unstated

The header previously tracked a case count (46, of which 38 automation-verified). Section H added 10 cases without updating any total, so the document no longer states how many cases a tester should expect.

---

## 12. ALL FINDINGS

| ID | Severity | Area | Finding | Blocks UAT | Blocks JWT |
|---|---|---|---|---|---|
| F-01 | **HIGH** | RBAC | Any user can create a section → enumerate users and their fines | no | no |
| F-07 | **HIGH** | Reserve | Releasing a reserve orphans its queue and outstanding loans | no | no |
| F-08 | **HIGH** | Reserve | Denying after allocation strands copies permanently | no | no |
| F-28 | **HIGH** | Security | `is_super_admin` is mass-assignable | no | **fix first** |
| F-02 | MEDIUM | RBAC | `GET /sections` serialises full User models | no | no |
| F-04 | MEDIUM | Renewal | Borrower never sees Approved / Denied | **partially** | no |
| F-09 | MEDIUM | Reserve | Removed student keeps their reserve request | no | no |
| F-10 | MEDIUM | Reserve | No expiry on a set-aside copy | no | no |
| F-13 | MEDIUM | Inventory | Manual status edits contradict live holds/loans | no | no |
| F-14 | MEDIUM | Reserve | `updateStatus` is not transactional | no | no |
| F-16 | MEDIUM | Concurrency | Duplicate-request guards outside the lock | no | no |
| F-19 | MEDIUM | Performance | `renewal_status` N+1 on three endpoints | no | no |
| F-25 | MEDIUM | Migration | Rollback untested | no | no |
| F-29 | MEDIUM | Security | Older endpoints return raw exception text | no | no |
| F-30 | MEDIUM | Docs | §14 test results stale (127 vs 202) | no | no |
| F-31 | MEDIUM | Docs | §12 route authorization stale | no | no |
| F-03 | LOW | RBAC | `addStudent` does not verify the target is a student | no | no |
| F-15 | LOW | Inventory | `total_copies` counter can drift | no | no |
| F-17 | LOW | Concurrency | `updateCopyStatus` has no transaction or lock | no | no |
| F-18 | LOW | Concurrency | `acceptHold` read-modify-write unguarded | no | no |
| F-20 | LOW | Performance | Both layouts always mounted | no | no |
| F-21 | LOW | Performance | Lazy panels refetch on every visit | no | no |
| F-22 | LOW | Contract | Two unreachable reserve-card states | no | no |
| F-23 | LOW | Contract | `pending_reserves` computed, never used | no | no |
| F-24 | LOW | Contract | Loan-days map duplicated in the frontend | no | no |
| F-27 | LOW | Migration | `is_super_admin` must be set by hand | no | no |
| F-32 | LOW | Docs | 48-hour pickup documented, unenforced | no | no |
| F-33 | LOW | Docs | UAT case count unstated | no | no |
| F-05 | PRODUCT | Renewal | Denied renewals re-requestable immediately | no | no |
| F-06 | PRODUCT | Renewal | No cap on renewals per loan | no | no |
| F-11 | PRODUCT | Reserve | Reserve request skips librarian approval | no | no |
| F-12 | PRODUCT | Fines | Fines do not block requests, only checkout | no | no |
| — | PRODUCT | Fines | Fines do not block renewal approval | no | no |
| F-26 | DEFER | Database | No unique constraints behind the guards | no | no |
| F-10 | DEFER | Reserve | Pickup expiry needs a scheduled job | no | no |

---

## 13. FINAL VERDICT

## B. READY WITH NON-BLOCKING ISSUES

A human can run the full manual UAT now. Every scripted flow works, 202 automated tests pass, and no finding corrupts data along the happy paths.

**But read this before deploying anywhere real:** F-01 lets any logged-in student enumerate other users — including their fine balances — by creating a course section. It does not block a UAT session, and it pre-dates this pass, but it is a genuine authorization hole and should not survive to production.

### Top 5 remaining risks

1. **F-01** — course-section creation is ungated, which reopens the directory exposure SEC-05 closed and leaks fine balances alongside it.
2. **F-07 / F-08** — the two reserve-lifecycle endings (release, deny) both leave the system inconsistent: orphaned queues in one case, permanently stranded inventory in the other.
3. **F-28** — `is_super_admin` is mass-assignable on a table shared with the rest of SCISP, and that flag is what the middleware trusts.
4. **F-04** — the renewal loop is only two-thirds visible to the borrower; a denial looks identical to never having asked.
5. **F-19** — the `renewal_status` append quietly turned the circulation list into an N+1. Harmless at three books; not at three thousand.

### Fix before manual UAT

Only one, and it is small:

- **F-04** — add the Approved and Denied branches to the loan modal. UAT-H2 asks the tester to verify a denial from the borrower's side, and right now there is nothing to see. Everything it needs is already in the payload.

### Can wait until after manual UAT

- **F-01, F-02, F-03** — the section authorization cluster. Fix together; one focused change.
- **F-07, F-08, F-09, F-14** — the reserve lifecycle cluster. Also best fixed together, inside one transaction-wrapped `updateStatus`.
- **F-19** — three eager loads.
- **F-13, F-16, F-17, F-18** — the consistency and locking cluster.
- **F-30, F-31, F-33** — documentation reconciliation.
- **F-05, F-06, F-11, F-12** and the fines-vs-renewal question — put the five product decisions in front of the owner as a batch; several of the fixes above depend on how they land.

### Can wait until after JWT

- **F-10 / pickup expiry** — needs a scheduler, which is its own phase.
- **F-20** — layout de-duplication is a rewrite.
- **F-26** — database-level uniqueness.
- **F-15, F-21, F-22, F-23, F-24, F-27, F-32** — cleanup.

### One exception to that ordering

**F-28 should be fixed before JWT, not after.** JWT brings real user provisioning, and provisioning is precisely the path that turns a mass-assignable privilege flag into an exploitable one. It is a one-line change to `$fillable` plus `forceFill` in the seeder and two test helpers.

---

*Audit only. No source modified, no migrations run, no live data touched.*
