# Library Scope Gap Analysis

**Repo:** `SCISP-Library-Module` · **Branch:** `lyndon`
**Date:** 2026-09-08
**Purpose:** Compare the current Library codebase against the submitted proposal/ERD/use-case/architecture scope, and identify the **minimum** work to finish the module.

> ### ⚠ Source-of-truth caveat — read first
>
> I have **not seen** the proposal, ERD, Use Case Diagram or Architecture Diagram directly. This analysis is grounded in two things:
> 1. The **documented core scope list** supplied in the task brief.
> 2. **`diagram_alignment_report.md`** (in this repo), which summarises the ERD entities, architecture blocks and use-case notes.
>
> Where those two disagree with the code, I flag it as a **QUESTION** rather than assuming. Anything marked ❓ needs a decision made against the real documents before implementation.
>
> All code claims below were **re-verified against the current working tree on 2026-09-08**, after Tasks 001, IDENTITY, TEST_SAFETY and 002. The previous `LIBRARY_AUDIT.md` was deleted and is not relied upon.

---

## 1. Documented Scope

### 1.1 From the task brief

| # | Area | Documented items |
|---|---|---|
| 1 | Role-based access | Student, Faculty/Teacher, Admin, Super Admin |
| 2 | Catalog / inventory | search/filter, view book info, view availability, physical copies, copy condition, copy availability status, shelf/location, admin add/edit titles, admin manage copies |
| 3 | Circulation | check-out, check-in/return, active loans, borrowing dates, dynamic due dates by role, **transaction history** |
| 4 | Holds | place hold, hold queue, FCFS, availability check, hold status |
| 5 | Fines / overdue | detect overdue returns, calculate a basic fine, show fines/overdue notices, admin manage or waive a fine. **No payment processing. No accounting subsystem** |
| 6 | Faculty-specific | extended borrowing period/limit, request course reserve book |
| 7 | Admin-specific | view transactions, manage inventory, manage fines, approve documented requests |
| 8 | Security | correct role restrictions, correct `user_id` ownership, prevent cross-user actions, keep mock auth, JWT at the end |

### 1.2 ERD entities, per `diagram_alignment_report.md`

| Entity | Documented columns |
|---|---|
| `books` | `book_title`, `author`, `category`, `isbn`, `physical_location`, `total_copies` |
| `book_copies` | `copy_id`, `book_id`, `condition`, `availability_status` |
| `transactions` | `transaction_id`, `user_id`, `copy_id`, `date_borrowed`, `due_date`, `actual_return_date`, `status` |
| `holds` | `hold_id`, `user_id`, `book_id`, `request_date`, `queue_position`, `status` |
| `users` | **External** user; the local table stores only library state — **`total_fines`** |

**Three consequences that reshape this analysis:**

1. **There is no `fines` table in the documented ERD.** Fines are a scalar `users.total_fines`. The `fines` table that exists in code is **outside documented scope**.
2. **There are no `course_sections` / `course_section_students` entities.** Faculty scope is only *"request course reserve book"*.
3. **`holds` has no `copy_id`** in the ERD; the implementation added one.

### 1.3 Architecture blocks, per the alignment report

Inventory Management · Search and Filter Engine · Circulation Manager · Fines Calculator · Hold and Queue Manager — **all five exist in code.**

### 1.4 Use-case note carried forward

The alignment report states the Use Case Diagram shows **Students and Faculty initiating "Check Out Book" and "Return Book"**, while the implementation placed both behind admin-only middleware. It flags this as an open adjustment. → **❓Q-1** in §5.

---

## 2. Already Complete

