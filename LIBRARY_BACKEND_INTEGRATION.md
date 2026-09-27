# Library Backend / Integration / Performance Pass — Report

**Date:** 2026-09-12
**Scope:** backend rules, D-1/D-2/D-3 integration, availability semantics, performance. Frontend treated as frozen except for wiring.
**Not started:** JWT.

---

## 1. ADMIN VS SUPER ADMIN IDENTITY MODEL

### What the audit found

| | Admin | Super Admin |
|---|---|---|
| `users.role` | `administrator` | `administrator` — **identical** |
| `X-Mock-Role` header | `Admin` | `Super Admin` |
| Request attribute `role` | raw header, preserved | raw header, preserved |
| `CirculationService::normalizeRole` | → `administrator` | → `administrator` |
| `isLibrarian()` | true | true — comment literally read *"Admin and Super Admin are the same inside the Library module"* |

So the two personas **were** distinguishable at the request level (the raw header survives into `$request->attributes`), but **not** in stored data.

### Why the request header could not carry the rule

`POST /api/library/checkout` takes `{ user_id, copy_id }`. **The borrower is not the caller.** They send no header at all — an Admin at the desk selects them. There is therefore no request-side signal that can answer *"is user 11 a Super Admin?"*, which is exactly the question the rule asks.

Worse, the header alone is not trustworthy for this: before this pass a Super Admin could simply send `X-Mock-Role: Admin` and pass every check, because the middleware compared the supplied role to the stored role only at the *mapped* level, where both collapse to `administrator`.

### Decision: one additive column, explicitly justified

`users.is_super_admin` — boolean, default false.

**Why not extend the `role` enum with `super_admin`:** `users` is shared with the other SCISP modules. Adding a fourth value would silently change the meaning of every existing `role === 'administrator'` comparison across the whole system — Super Admins would quietly stop matching administrator checks they match today. That is precisely the "modify unrelated SCISP modules" outcome to avoid.

The column is purely additive: every existing query keeps its current result, and only code that opts in sees the distinction.

### Stored-role validation was strengthened, not weakened

`MockAuthMiddleware` now also checks the claimed persona against the stored flag **in both directions**:

- header says `Super Admin`, flag is false → **403**
- header says `Admin`, flag is true → **403**

The second direction is the one that matters: without it the borrowing restriction is bypassable by sending a different header. The original mapped-role check is untouched, and `is_super_admin` is exposed as a request attribute so no controller re-derives it.

**Backend places that needed the distinction:** `CirculationService::checkout` (borrower eligibility), `HoldService::placeHold` and `requestReserveCopy` (borrower eligibility), `RenewalService::request`, the `POST /renewals` route gate, `CirculationController::mySummary`, and `MockAuthMiddleware`.

---

## 2. RENEWAL APPROVAL WORKFLOW

`POST /api/library/loans/renew` **is gone.** A borrower can no longer move their own due date.

| Step | Endpoint | Who |
|---|---|---|
| Ask | `POST /api/library/renewals` | Student, Faculty, Admin — own active loan only |
| Queue | `GET /api/library/renewals` | Admin, Super Admin |
| Approve | `PUT /api/library/renewals/{id}/approve` | Admin, Super Admin |
| Deny | `PUT /api/library/renewals/{id}/deny` | Admin, Super Admin |
| Own requests | `GET /api/library/renewals/me` | the borrower |

**Data model:** one flat `renewal_requests` table — `transaction_id`, `user_id`, `status` (`pending` / `approved` / `denied`), `decided_by`, `decided_at`, `new_due_date`, `decision_note`. No workflow engine. Nothing existing could carry it: `transactions` has no request concept and `holds` is about waiting for a copy, not extending one already borrowed.

**Guards** — requesting is refused when the loan is returned, belongs to someone else, already has a pending request, or the requester cannot borrow. Approving is refused when the loan is no longer active, the request was already decided, or **another borrower is waiting for the same title** (the pre-existing hold rule, now applied at the approval step rather than the request step). A reserve loan is only blocked by its own reserve's queue, never by the title's general waitlist. The borrower's *own* hold never blocks their renewal.

**Due date on approval** is computed from the **borrower's** role, not the approver's — a faculty loan approved by an Admin still gets 14 days, and a reserve loan gets the fixed 14.

`Transaction` gained a derived `renewal_status` append, so the borrowing screen shows Requested / Approved / Denied without a second call.

---

