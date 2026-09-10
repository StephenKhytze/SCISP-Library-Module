# Library Module — Current State

**Repo:** `SCISP-Library-Module` · **Branch:** `lyndon`
**Verified:** 2026-09-08, against the live working tree, running containers and database
**Last updated:** 2026-09-10 — records SEC-05 and the automated pre-UAT validation
**Status:** Simplified Library scope **implemented and verified**. Automated pre-UAT **PASS**. **Manual UAT pending.** JWT integration not started.

> ### ⏳ Manual UAT is still pending and must be performed by a human.
> Automated pre-UAT validation passed (see §14). It does **not** replace human UAT.
> The manual script is in `LIBRARY_MANUAL_UAT.md` and has not yet been executed.

> Every statement below was re-checked against the actual repository at the time of writing —
> route table, service code, database schema, test files and test runs. It is not a plan or a
> summary of intent; it describes what the module does today.

---

## 1. Implemented Features

| Area | Feature | Status |
|---|---|---|
| Catalog | Search across title / author / ISBN / shelf (server-side) | ✅ |
| Catalog | Pagination — the whole catalog is reachable, not just page 1 | ✅ |
| Catalog | Category filter sourced from real catalog data | ✅ |
| Catalog | Book details, live availability, per-copy condition + status, shelf location | ✅ |
| Inventory | Admin add title (with copies) | ✅ |
| Inventory | Admin edit title (title, author, category, ISBN, shelf) | ✅ |
| Inventory | Admin add copies to an existing title | ✅ |
| Inventory | Admin set copy condition / availability status | ✅ |
| Circulation | Check-out (librarian-operated) | ✅ |
| Circulation | Check-in / return, with final fine applied | ✅ |
| Circulation | Active loans, borrow / due / return dates | ✅ |
| Circulation | Role-based due dates | ✅ |
| Circulation | Transaction history (own + admin-wide) | ✅ |
| Circulation | Renew | ✅ |
| Circulation | Server-enforced borrowing limits | ✅ |
| Fines | ₱10 per overdue calendar day, partial day rounds up | ✅ |
| Fines | Overdue detection while a book is still out (derived) | ✅ |
| Fines | Borrower sees own balance | ✅ |
| Fines | Admin Paid / Waived settlement, partial allowed | ✅ |
| Fines | Outstanding balance blocks further borrowing | ✅ |
| Holds | Place hold, FCFS queue, correct positions, hold status | ✅ |
| Holds | Admin accept / cancel / release a stuck hold | ✅ |
| Reserves | Faculty request; admin approve / deny / allocate / release | ✅ |
| Reserves | Borrower-based eligibility | ✅ |
| Security | Role restrictions, `user_id` ownership, cross-user prevention | ✅ |
| Security | Mock-auth identity hardening | ✅ |
| Quality | Test database safety barrier | ✅ |

**API surface: 33 routes** under `/api/library`.

---

## 2. Student Capabilities