| # | Feature | Files | Status | Evidence |
|---|---|---|---|---|
| C1 | Four distinct roles, correct gating | `MockAuthMiddleware.php`, `routes/api.php` | ✅ Complete | Role list gates on routes; `MockAuthIdentityTest` 25/25 covers Student/Teacher/Admin/Super Admin |
| C2 | Correct `user_id` ownership | `transactions`, `holds`, `course_reserves`, `course_sections` | ✅ Complete | All ownership columns exist and are populated from request attributes |
| C3 | Identity integrity (no fallback, no auto-create) | `MockAuthMiddleware.php`, `MockPersonaSeeder.php` | ✅ Complete | Task IDENTITY; unknown/missing username → 401, role mismatch → 403, disabled → 403 |
| C4 | Cross-user prevention on holds | `HoldService::cancelHold` | ✅ Complete | Owner-or-admin check |
| C5 | Cross-user prevention on reserves | `ReserveController::updateStatus`, `::store` | ✅ Complete | Tasks 001 + 002; `ReserveAuthorizationTest` 27/27 |
| C6 | View book information | `InventoryService::searchBooks`, `BookController@index` | ✅ Complete | Title, author, category, ISBN, location returned |
| C7 | View availability (live) | `InventoryService` `withCount(... 'available')` | ✅ Complete | Computed live, not from a stale counter |
| C8 | Physical copies + condition + availability status | `book_copies` table, copy-inventory modal | ✅ Complete | Verified schema: `condition` enum, `availability_status` enum; UI lists per-copy state |
| C9 | Shelf / location | `books.physical_location` | ✅ Complete | Displayed on catalog cards and modal |
| C10 | Admin **add** title + copies | `BookController@store`, `InventoryService::addCopies` | ✅ Complete | Wired to three Add-Book forms |
| C11 | Check-out | `CirculationService::checkout` | ✅ Complete | `lockForUpdate()`, availability + hold checks |
| C12 | Check-in / return | `CirculationService::checkin` | ✅ Complete | Duplicate-checkin guard, advances hold queue |
| C13 | Active loans (own) | `CirculationController@myLoans` | ✅ Complete | Scoped to caller |
| C14 | Borrowing dates | `transactions.date_borrowed`, `due_date`, `actual_return_date` | ✅ Complete | All three populated |
| C15 | Dynamic due dates by role | `CirculationService::getDueDateForRole` | ✅ Complete | Student 7 / Faculty 14 / Admin 30 days |
| C16 | Place hold + availability check | `HoldService::placeHold` | ✅ Complete | Reserves a free copy, else waitlists |
| C17 | Hold queue, FCFS | `holds.queue_position`, `HoldService::advanceQueue` | ✅ Complete | Ordered `queue_position asc`; documented FCFS intent satisfied |
| C18 | Hold status | `holds.status` | ✅ Complete | pending / pending_approval / fulfilled / cancelled |
| C19 | Basic fine calculation | `FinesCalculator::calculateFine` | ✅ Complete *(value issues in §4)* | Computes days-late × rate on check-in |
| C20 | Faculty extended borrowing **period** | `getDueDateForRole` → 14 days | ✅ Complete | Documented "extended borrowing period" satisfied |
| C21 | Faculty request course reserve | `ReserveController@store`, `TeacherReservesView` | ✅ Complete | Request creation works and is now ownership-bound |
| C22 | Admin approve/deny reserve | `ReserveController@updateStatus` | ✅ Complete | Task 001 |
| C23 | Test isolation from live DB | `phpunit.xml`, `tests/TestCase.php` | ✅ Complete | Task TEST_SAFETY; 4-layer barrier verified |

---

## 3. Partially Complete

### P1 — Catalog search / filter
- **Works:** client-side search over title/author/ISBN/location; category chips; server-side `title/author/category/isbn` filters **exist** in `InventoryService::searchBooks`.
- **Missing:** the frontend calls `api.get('/library/books')` with **no parameters** (`LibraryPortal.jsx:52`) and `paginate(15)` means only the **first 15 books** are ever loaded or searchable. Category chips also compare against hardcoded uppercase legacy strings that no Add-Book value matches.
- **Minimum fix:** pass `searchQuery` to the existing `title` filter (debounced) and add simple page controls reading the paginator metadata already returned. Align the category chip values with the Add-Book `<select>` values. **Do not** redesign search.