## 3. SUPER ADMIN BORROWING RESTRICTION

Enforced server-side at every borrower entry point:

| Action | Result |
|---|---|
| Checked out **to** a Super Admin (by anyone, including an Admin) | 422 — "Super Admin accounts cannot borrow library materials." |
| Super Admin checking out to themselves | 422 — same |
| Super Admin placing a hold | 422 — same |
| Super Admin requesting a renewal | 403 at the route gate |
| Super Admin summary | `can_borrow: false`, `borrow_limit: 0`, all borrower counters 0 |

Everything librarian still works: circulation, check-in, fines settlement, inventory, holds, course reserves, renewal decisions, student directory.

**Admin remains borrow-capable** — checkout, holds and renewal requests all succeed for an Admin account.

Frontend: the My Borrowing tab is hidden when `summary.can_borrow === false`, the tab row collapses to two columns, the borrowing screen is guarded, and the borrowing-rule card reads "Management account — borrowing not available" instead of the misleading "No borrowing limit".

---

## 4. COURSE RESERVE REQUEST FLOW (D-3)

`POST /api/library/reserves/{id}/request` — **Student only** (route gate).

Validated in order: reserve exists → reserve is `approved` → caller can borrow → caller is enrolled in the reserve's section → no duplicate active request → the reserve has allocated copies → the caller does not already have one of its copies on loan.

- **A free allocated copy exists** → that specific copy is set aside (`holds.status = fulfilled`, copy → `on_hold`), and the response reports `ready_for_pickup`.
- **Every allocated copy is in use** → the student joins **that reserve's** queue, and the response reports `queued` with the position.

No transaction is created. **Final checkout stays Admin / Super Admin only** — a student calling `/checkout` still gets 403.

The set-aside hold is created as `fulfilled` rather than `pending_approval` because the librarian already approved the reserve; a second approval step would be redundant. Promotions from the reserve queue use the same state, so a queued student and a directly-requesting student end up in the same place.

---

## 5. RESERVE-SCOPED QUEUE

The two pools never cross:

| | Eligible copies | Queue |
|---|---|---|
| General hold | `reserve_id IS NULL` **and** free | the title's waitlist |
| Reserve request | `reserve_id = <that reserve>` **and** free | that reserve's own queue |

Implemented by adding a nullable `holds.reserve_id` — reusing the existing hold machinery rather than adding a second queue table. `NULL` keeps today's behaviour for every existing row.

Every path respects the boundary:
- `placeHold` selects general copies only (`whereNull('reserve_id')`), so a free reserved copy is never handed to a general borrower — they queue instead.
- `requestReserveCopy` selects only that reserve's copies, so a free general copy never satisfies a reserve request.
- Check-in advances `advanceReserveQueue` for a reserve copy and `advanceQueue` for a general one.
- Queue renumbering (`resequence`) runs per pool, so positions are independent — two people can both be `#1`, one in each queue.
- Checkout's hold cleanup is scoped to the same pool, so collecting a reserve copy no longer silently cancels the borrower's separate general hold for the same title.

---

## 6. CLASSMATES API (D-1)

`GET /api/library/sections/{sectionId}/classmates`

```json
{ "section_id": 3, "section_name": "Water",
  "classmates": [ { "user_id": 6, "username": "DelaCruz_Juan_C1234" } ] }
```

| Caller | Result |
|---|---|
| Student enrolled in that section | 200 |
| Student in another section | **403** |
| Faculty who owns the section | 200 |
| Faculty who does not | **403** |
| Admin / Super Admin | 200 |
| Unknown section | 404 |
| Missing / unknown identity | 401 |

Only `user_id` and `username` are returned — `users` stores no given/family name, so username is the display value and the modal falls back to it. The rejection body leaks no roster. **SEC-05 is untouched**: `GET /library/students` is still 403 for a student, verified in the same test file.

---

## 7. AVAILABILITY LOGIC

**General availability** now requires *both* conditions: no `reserve_id` **and** `availability_status = 'available'`. A copy allocated to a course reserve is excluded from catalog availability even while it sits free on the shelf, because it is set aside for one section.

Applied in one place (`InventoryService::generalAvailabilityCounts`) and used by the catalog list, the book detail view and therefore the dashboard counts. A companion `reserved_copies_count` is returned so the UI can explain the gap between "we own five" and "five you can borrow".

Visible on live data immediately: *Clean Code* has 2 copies, one allocated to a reserve, and the catalog now reads **"1 of 2 Copies Available"** where it previously said 2.