**Can:**
- Search, filter and page through the whole catalog; view book details, availability, copy condition/status and shelf location
- View own active loans, with overdue flag, days overdue and estimated fine
- View own borrowing history (active + returned)
- View own outstanding fine balance
- Place a hold; view own holds and queue position; cancel own hold
- Renew **own** active loan
- View course reserves for sections they are enrolled in (approved reserves, teacher's note)

**Cannot:**
- Check out or check in (librarian-operated — `POST /checkout`, `POST /checkin` are Admin/Super Admin only)
- View another user's loans, history, holds or fines
- View the system-wide fines list or the admin circulation list
- Read the student directory (`GET /students` returns 403 — SEC-05)
- Settle or waive any fine
- Approve, deny, allocate or release a course reserve
- Manage inventory
- Borrow while owing a balance, or beyond 3 active loans

---

## 3. Faculty Capabilities

Everything a Student can do, plus:

**Can:**
- Borrow up to **10** active loans with a **14-day** due date
- Request a course reserve — **only for a section they own**
- Create course sections and manage that section's student roster *(frozen feature — see §15)*
- Renew a loan on a copy belonging to a reserve they own *(frozen allowance)*
- View their own sections, reserves, and each allocated copy's current borrower and due date

**Cannot:**
- Check out or check in
- Request a reserve against another teacher's section
- Approve, deny or allocate reserves (admin actions)
- Settle fines, or view the system-wide fines list

---

## 4. Admin / Super Admin Capabilities

**Admin and Super Admin are identical inside the Library module.** Both persona names are preserved for UI/demo purposes; no Library business rule distinguishes them. Both map to the database role `administrator`.

**Can:**
- Check out a copy to any eligible borrower, and check in any active loan
- View all active transactions, all returned transactions, or the full history (`?status=active|returned|all`)
- Renew **any** active loan
- View every borrower with an outstanding balance and the total outstanding
- Record a **Payment** or a **Waiver** against any borrower's balance, in full or in part
- Manage the hold queue: accept a pending-approval hold, check out to the holder, cancel/release any hold
- Manage inventory: add titles, edit titles, add copies, set copy condition and availability status
- Approve / deny / release a course reserve, and allocate physical copies to it
- Borrow with a **30-day** due date and **no borrowing limit**

**Cannot:**
- Bypass a borrower's course-reserve ineligibility (see §10)
- Set a copy with an active loan back to `available` (see §11)
- Drive a fine balance below zero

---

## 5. Circulation Rules

**Check-out** (`POST /api/library/checkout`, Admin/Super Admin only) evaluates, in order:

1. Copy exists
2. Borrower exists
3. **Borrower has no outstanding balance** — otherwise rejected
4. **Borrower is under their role's active-loan limit** — otherwise rejected
5. **Course-reserve eligibility**, judged on the borrower (§10)
6. Copy is `available`, or `on_hold` **for this borrower** with a fulfilled hold
7. Copy → `checked_out`; transaction created with `date_borrowed`, `due_date`, `status = active`
8. Any pending/fulfilled hold this borrower had on the title is cleared; if a different copy had been held for them, it is released to the next person in the queue

Concurrency is protected with `lockForUpdate()` on the copy row inside a transaction.

**Check-in** (`POST /api/library/checkin`, Admin/Super Admin only):
1. Transaction locked; must be `active` (double check-in rejected)
2. Final overdue fine calculated and **added to `users.total_fines`**
3. Transaction → `returned`, `actual_return_date` set
4. Copy either promotes the next hold in the queue, or returns to `available`

**Due dates** (`CirculationService::getDueDateForRole`):

| Borrower role | Loan period |
|---|---|
| Student | 7 days |
| Faculty | 14 days |
| Admin / Super Admin | 30 days |
| Course-reserve copy | 14 days (overrides the above) |

**Renew** (`POST /api/library/loans/renew`) is denied when:
- the transaction is not `active`
- another user is waiting via a hold (`pending` or `pending_approval`) on that title — reserve copies are exempt
- the caller is neither a librarian, the loan's owner, nor the owning teacher of the reserve

On success the due date is recomputed from the borrower's role.

---

## 6. Borrowing Limits

Enforced **server-side** in `CirculationService::checkout`, counted from `transactions` where `status = active`, and based on the **borrower's stored role**:

| Role | Max active loans |
|---|---|
| Student | **3** |
| Faculty | **10** |
| Admin / Super Admin | **unlimited** |

Rejection message: `Borrowing limit reached: {n} of {limit} active loans.` (HTTP 422)

The UI reads the same limit from `GET /api/library/me/summary` — it never hardcodes it — but the UI is not the enforcement point.

---

## 7. Fine Rules

- **Rate:** ₱10.00 per overdue calendar day (`FinesCalculator::$dailyRate`)
- **Rounding:** any partial day counts as a **full** day — `max(1, ceil(...))`
- **Authoritative balance:** `users.total_fines`. The separate `fines` table is **not** used
- **No** grace periods, caps, role-specific fine engines, policy configuration, accounting subsystem, payment gateway or financial processing

Worked examples (covered by tests C2–C4):

| Overdue by | Days charged | Fine |
|---|---|---|
| exactly 2 days | 2 | ₱20.00 |
| 2 days + 1 hour | 3 | ₱30.00 |
| 1 minute | 1 | ₱10.00 |
| not yet due | 0 | ₱0.00 |

**Overdue while still checked out** — derived at read time on `Transaction`, never written:

- `is_overdue` — `status = active` and `due_date` is past
- `days_overdue` — whole days, rounded up
- `estimated_fine` — what would be charged if returned now

These appear on every transaction payload. **Nothing is written to the database while a book is still out.** The fine is committed only at check-in.

---

## 8. Paid / Waived Settlement Rules

`POST /api/library/fines/settle` (Admin/Super Admin only).

| Field | Rule |
|---|---|
| `user_id` | required, must exist |
| `amount` | required, numeric, minimum 0.01 |
| `type` | required, exactly `paid` or `waived` |

Behaviour:
- **Paid** = the borrower settled externally. **Waived** = the librarian forgave the amount. Both reduce `users.total_fines`; they are separate, clearly-labelled actions in the UI.
- **Partial settlement is allowed** — ₱100 balance, ₱40 paid → ₱60 remaining.
- **The balance can never go below zero.** Settling more than owed applies only the outstanding amount and reports `applied_amount`.
- Settling against a zero balance is rejected (422).
- Runs inside a transaction with `lockForUpdate()` on the user row.
- The response states the applied amount and the new balance, e.g. `Fine waived: PHP 60.00. Remaining balance: PHP 0.00.`

**No payment ledger or accounting history is kept** — by design. Only the resulting balance persists.

**Borrowing block:** while `total_fines > 0`, check-out for that borrower is rejected. Recording a payment or waiving the balance re-enables it immediately.

---

## 9. Holds / Queue Rules

- **Placing a hold:** if a copy of the title is free, it is reserved for the requester (copy → `on_hold`, hold → `pending_approval`, awaiting librarian acceptance). If none is free, the requester joins the waitlist with the next `queue_position`.
- **One active hold per user per title** — a duplicate request is rejected.
- **FCFS:** the waitlist is ordered by `queue_position` ascending; the head is promoted first.
- **Positions renumber.** After a promotion or a cancellation, the remaining waitlist is resequenced to 1..N, so a queue never shows a gap such as "#2, #3" with no #1.
- **On return / cancellation:** the freed copy is offered to the next waiting user (`pending` → `pending_approval`, copy stays `on_hold`); if nobody is waiting, the copy returns to `available`.
- **Stuck holds:** a librarian can cancel any hold, which releases the copy back to circulation. This is the manual escape hatch so a copy cannot become permanently unavailable.
- **Ownership:** a user may cancel their own hold; a librarian may cancel any hold.
- **No scheduler or notification subsystem exists.** Hold expiry is not automated.

---

## 10. Course Reserve Rules

**Request** (`POST /api/library/reserves`, Faculty/Admin) — faculty may only file against a section they own; admins may file for any section.

**Lifecycle** — `pending` → `approved` / `denied`; `approved` → `released`. Approve, deny and allocate are Admin/Super Admin only. Release is available to an admin or the owning teacher. Releasing clears `reserve_id` from the reserve's copies.

**Allocation** (`POST /api/library/reserves/{id}/allocate`, Admin/Super Admin only):
- Works with **no request body** — auto-selects available copies of the reserve's own title, up to the number still outstanding against `copies_requested`
- Accepts an explicit `copy_ids` selection
- Rejects copies belonging to a different title, or already reserved elsewhere
- Rejects allocation to a reserve that is not `approved`
- Rejects when no available copies remain

**Borrow eligibility is judged on the BORROWER, never on the librarian operating check-out:**

| Borrower | Reserved copy |
|---|---|
| Student enrolled in the reserve's section | **Allowed** |
| Student **not** enrolled | **Denied** — an admin operator does not override this |
| Faculty | **Allowed** |
| Admin / Super Admin | **Allowed** |

Reserve copies carry a fixed 14-day loan period, and are exempt from the "holds block renewal" rule.

**Visibility:** each allocated copy exposes its current loan (`active_transaction`) with borrower and due date, so a teacher can see who holds each reserve copy.

---

## 11. Inventory / Copy Status Rules

Condition and availability are **separate** fields, as documented.

**Condition** — `new`, `good`, `fair`, `poor`. Physical quality only; does not by itself determine lendability.

**Availability status** — `available`, `checked_out`, `on_hold`, `lost`, `damaged`.

Consistency rules enforced:
- **A copy with an active loan cannot be hand-set to `available`.** It returns to the shelf through check-in, which also settles any fine. Attempting it returns 422; the UI disables the control for `checked_out` / `on_hold` copies.
- **`lost` and `damaged` copies are not borrowable** — check-out requires `available` (or `on_hold` for the holder).
- A free copy may be marked `lost` or `damaged` at any time.
- Manual status options in the UI are limited to `available`, `lost`, `damaged`; `checked_out` and `on_hold` are driven by circulation.

**Not implemented, by design:** no `archived` state machine, no status history, no audit trail, no repair-workflow engine.

**Availability counts** are computed live from `book_copies` (`available_copies_count`), not from a cached counter.

---

## 12. Security Protections

**Identity** (`MockAuthMiddleware`) — six explicit rejection cases:

| Condition | Response |
|---|---|
| Missing `X-Mock-Role` | 401 `Unauthorized. Missing X-Mock-Role header.` |
| Role not permitted for the route | 403 `Forbidden. Insufficient role privileges.` |
| Missing `X-Mock-Username` | 401 `Unauthorized. Missing X-Mock-Username header.` |
| Unknown username | 401 `Unauthorized. Unknown user.` |
| User `status = disabled` | 403 `Forbidden. User account is disabled.` |
| Supplied role ≠ stored role (compared at mapped DB-role level) | 403 `Forbidden. Supplied role does not match the user account.` |

- **No fallback identity.** The old silent `2012-00000-SYS` default is gone.
- **No request-path user creation.** Mock auth resolves users; it never creates them. Personas come from `MockPersonaSeeder`.
- Admin and Super Admin both map to `administrator`; the raw header is preserved in the `role` request attribute so route gates can still distinguish the persona names.

**Route-level authorization** — 16 routes carry an explicit role list. Admin/Super Admin only: `POST/PUT /books`, `POST /books/{id}/copies`, `PUT /copies/{id}`, `checkout`, `checkin`, `GET /circulation`, `GET /fines`, `POST /fines/settle`, `GET /holds`, `PUT /holds/{id}/accept`, `GET /reserves`, `POST /reserves/{id}/allocate`. Faculty-inclusive: `POST /reserves`, `PUT /reserves/{id}/status`, `GET /students` (SEC-05). The API role matrix exercising these gates passed 63/63 during pre-UAT validation (§14.1).

**Ownership and cross-user prevention:**
- `GET /loans/me`, `/loans/me/history`, `/holds/me`, `/sections/me`, `/me/summary`, `/fines/me` are all scoped to the caller
- Hold cancellation: owner or librarian
- Reserve release: owning teacher or librarian
- Reserve creation: section owner or librarian
- Renew: loan owner, reserve owner, or librarian
- Every created record stores the acting `user_id`

**Business rules enforced server-side, not only in the UI:** borrowing limits, fines block, reserve eligibility, copy-status consistency, settlement floor at zero.

---

## 13. Test Safety Architecture

Four independent layers prevent automated tests from ever touching the live MySQL database.

| Layer | Mechanism |
|---|---|
| 1a | `phpunit.xml` — **21** `<env … force="true">` entries. Without `force`, PHPUnit skips a variable that already exists in the environment, and docker-compose exports `DB_CONNECTION=mysql` |
| 1b | `phpunit.xml` — **8** `<server>` entries. Laravel's Dotenv reads `ServerConstAdapter` (`$_SERVER`) **before** `EnvConstAdapter` (`$_ENV`), so `<env>` alone is silently defeated |
| 2 | `Tests\TestCase::refreshApplication()` guard — inspects the **resolved** connection and hard-aborts (`exit(1)`) if it is a protected database. Placed before `setUpTraits()`, the only window in which aborting still prevents data loss |
| 3 | `TestDatabaseSafetyTest` — always-on assertions that the resolved connection is sqlite `:memory:` |

Tests resolve to **SQLite `:memory:`**. The protected list defaults to `laravel` and is overridable via `TEST_PROTECTED_DATABASES`.

**Run tests inside Docker** (host PHP is 8.2; PHPUnit needs 8.3+):

```bash
docker exec scisp_backend php artisan test
```

The guard's own behaviour is verified by an env-gated self-test:

```bash
docker exec -e GUARD_SELFTEST=1 scisp_backend php artisan test --filter="guard aborts"
```

---

## 14. Automated Test Results

Last full run, 2026-09-09, inside Docker, as part of the automated pre-UAT validation (§14.1):

| Suite | File | Result |
|---|---|---|
| Pre-UAT end-to-end flows | `tests/Feature/Library/PreUatEndToEndTest.php` | **7 passed** (48 assertions) |
| Student directory access (SEC-05) | `tests/Feature/Library/StudentDirectoryAccessTest.php` | **12 passed** (22 assertions) |
| Circulation rules | `tests/Feature/Library/CirculationRulesTest.php` | **25 passed** (40 assertions) |
| Fines & overdue | `tests/Feature/Library/FinesAndOverdueTest.php` | **14 passed** (43 assertions) |
| History, holds & reserves | `tests/Feature/Library/HistoryHoldsReservesTest.php` | **14 passed** (29 assertions) |
| Reserve authorization | `tests/Feature/Library/ReserveAuthorizationTest.php` | **27 passed** (54 assertions) |
| Mock-auth identity | `tests/Feature/Library/MockAuthIdentityTest.php` | **25 passed** (60 assertions) |
| Test DB safety | `tests/Feature/TestDatabaseSafetyTest.php` | **3 passed, 1 skipped** (7 assertions) |
| **Total** | | **127 passed, 1 skipped, 0 failed** (303 assertions) |

The skipped test is the guard self-test, which terminates the process by design and runs only with `GUARD_SELFTEST=1`.

> **Count correction.** The pre-UAT report of 2026-09-09 stated "134 passed". That was an
> arithmetic error: the per-suite results above sum to **127 passed, 1 skipped**, which also
> matches the 128 `test()` declarations across the eight files. 127 is the verified figure.

Live database verified unchanged after every run: `users 11 · books 3 · book_copies 5 · transactions 0 · holds 0 · course_reserves 1 · course_sections 2 · course_section_students 2`, no outstanding balances, reserve 1 → user 5.

### 14.1 Automated Pre-UAT Validation — **PASS** (2026-09-09)

> This validated the backend and the UI structure automatically. **It is not UAT.**
> Manual UAT is still pending and must be performed by a human.

| Check | Result |
|---|---|
| Environment — frontend, backend, `/library` reachable; MySQL healthy | ✅ PASS |
| Test isolation resolves to SQLite `:memory:` (confirmed **before** any test ran); all four safety layers intact | ✅ PASS |
| Automated regression | ✅ **127 passed, 1 skipped, 0 failed** |
| API role matrix against the real API and real personas | ✅ **63 / 63 PASS** |
| End-to-end logic flows | ✅ **All 9 validated** |
| UI structural pre-check (desktop and 390 px mobile) | ✅ PASS — subjective items deferred to human UAT |
| Live MySQL after validation | ✅ Unchanged |
| JWT work | Not started |

**API role matrix — 63 cases**

| Group | Cases | Result |
|---|---|---|
| Student — own-data reads allowed, admin lists and student directory denied | 13 | 13/13 |
| Student — every mutation route denied (settle, checkout, checkin, add book, update copy, reserve status, allocate, create reserve) | 8 | 8/8 |
| Identity edges — missing role 401, unknown username 401, role mismatch 403 | 3 | 3/3 |
| Faculty — member access, student directory and sections allowed; admin routes denied | 15 | 15/15 |
| Admin — circulation, history, fines, holds, reserves, directory, inventory, settlement validation | 12 | 12/12 |
| Super Admin — identical to Admin | 12 | 12/12 |

**End-to-end logic flows — all 9 validated in the isolated SQLite environment**

| Flow | Covered by |
|---|---|
| A. Hold → admin sees → accept → checkout to holder → correct copy/transaction state | `PreUatEndToEndTest` A1–A3 |
| B. Borrowing limit — Student 3 / Faculty 10 / librarian unlimited | `CirculationRulesTest` C5–C9 |
| C. Borrower with a balance cannot check out | `CirculationRulesTest` C10–C11; `FinesAndOverdueTest` F14; `PreUatEndToEndTest` X1 |
| D. Renew — own allowed, unrelated user denied, pending hold blocks | `CirculationRulesTest` C15–C19; `PreUatEndToEndTest` D1 |
| E. Overdue — 2 days = ₱20, 2 days + 1 hour = ₱30, applied at check-in | `CirculationRulesTest` C1–C4, C24–C25; `FinesAndOverdueTest` F1–F2 |
| F. Settlement — Paid, Waived, partial, floor at zero, unblocks borrowing | `FinesAndOverdueTest` F8–F14; `PreUatEndToEndTest` X1 |
| G. Course reserve — request → approve → allocate → eligible allowed, ineligible denied | `CirculationRulesTest` C12–C14; `HistoryHoldsReservesTest` H10–H14; `PreUatEndToEndTest` G1–G2 |
| H. Checked-out copy cannot be hand-set to available | `CirculationRulesTest` C20–C23 |
| I. Queue positions renumber after cancellation / promotion | `HistoryHoldsReservesTest` H7–H9 |

Flows A, D and G had no single end-to-end test before validation; `PreUatEndToEndTest` was added to walk them as a human tester would.

**Defect found and fixed during validation**

| | |
|---|---|
| Defect | **Mobile Borrowing History was missing.** The history section rendered at desktop width but not below the `lg` breakpoint. Backend data and ownership scoping were correct; only the mobile render was absent |
| Impact | Transaction history is documented scope; a tester on a phone would have failed it |
| Fix | Added the history to the mobile borrowing tab as a card list (a table overflows at phone width). Same data, same scoping, read-only |
| Verification | Confirmed rendering at 390 px; frontend build and lint clean; full regression re-run — 127 passed, 1 skipped |

**Manual UAT package** — `LIBRARY_MANUAL_UAT.md` contains **46 cases**: **38** marked AUTOMATION VERIFIED (backend rule proven; a human re-confirms it through the UI) and **8** marked REQUIRES HUMAN UAT (visual correctness, message clarity, mobile quality, error handling, usability). Actual Result, PASS/FAIL and Notes are blank for the tester.

> **Count correction.** The pre-UAT report stated 48 cases (39 / 9). The document itself contains
> **46 cases (38 / 8)**; 46 is the verified figure.

**Process note.** One API matrix check wrote to live data (a copy's condition). It was detected and restored to baseline before validation finished; the final database state above reflects that.

---

## 15. Frozen Features

Working, retained, **not to be expanded**.

| Feature | Note |
|---|---|
| Course sections & student rosters | Not in the documented ERD; documented faculty scope is only "request course reserve book". `course_reserves.section_id` depends on it, so removal is risky |
| Reserve notes (`teacher_to_admin_note`, `admin_to_teacher_note`, `teacher_to_student_note`) and `target_group` | Beyond documented scope; harmless columns. Do not build messaging on them |
| Hold `pending_approval` + admin accept step | The documented hold model is availability-based FCFS; the approval step is an addition. It works and the UI depends on it |
| `ExpireHolds` console command | Registered to no scheduler, so it never runs |
| `fines` table + `Fine` model | Undocumented in the ERD and written by nothing. `users.total_fines` is authoritative. Do **not** build a penalty subsystem on it |
| Teacher-renews-reserve-copy allowance | Pre-existing path, kept alongside the confirmed own-loan/librarian rule |

---

## 16. Deferred Features

| Item | Reason |
|---|---|
| **JWT / real authentication** | Explicitly deferred to the end. Mock auth remains |
| Dead middleware cleanup (`RequireAdminRole`, `ExternalAuthMiddleware`) | Unregistered; harmless |
| `LibraryController@index` placeholder route | Returns a stub message |
| Loose debug scripts at `backend/` | Nine files, several with hardcoded DB credentials |
| `library_recovery_scratch` database | Post-incident artifact, retained until recovery sign-off |
| Notifications, hold expiry automation, business-day fine calendar, per-fine ledger, audit subsystem, archive lifecycle | Explicitly out of scope |

---

## 17. Remaining Known Issues

| # | Issue | Severity | Note |
|---|---|---|---|
| 1 | ~~`GET /library/students` returns every student to **any** authenticated role~~ | ✅ **Closed** | **SEC-05 fixed.** Route now gated to Super Admin, Admin, Teacher, Faculty; students get 403. Covered by `StudentDirectoryAccessTest` (12 tests). Faculty and librarians can still read the full directory — that is the confirmed policy |
| 2 | `books.total_copies` only ever increments | Low | Marking a copy `lost`/`damaged` does not decrement it, so "N of M available" counts destroyed copies in M. Availability (N) is correct — it is computed live |
| 3 | SQLite skips the `holds.status` enum migration | Low | Tests cannot catch enum-violation bugs. Production MySQL is unaffected |
| 4 | Host PHP is 8.2; `composer.json` requires ^8.3 | Medium | Tests and the HTTP kernel only work inside Docker |
| 5 | A `pending_approval` hold pins a copy indefinitely | Low | Nothing expires it automatically. A librarian can cancel/release it manually |
| 6 | Six earlier planning documents were deleted mid-session | Info | `LIBRARY_AUDIT.md`, `LIBRARY_PRODUCT_RULES.md`, `LIBRARY_TASK_001.md`, `LIBRARY_TASK_IDENTITY.md` are gone and were never recreated. Not code |
| 7 | Nothing is committed | Info | All work is uncommitted in the working tree |
| 8 | **Manual UAT not yet executed** | **Blocking for sign-off** | Automated pre-UAT passed (§14.1), but `LIBRARY_MANUAL_UAT.md` (46 cases) has not been run. Manual UAT is still pending and must be performed by a human |
| 9 | ~~Mobile Borrowing History missing~~ | ✅ **Closed** | Found during pre-UAT validation and fixed; see §14.1 |

---

## 18. Final Manual Test Checklist

> **The authoritative manual UAT script is `LIBRARY_MANUAL_UAT.md`** — 46 cases with preconditions,
> exact steps, expected results and blank Actual / PASS-FAIL / Notes columns. This section is a quick
> summary only. **Manual UAT is still pending and must be performed by a human.**

Run inside Docker with the app at `http://localhost:5173/library`. Switch personas with the Topbar user menu.

### Student (`DelaCruz_Juan_C1234`)
- [ ] Catalog search finds a title that is **not** on the first page
- [ ] Category chips filter correctly and show real categories
- [ ] Pagination Previous/Next work; counts are accurate
- [ ] "View Copies" shows each copy's barcode-style id, condition and status
- [ ] Borrowing tab shows real active-loan count and `n / 3` limit — never a hardcoded 0
- [ ] Fine balance shows the real amount
- [ ] Borrowing History lists active and returned loans — a table on desktop, a card list on mobile
- [ ] Place a hold on an unavailable title; own hold appears with a queue position
- [ ] Cancel own hold
- [ ] Renew own loan from the loan detail modal
- [ ] Admin tabs (Circulation Desk, Admin Fines & Queue, Add Title & Copies) are **not** visible

### Faculty (`Santos_Maria_F12`)
- [ ] Course Reserves tab shows own sections
- [ ] Request a course reserve for an owned section — succeeds
- [ ] Allocated reserve copies show current borrower and due date
- [ ] Loan privilege shows Max 10 Books (14-Day Loan)

### Admin (`Admin_User_00001`) and Super Admin (`SysAdmin_001`)
- [ ] Both personas see identical Library capabilities
- [ ] Badge reads LIBRARIAN; "No borrowing limit (30-Day Loan)"
- [ ] Stat tiles show real values (titles, available, checked out, overdue, total system fines)
- [ ] Check out a copy to a student — succeeds
- [ ] Check out a 4th book to that student — **rejected** (limit 3)
- [ ] Check in an overdue loan — fine appears on the borrower's balance
- [ ] Check out to a borrower who owes — **rejected**; record a payment, then it succeeds
- [ ] Fines tab: Record Payment for part of a balance — remainder is correct
- [ ] Fines tab: Waive the remainder — balance reaches ₱0.00 and the row disappears
- [ ] Circulation Desk: Active / All History / Returned toggle changes the list
- [ ] Hold queue: Accept a pending-approval hold, then Check Out to the holder
- [ ] Hold queue: Cancel/Release a hold — the copy returns to circulation
- [ ] Add Title & Copies → Manage Inventory: Edit Title saves
- [ ] Manage Copies: add copies; change a copy's condition
- [ ] Manage Copies: a `checked_out` copy's status control is disabled
- [ ] Course Reserves: approve a pending reserve, then Allocate Physical Copies — succeeds
- [ ] Check out a reserved copy to an **unenrolled** student — **rejected** even though an admin is operating

### Cross-cutting
- [ ] Mobile layout (< lg) offers the same hold-queue, fines, inventory and borrowing-history features as desktop
- [ ] A student cannot load the student directory; a teacher's roster dropdown still populates (SEC-05)
- [ ] No placeholder values anywhere (no fake ₱150.00, no fake fine cards, no hardcoded counts)
- [ ] A failed load shows an error banner with Retry, not an empty library

---

## 19. Remaining Work Before JWT

Ordered. None of this is blocked.

1. ~~**SEC-05** — restrict `GET /library/students` to faculty/admin~~ ✅ **Done** — route gated; 12 tests
1a. **Manual UAT** — execute `LIBRARY_MANUAL_UAT.md` with a human tester and record results. *Must happen before the module is signed off*
2. **Environment** — resolve host PHP 8.2 vs required 8.3+, so tests and the app run outside Docker *(currently the single biggest developer-experience blocker)*
3. **Cleanup** — delete the nine loose debug scripts at `backend/`, the unregistered `RequireAdminRole` / `ExternalAuthMiddleware`, and the `LibraryController@index` placeholder
4. **`library_recovery_scratch`** — drop once recovery validation is signed off
5. **Optional** — decide whether `books.total_copies` should be computed live (issue 2 in §17)
6. **Commit the work** — the entire simplified-scope implementation is uncommitted

### Then, for JWT (SEC-01)

The contract is already narrow: **two request attributes**, `role` and `user_id`, are the only interface between identity and the rest of the Library. No controller or service reads an `X-Mock-*` header — only `MockAuthMiddleware` does.

| Concern | Today | After JWT | Changes? |
|---|---|---|---|
| Identity source | `X-Mock-Username` header | `sub` claim | middleware only |
| Resolution | `User::where('username', …)->first()` | `User::find($payload['sub'])` | middleware only |
| Missing / unknown identity | 401 | 401 | **same** |
| Disabled user | 403 | 403 | **same** |
| Role gating | `MockAuthMiddleware:…$roles` | unchanged | **none** |
| `role` / `user_id` attributes | set by middleware | set by middleware | **none** |
| All consumers | read attributes | read attributes | **none** |

`VerifyJwtToken` already rejects unknown and disabled users, so the two paths have converged. Keep it that way: no new header reads outside the middleware.

---

## 20. Definition of Done

### Functional — all met
- [x] Four roles see and do only what their role permits
- [x] Catalog search reaches every book, not just the first page
- [x] Book info, live availability, per-copy condition/status and shelf location display correctly
- [x] Admin can add **and edit** titles, and manage copies from the UI
- [x] Check-out and check-in work with correct borrow, due and return dates
- [x] Transaction history viewable — own for members, all for admin
- [x] Holds queue FCFS with correct positions, releasing to the next borrower on return
- [x] Overdue active loans are detected and visibly flagged
- [x] Fines calculated correctly — one rate, whole days rounded up
- [x] Members see their own balance; admin can Record Payment and Waive
- [x] Faculty get the extended period **and** an enforced limit
- [x] Faculty request reserves; admin approves and **successfully allocates copies**

### Security — all met
- [x] Every documented action is role-restricted server-side, not just hidden in the UI
- [x] Every record carries the correct `user_id`; no fallback identity, no request-path user creation
- [x] No user can read or act on another user's loans, holds, fines or reserves
- [x] Mock auth isolates the four personas; the JWT swap remains a single-file change

### Quality — all met
- [x] 127 automated tests pass (1 skipped by design), 0 failed
- [x] Automated pre-UAT validation passed — 63/63 API role matrix, all 9 end-to-end flows (§14.1)
- [x] Each completed feature has allowed-role, denied-role and ownership coverage
- [x] Tests never touch the live MySQL database
- [x] No hardcoded placeholder values remain in the Library UI
- [x] Frontend builds clean with no lint warnings in changed files

### Scope discipline — all met
- [x] No payment processing, accounting subsystem, policy engine or audit subsystem added
- [x] Frozen items still work and were not expanded
- [x] No unrelated module modified

### Outstanding before the module is closed
- [x] SEC-05 `/students` restriction
- [ ] Environment fix (PHP 8.3+)
- [ ] Dead-code and debug-script cleanup
- [ ] **Manual UAT executed and signed off by a human** (`LIBRARY_MANUAL_UAT.md`) — still pending
- [ ] Commit the work
- [ ] JWT integration (final phase)