### P2 — Admin manage inventory
- **Works:** add title + copies.
- **Missing:** `PUT /books/{id}` (edit title), `POST /books/{id}/copies` (add copies to existing title), `PUT /copies/{id}` (set condition / availability status) are **implemented, role-gated, and called by nothing** — verified: zero frontend references.
- **Minimum fix:** small admin UI for edit-title and copy-status. No backend work needed.

### P3 — Transaction history
- **Works:** `GET /circulation` (admin) and `GET /loans/me` (self) both return loans.
- **Missing:** both hard-filter `->where('status', 'active')` (`CirculationController.php:119,131`). Returned/completed transactions are **unreachable**, so documented "transaction history" does not exist.
- **Minimum fix:** allow a `status` (or `history=1`) query parameter to include `returned`. Roughly a two-line change per method.

### P4 — Show fines / overdue notices
- **Works:** fines are calculated on check-in and accumulated into `users.total_fines` — which **is** the documented ERD storage.
- **Missing:** no endpoint returns the caller's own `total_fines`; the student-facing balance is hardcoded `₱0.00` (2 occurrences verified). Admin fines table reads the **undocumented `fines` table**, which nothing writes, so it is always empty.
- **Minimum fix:** expose `total_fines` on a small self endpoint and render it. See **❓Q-2** on which storage is authoritative.

### P5 — Admin manage / waive a fine
- **Works:** `POST /fines/clear` exists.
- **Missing:** it acts on the empty `fines` table and clears **all** unpaid rows globally, ignoring its request body. There is no per-user waive.
- **Minimum fix:** if `users.total_fines` is authoritative (❓Q-2), implement waive as "set/adjust one user's `total_fines`", admin-only. That is a handful of lines and needs no new tables.

### P6 — Overdue detection
- **Works:** overdue **fee** is computed at check-in.
- **Missing:** nothing ever evaluates *currently* overdue active loans. A book never returned generates no fine and appears nowhere. `transactions.status = 'overdue'` is written by no code path (the assignment at `CirculationService.php:161` is immediately overwritten on line 164).
- **Minimum fix:** derive overdue at read time — `due_date < now() AND status = 'active'` — and surface a flag in `myLoans` / `circulation`. **No scheduler or job needed.**

### P7 — Renew
- **Works:** `POST /loans/renew` is implemented, with a correct "blocked while holds are pending" rule.
- **Missing:** `LibraryPortal.jsx` never calls it (verified: 0 references). The only caller is in `TeacherReservesView`, and that button never renders (see B5).
- **Minimum fix:** add a Renew button to the existing loan-detail modal. ❓ Renew is not explicitly in the documented scope list — see **Q-3**.

### P8 — Faculty extended **limit**
- **Works:** extended *period* (14 days).
- **Missing:** no borrowing **limit** is enforced anywhere — verified, no count check in `CirculationService`. The UI advertises 3 / 10 / 20 books.
- **Minimum fix:** count the borrower's active transactions in `checkout` and reject past a role limit. ~5 lines, no config engine.

---

## 4. Broken Features

### B1 — Admin cannot renew any loan · role comparison is case-sensitive
- **Documented:** Admin manages circulation.
- **Problem:** `CirculationService.php:208` compares against `['superadmin', 'admin']`, but the role attribute holds the raw header (`'Admin'`, `'Super Admin'`). No comparison ever matches, so an admin renewing a member's loan gets "You are not authorized to renew this book."
- **Minimum correction:** normalise the role once (the middleware already computes the DB role at lines 40–46) and compare against the normalised value. ⚠ Fixing this **activates** an unguarded `$reserve->user_id` dereference at lines 204-206 — add the null check in the same change.