**Course-reserve availability** counts only that reserve's own allocated copies that are physically free — a held or checked-out allocated copy does not count, and general stock of the same title never does.

**Checkout eligibility** was already borrower-based and is unchanged: a reserved copy still cannot go to a borrower who is not enrolled in that section.

---

## 8. PERFORMANCE — BEFORE / AFTER

Profiled with the browser's own Resource Timing data, Vite dev server, same machine, same data. Call counts are doubled by React StrictMode's development double-effect; the endpoint count is the meaningful figure.

### Before

| Role | Distinct endpoints | API calls | Wall clock |
|---|---|---|---|
| Admin | 10 | 20 | **6383 ms** |

Slowest: `categories` 3993 ms, `me/summary` 3922 ms, `loans/me/history` 3842 ms.

### Root cause

A single warm request is **77 ms**, and six in parallel finish in 504 ms — the backend is not slow. The page was issuing **20 requests plus 20 CORS preflights**, and the browser's ~6-connection-per-origin limit queued them into waves. The Resource Timing `duration` was almost all queueing. Four of those ten endpoints were librarian datasets loaded on first paint even when the librarian was looking at the catalog, and three were borrower endpoints loaded for an account that cannot borrow.

### After

| Role | Distinct endpoints | API calls | Wall clock | Change |
|---|---|---|---|---|
| Admin | **6** | 12 | **4094 ms** | −40% endpoints, −36% time |
| Super Admin | **3** | 6 | **2168 ms** | −70% endpoints, −66% time |

### What changed

| Fix | Effect |
|---|---|
| Librarian datasets load per tab, not on first paint | −4 endpoints for every administrator |
| Badge counts come from `me/summary` instead of the datasets | makes the above possible without wrong badges |
| Borrower endpoints skipped when the account cannot borrow | −4 endpoints for Super Admin |
| `categories` fetched once and reused | −1 endpoint on every later load |
| Mutations refresh only the current screen | a check-in no longer refetches four admin datasets |
| `loading` vs `refreshing` split (earlier pass) | no skeleton collapse on refetch |

Correctness was not traded away: every count shown is still server-derived, and a librarian opening a tab gets fresh data for it.

**Not addressed** (dev-only or out of scope): StrictMode double-fetch, CORS preflights, and `php artisan serve` — none of which exist in a production build behind a real web server.

---

## 9. API ENDPOINTS ADDED / CHANGED

### Added

| Method | Path | Access |
|---|---|---|
| POST | `/api/library/renewals` | Student, Faculty, Admin |
| GET | `/api/library/renewals/me` | any borrower |
| GET | `/api/library/renewals` | Admin, Super Admin |
| PUT | `/api/library/renewals/{id}/approve` | Admin, Super Admin |
| PUT | `/api/library/renewals/{id}/deny` | Admin, Super Admin |
| POST | `/api/library/reserves/{id}/request` | Student |
| GET | `/api/library/sections/{id}/classmates` | per-section, checked in the controller |

### Removed

| Method | Path | Replaced by |
|---|---|---|
| POST | `/api/library/loans/renew` | `POST /api/library/renewals` + an approval |

### Changed payloads

- `GET /me/summary` — added `is_super_admin`, `can_borrow`, `pending_renewals`, and a `librarian` block for administrators.
- `GET /sections/me` — added `allocated_copies`, `available_for_section`, `student_request_status`, `queue_position` per reserve.
- `GET /books`, `GET /books/{id}` — `available_copies_count` now excludes reserve-allocated copies; added `reserved_copies_count`.
- `GET /loans/me`, `GET /loans/me/history` — each transaction carries `renewal_status`.

---

## 10. FRONTEND WIRING

No visual redesign. Antigravity's polish (badge sizing, hover lifts, empty-state icons, spinners) was preserved.

| Component | Wired to |
|---|---|
| `StudentCourseReserveCard` | `onRequestBorrow` → `POST /reserves/{id}/request`; real `student_request_status`, `queue_position`, `allocated_copies`, `available_for_section`; real success/error toasts |
| `ClassmatesModal` | `GET /sections/{id}/classmates`; keeps its honest unavailable state when the call fails |
| Loan details modal | **Request Renewal** replaces Renew Loan; shows **Renewal Requested** while pending |
| `RenewalRequestsPanel` (new) | `GET /renewals`, approve / deny — styled to match the hold queue beside it |
| Navigation badges | `summary.librarian` counts |
| My Borrowing tab | hidden when `can_borrow === false` |
| Borrowing-rule card | "Management account — borrowing not available" for a Super Admin; role chip reads SUPER ADMIN |
| `TeacherReservesView` | teacher-side Renew button removed — it called a route that no longer exists, and a teacher is neither the borrower nor the approver |

### One bug found and fixed during wiring

Splitting the loader introduced a race: `categoriesLoadedRef` was read *after* the `await`, and React StrictMode runs two effects concurrently. When the first finished, the flag flipped mid-parse and the second invocation read every response from the wrong index — the student's reserve list silently came back empty. Fixed by capturing the decision in a local before the await. Caught in the browser, not by tests.

---

## 11. FILES CHANGED

### Backend — new (9)

`app/Models/RenewalRequest.php` · `app/Services/RenewalService.php` · `app/Http/Controllers/Api/RenewalController.php` · 3 migrations · 5 test files (listed in §12)

### Backend — modified (11)

`MockAuthMiddleware.php` · `User.php` · `Hold.php` · `Transaction.php` · `CirculationService.php` · `HoldService.php` · `InventoryService.php` · `CirculationController.php` · `CourseSectionController.php` · `ReserveController.php` · `routes/api.php` · `MockPersonaSeeder.php`

### Frontend — new (1)

`RenewalRequestsPanel.jsx`

### Frontend — modified (2)

`LibraryPortal.jsx` (data layer + wiring) · `TeacherReservesView.jsx` (renew removal)

### Documentation (2)

`LIBRARY_CURRENT_STATE.md` · `LIBRARY_MANUAL_UAT.md`

---

## 12. TESTS ADDED / UPDATED

### New suites (5 files, 78 tests)

| File | Tests | Covers |
|---|---|---|
| `RenewalApprovalTest.php` | 21 | request, duplicate, returned loan, wrong owner, approve by Admin and Super Admin, borrower-role due date, deny, re-request after denial, waiting-borrower block, own-hold exemption, listing scope, derived `renewal_status` |
| `SuperAdminRestrictionTest.php` | 15 | stored distinction, both masquerade directions, summary flags, cannot be borrower / self-checkout / hold / renew, Admin can still borrow, Super Admin keeps every librarian power |
| `CourseReserveRequestTest.php` | 20 | happy path, enrolment, role gate, unapproved reserve, no allocation, duplicate, **both pool-crossing directions**, independent queue numbering, reserve-queue promotion on check-in, librarian-only checkout, all five D-2 states |
| `ClassmatesAccessTest.php` | 12 | all six access outcomes, no leakage, 404, identity, field shape, SEC-05 still closed |
| `AvailabilitySemanticsTest.php` | 7 | reserved copies excluded from general availability, every non-free status, book detail parity, reserve-only availability, held copy, checkout boundary |

### Updated (5 files)

`CirculationRulesTest.php` (C15–C19 rewritten to the approval flow) · `PreUatEndToEndTest.php` (D1 rewritten) · `MockAuthIdentityTest.php` (**I17 inverted** — it asserted the old parity; now asserts the personas are *not* interchangeable) · `ReserveAuthorizationTest.php` and `StudentDirectoryAccessTest.php` (fixtures flag their super-admin users)

---

## 13. FULL TEST RESULTS

```
php artisan test tests/Feature/Library tests/Feature/TestDatabaseSafetyTest.php

Tests:    202 passed, 1 skipped (522 assertions)
Duration: 35.44s
```

Up from **127 passed** before this pass. 0 failures. The one skip is the intentional `GUARD_SELF_TEST` case.

**Frontend:** `npm run build` PASS. `npm run lint` PASS — 2 warnings (both the provider-plus-hook fast-refresh pattern), 0 errors.

**Note on the wider suite:** `php artisan test --testsuite=Feature` also reports 41 failures in `Tests\Feature\Settings\*`. These are Laravel starter-kit tests unrelated to the Library, failing on "no such table: users" from their own setup. They were failing before this pass and were not touched.

---

## 14. LIVE DB BEFORE / AFTER

| Table | Before | After |
|---|---|---|
| users | 11 | 11 |
| books | 3 | 3 |
| book_copies | 6 | 6 (all `available`) |
| transactions | 3 | **4** |
| holds | 2 | 2 (both cancelled) |
| renewal_requests | — | **1** |
| course_reserves | 2 | 2 |
| course_sections | 3 | 3 |
| course_section_students | 3 | 3 |
| total fines | 0.00 | 0.00 |