### B2 — Course-reserve checkout gate over-blocks
- **Documented:** faculty course reserves must remain borrowable.
- **Problem:** `CirculationService.php:66` has the same case-sensitivity flaw, so the guard collapses to "borrower must be enrolled in the section", regardless of who operates the desk. An admin cannot lend a reserved copy to a faculty member.
- **Minimum correction:** same normalisation; decide the check against the **borrower's** stored role rather than the operator's.

### B3 — Fine amounts are wrong (rate + fractional days)
- **Documented:** "calculate a basic fine".
- **Problem:** `FinesCalculator.php:9` hardcodes **₱5.00/day** while the UI states ₱10.00 in seven places. Carbon 3's `diffInDays()` returns a **float**, so a 2-day-10-hour overdue yields ₱12.08.
- **Minimum correction:** one authoritative rate constant, and `(int) ceil(...)` on the day count. No policy engine.

### B4 — "Clear fine" is a global bulk update
- **Documented:** admin manages/waives *a* fine.
- **Problem:** `FinesController::clear` ignores its body and sets **every** unpaid row to Paid. The frontend binds it as `onClick={handleClearFine}`, so the handler receives a click event as its `fineId`.
- **Minimum correction:** scope to one target and fix the binding. Currently harmless only because the table is empty — it becomes data loss the moment fines are stored there.

### B5 — Faculty reserve-copy view shows wrong state; Renew is dead
- **Documented:** faculty course reserve visibility.
- **Problem:** `TeacherReservesView` reads `copy.active_transaction`, but no `activeTransaction` relation exists on `BookCopy` (verified: 0 occurrences) and `CourseSectionController` does not eager-load one. Every allocated copy displays "Available" even when checked out, and the Renew handler is unreachable.
- **Minimum correction:** add the `activeTransaction` relation and eager-load it. Laravel snake-cases relation keys, so the existing frontend code starts working unchanged.

### B6 — Course reserve copies can never be allocated
- **Documented:** faculty request course reserve → admin fulfils it.
- **Problem:** the frontend posts to `/reserves/{id}/allocate` with **no body**; the controller requires a `copy_ids` array. Every attempt returns 422, so an approved reserve can never receive physical copies.
- **Minimum correction:** make `copy_ids` optional and auto-select available copies of that book when absent. Keeps the one-click UI.

### B7 — Loan detail modal shows wrong data
- **Problem:** verified still present — `transaction_date` (column is `date_borrowed`) renders "Invalid Date"; `physical_condition` (column is `condition`) always falls back to "new".
- **Minimum correction:** two field-name fixes.

### B8 — Hardcoded UI values
- **Problem:** verified still present — "Your Active Book Loans (0)" ×2, "Max Allowed: 0" ×2, "₱0.00" ×2, plus two hardcoded fake fine cards on mobile (`FINE-101`, `FINE-102`).
- **Minimum correction:** bind to `myLoans.length`, the role limit, and the real balance; map the mobile fines list to state.

### B9 — Mobile admin hold queue is incomplete
- **Problem:** the mobile hold queue renders only "Cancel"; there is no Accept or Check-Out, and it maps unfiltered `holdRequests`. Admins on a phone cannot complete the documented hold workflow.
- **Minimum correction:** reuse the existing desktop handlers.

### B10 — Hold queue positions are never renumbered
- **Documented:** hold queue with FCFS ordering.
- **Problem:** when a hold is promoted or cancelled, remaining `pending` holds keep their original numbers, so the queue reads `#2, #3` with no `#1`. Order is still correct; the **displayed position** is wrong.
- **Minimum correction:** decrement remaining positions inside the existing transaction.

---

## 5. Missing Features

Only items traceable to the documented scope.

| # | Missing | Documented under | Note |
|---|---|---|---|
| M1 | **Transaction history** (returned loans) | Scope 3 | See P3 — small query change |
| M2 | **Own-fines view** for students/faculty | Scope 5 ("show fines") + alignment report ("View Fines … can easily be exposed") | See P4 |
| M3 | **Overdue notice / flag** on active loans | Scope 5 ("detect overdue returns") | See P6 — derive at read time |
| M4 | **Per-user fine waive** | Scope 5 + 7 | See P5 |
| M5 | **Admin edit title UI** | Scope 2 ("admin add/edit titles") | Backend exists, unwired |
| M6 | **Admin manage copies UI** | Scope 2 ("admin manage copies") | Backend exists, unwired |
| M7 | **Faculty/role borrowing limit** | Scope 6 ("extended borrowing period/**limit**") | See P8 |
| M8 | **Catalog reachable beyond 15 books** | Scope 2 ("search/filter books") | See P1 |

### ❓ Open questions — need the real documents

- **Q-1 — Who initiates checkout/return?** The alignment report says the Use Case Diagram shows *Students and Faculty* initiating "Check Out Book" / "Return Book", but `/checkout` and `/checkin` are **Admin/Super Admin only**. If the diagram is authoritative, this is a **missing feature** (self-service). If a librarian-operated desk was intended, current behaviour is correct. **Do not change without checking the diagram.**
- **Q-2 — Which fine storage is authoritative?** The ERD documents only `users.total_fines`, which the code correctly writes. The `fines` table is undocumented and unwritten. Confirm `total_fines` is authoritative so §3/§4 fixes stay minimal. *(This also supersedes the elaborate penalty model in the deleted `LIBRARY_PRODUCT_RULES.md` — see §6 X8.)*
- **Q-3 — Is Renew in scope?** It is implemented and partially wired but appears in neither the scope list nor the ERD. Finish wiring it, or freeze it?
- **Q-4 — Is `Super Admin` distinct from `Admin` anywhere in the documents?** Both collapse to `administrator` in the database; only the request header separates them.

---

## 6. Extra / Over-Engineered Functionality

**Nothing is deleted by this analysis.**

| # | Item | Files | Classification | Rationale |
|---|---|---|---|---|
| X1 | **Course sections + student rosters** | `course_sections`, `course_section_students`, `CourseSectionController`, `TeacherReservesView` | **FREEZE** | Not in the documented ERD. Documented faculty scope is only *"request course reserve book"*. But `course_reserves.section_id` now depends on it, so removal is risky. Leave working, **do not expand**. |
| X2 | **Reserve approval workflow** (`pending`→`approved`/`denied`/`released` + copy allocation) | `ReserveController`, `course_reserves` | **KEEP** | Justified by scope 7 "approve documented requests". B6 must be fixed for it to function. |
| X3 | **Three note fields** on reserves (`teacher_to_admin_note`, `admin_to_teacher_note`, `teacher_to_student_note`) + `target_group` | `course_reserves` | **FREEZE** | Beyond documented scope; harmless columns. Do not build more messaging on them. |
| X4 | **Hold `pending_approval` state + admin accept** | `HoldService`, `HoldController@acceptHold` | **FREEZE** | The documented hold model is FCFS availability-based; an admin approval step is an addition. It works and the UI depends on it — leave it, do not extend. ⚠ Side effect: a `pending_approval` hold pins a copy out of circulation indefinitely (nothing expires it). If librarians report "missing" copies, this is why. |
| X5 | **`holds.copy_id`** | migration `2026_09_07_010550` | **KEEP** | Not in the documented ERD, but genuinely needed to reserve a specific copy for a queued user. |
| X6 | **`ExpireHolds` command** | `Console/Commands/ExpireHolds.php` | **FREEZE** | Registered to no scheduler, so it never runs. Harmless. Only relevant if X4's stuck-copy problem surfaces. |
| X7 | **`fines` table** | migration `2026_09_06_060015`, `Fine` model, `FinesController` | **FREEZE** *(pending Q-2)* | Undocumented in the ERD and written by nothing. Do **not** build the penalty subsystem on it. If Q-2 confirms `total_fines`, this becomes REMOVE LATER. |
| X8 | **Configurable penalty policy model** (global role policies, grace, caps, waived actor/timestamp, policy snapshots) | *specified only, never implemented* | **DO NOT IMPLEMENT** | Explicitly excluded by the simplification rules ("no configurable policy engines", "no accounting subsystem"). Recorded here so nobody revives it from the earlier product-rules document. |
| X9 | **Book copy lifecycle proposal** (`archived` status, damage/repair notes, transition state machine) | *specified only, never implemented* | **DO NOT IMPLEMENT** | Documented scope needs only `condition` + `availability_status`, which exist. |
| X10 | **`LibraryController@index`** placeholder | `Library/LibraryController.php` | **REMOVE LATER** | Returns `{"message":"Library endpoint placeholder"}`. Dead route, zero risk either way. |
| X11 | **`RequireAdminRole`, `ExternalAuthMiddleware`** | `Http/Middleware/` | **REMOVE LATER** | Verified unregistered in `bootstrap/app.php` and all routes. `ExternalAuthMiddleware` contains a request-path `firstOrCreate`, contrary to the identity rules — dead, but delete when convenient. |
| X12 | **Debug scripts** (`check.php`, `fix_holds.php`, `migrate.php`, `test.php`, `patch.php`, …) | `backend/*.php` | **REMOVE LATER** | Nine loose scripts, five with hardcoded root DB credentials; two rewrite `vendor/` source. Not web-reachable under `public/`, but they should not ship. |
| X13 | **`library_recovery_scratch` database** | MySQL | **REMOVE LATER** | Post-incident artifact. Retain until recovery validation is signed off. |
| X14 | **Test-safety layer** | `phpunit.xml`, `tests/TestCase.php`, `TestDatabaseSafetyTest` | **KEEP** | Not a product feature, but it prevents a repeat of the live-database wipe. Non-negotiable. |