**Schema:** three additive migrations applied with plain `php artisan migrate`. No existing column altered or dropped.

**Data:** `is_super_admin = 1` set on `SysAdmin_001` (the Topbar's Super Admin persona) and on the legacy `superadmin` dev account, so both keep working under the strengthened middleware check.

**Residue from end-to-end verification** — one completed borrow cycle driven through the UI (reserve request → checkout → renewal request → approval → check-in): one extra `returned` transaction and one `approved` renewal request. Both are normal completed records; every copy is back to `available` and no balance changed.

**Never run:** `migrate:fresh`, `db:seed`, `DatabaseSeeder`, or `RefreshDatabase` against MySQL. The test guard was not weakened and the recovery scratch DB was not touched. All 202 tests ran on SQLite `:memory:`, confirmed by `TestDatabaseSafetyTest`.

---

## 15. DOCUMENTATION UPDATED

**`LIBRARY_CURRENT_STATE.md`** — the "Admin and Super Admin are identical" rule is **replaced** with the new capability table and the reason the distinction had to be stored; the renewal section is replaced with the approval flow; the frozen-list entry for the teacher-renew allowance is marked removed; the sign-off checklist item is rewritten; a new §21 documents D-1, D-2, D-3, availability semantics, badge data and all three schema changes.

**`LIBRARY_MANUAL_UAT.md`** — UAT-B6 rewritten to *request* a renewal (and to check the due date does **not** move); the teacher Renew expectation corrected; UAT-G1 retitled; a new **section H** adds 10 cases for the changed rules.

---

## 16. REMAINING MANUAL UAT ITEMS

Manual UAT is still pending and must be performed by a human. The new section H (10 cases) is additional to the existing package. Priority order:

1. **H1–H3** renewal approval, denial, and the waiting-borrower block
2. **H4–H5** Super Admin cannot borrow, and cannot be selected as a borrower
3. **H6–H8** course reserve request, reserve queue, and the pool boundary
4. **H9** classmates access from both an enrolled and an unenrolled student
5. **H10** catalog availability excluding reserved copies
6. Re-run **B6** and the teacher reserve case, whose expectations changed

Two things I could not observe and that still need a human:
- **An overdue loan in the UI** — still no overdue data, and creating one means back-dating a due date.
- **Reduced motion** — the CSS rule is present and correctly scoped, but this machine has the OS setting off.

---

## 17. REMAINING RISKS

| Risk | Assessment |
|---|---|
| **`is_super_admin` must be set on real accounts** | Nothing infers it. The seeder handles the four personas and I backfilled the two live administrator accounts, but any Super Admin created later must have the flag set or they will be treated as an ordinary Admin — and, under the strengthened middleware, will be rejected outright if they send the `Super Admin` header. |
| **Legacy `superadmin` / `admin` dev rows** | Flagged by username, which is a judgement call on leftover dev data, not a rule. |
| **`GET /students` is still faculty-wide** | SEC-05 restricted it to faculty and librarians, but a teacher still sees every student in the system, not just their own. Out of scope here; worth revisiting. |
| **Reserve requests bypass the "Getting Approval" step** | Deliberate — the reserve itself was already approved — but it means a student can set a copy aside without any librarian touching it. If the desk wants a second gate, that is a product decision. |
| **Outstanding fines do not block a reserve request** | They block *checkout* (unchanged), so the copy is set aside and then refused at the desk. Slightly late feedback; not incorrect. |
| **No expiry on a set-aside reserve copy** | The UI text promises "48 hours" but nothing enforces it — a student can hold a reserve copy out of circulation indefinitely. Pre-existing for general holds too. |
| **Availability change is user-visible** | Catalogs that previously counted reserved copies now show fewer available. This is the confirmed rule, but it will look like a regression to anyone not expecting it — flagged for UAT. |
| **StrictMode double-fetch masked a real bug** | The index race was only reachable because two loaders run concurrently. Production does not double-invoke, but the fix matters for any genuine concurrent refresh. |
| **41 unrelated starter-kit test failures** | Pre-existing, outside the Library, untouched — but they mean `php artisan test` is not green overall, which will confuse anyone running the full suite. |

---

## Stop point

This pass is complete: backend rules, D-1/D-2/D-3, availability, badges, performance, tests, wiring and documentation. **JWT has not been started.**