---

## 7. Security Required for Documented Features

Only what the documented functionality actually needs. Everything here is small.

| # | Item | Why documented scope needs it | Status |
|---|---|---|---|
| S1 | Role gates on reserve read/create/approve/allocate | Scope 7 "approve documented requests"; scope 8 role restrictions | ✅ **Done** — Tasks 001 + 002 |
| S2 | Identity integrity (`user_id` correct, no fallback, no auto-create) | Scope 8 "correct user ownership via user_id" | ✅ **Done** — Task IDENTITY |
| S3 | Role normalisation | Scope 8 role restrictions — **B1/B2 are security defects**, not just bugs: an admin is wrongly denied, and the reserve gate applies the wrong subject's role | ❌ **Required** |
| S4 | Own-fines scoping | Scope 5 "show fines" — a student must see **only** their own balance (M2) | ❌ **Required when M2 is built** |
| S5 | Own-history scoping | Scope 3 transaction history — `GET /loans/me` must stay caller-scoped when history is added (P3) | ❌ **Required when P3 is built** |
| S6 | Waive is admin-only | Scope 7 "manage fines" | ❌ **Required when M4 is built** |
| S7 | Borrowing limit enforced **server-side** | Scope 6 limit; a UI-only limit is not a limit | ❌ **Required with M7** |
| S8 | `GET /students` exposure | Returns the **entire student directory to any role**. Only used by the frozen roster UI (X1) | ⚠ **Restrict to faculty/admin** — one route move. Cheap; do it even though X1 is frozen |
| S9 | JWT integration | Scope 8 — explicitly deferred to the end | ⏸ **Deferred by decision** |

**Not required by documented scope** (do not build): audit-log subsystem, actor/`_by` columns, policy snapshots, fine history retention rules, rate limiting, `archived` transitions.

---

## 8. Recommended Completion Order

Each phase is independently shippable.

### Phase A — Fix broken core workflows *(highest value, smallest diffs)*
1. **B1 + B2 + S3** — role normalisation in `CirculationService`, plus the null guard B1 activates. *One file.*
2. **B3** — single fine rate + `ceil()` day count. *One file.*
3. **B7** — two field-name fixes in the loan modal.
4. **B6** — allocate copies without a body, so course reserves can be fulfilled.
5. **B5** — `activeTransaction` relation; revives the faculty reserve view.

### Phase B — Complete documented features
6. **P3 / M1** — transaction history via a status parameter (+ **S5**).
7. **P6 / M3** — derive overdue at read time; flag it in loans lists.
8. **P4 / M2** — expose own `total_fines` (+ **S4**). *Settle ❓Q-2 first.*
9. **P5 / M4 + B4** — per-user waive, admin-only (+ **S6**); fix the global clear.
10. **P8 / M7 + S7** — server-side borrowing limit.

### Phase C — Security for those workflows
11. **S8** — restrict `GET /students` to faculty/admin.
12. Re-verify S1/S2 still hold via the existing suites.

### Phase D — UI completeness
13. **B8** — replace hardcoded counters and the fake mobile fine cards.
14. **P1 / M8** — wire catalog search to the server filter, add pagination, reconcile category values.
15. **P2 / M5 + M6** — admin edit-title and copy-status UI (backend already exists).
16. **B9** — mobile hold-queue parity.
17. **B10** — renumber hold queue positions.

### Phase E — Optional / last
18. **P7 / ❓Q-3** — wire Renew, if in scope.
19. **❓Q-1** — self-service checkout, only if the Use Case Diagram requires it.
20. **X10–X13** cleanup (dead middleware, placeholder route, debug scripts, scratch DB).
21. **S9** — JWT integration.

---

## 9. Definition of Done

The Library module is finished for this project when **all** of the following are true.

### Functional
- [ ] Students, Faculty, Admin and Super Admin each see and can do only what their role permits
- [ ] Catalog search/filter reaches **every** book, not just the first page
- [ ] Book info, live availability, per-copy condition/status and shelf location all display correctly
- [ ] Admin can **add and edit** titles, and manage copies (add, set condition, set availability) from the UI
- [ ] Check-out and check-in work, with correct borrow date, role-based due date and return date
- [ ] **Transaction history** (returned loans) is viewable — own history for members, all for admin
- [ ] Holds can be placed, queue in first-come-first-served order, show a correct position, and release to the next person on return
- [ ] Overdue active loans are detectable and visibly flagged
- [ ] A basic fine is calculated correctly on late return — one agreed rate, whole days
- [ ] Members can see their own fine balance; admin can view and **waive** a fine
- [ ] Faculty get the extended borrowing period **and** an enforced limit
- [ ] Faculty can request a course reserve; admin can approve/deny **and successfully allocate copies**

### Security
- [ ] Every documented action is role-restricted server-side, not just hidden in the UI
- [ ] Every record carries the correct `user_id`; no fallback identity, no request-path user creation
- [ ] No user can read or act on another user's loans, holds, fines or reserves
- [ ] Mock auth still isolates the four personas; JWT swap remains a single-file change

### Quality
- [ ] `ReserveAuthorizationTest`, `MockAuthIdentityTest` and `TestDatabaseSafetyTest` all pass
- [ ] Each newly completed documented feature has at least one allowed-role, one denied-role and one ownership test
- [ ] Tests never touch the live MySQL database
- [ ] No hardcoded placeholder values remain in the Library UI

### Scope discipline
- [ ] No payment processing, accounting subsystem, configurable policy engine, or audit-log subsystem was added
- [ ] Items frozen in §6 still work but were not expanded
- [ ] ❓Q-1 … Q-4 were resolved against the real proposal/ERD/diagrams and recorded

### Explicitly **not** required
- Book copy `archived` lifecycle · per-fine ledger with waiver actor/timestamps · business-day fine calculation · notifications · hold expiry automation · JWT (until the final phase)
