# Library Module — Final System Check

**Repo:** `SCISP-Library-Module` · **Branch:** `lyndon` · **Date:** 2026-09-13
**Type:** Release-readiness verification. **No features added, no schema changed, no migration run, no fix implemented, no commit made.**

Every figure below was measured during this pass, not carried forward from an earlier report.

---

## 1. ENVIRONMENT STATUS

| Check | Result |
|---|---|
| `scisp_frontend` :5173 | ✅ Up 9h · HTTP 200 in 0.008s |
| `scisp_backend` :8000 | ✅ Up 9h · `/up` HTTP 200 |
| `scisp_db` :3306 | ✅ `mysqld is alive` |
| Laravel / PHP | 13.26.1 / 8.4.25 |
| `/library` loads | ✅ all four roles, **zero console errors** |
| Storage link | ✅ `public/storage -> /var/www/html/storage/app/public` |
| Cover files served | ✅ `/storage/library/covers/…` returns `200 image/*` |
| **Migrations** | **28 on disk · 28 applied · 0 pending** |

> The one `migrate:status` line containing the word "pending" is the *filename*
> `add_pending_approval_to_holds_status`, not a pending migration.

**Test-database safety — all four layers intact:**
`phpunit.xml` carries `<env … force="true">` **and** the matching `<server>` entries (both required: `<server>` wins over `<env>` in PHPUnit, so `force` alone would be silently defeated); `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, and every MySQL connection variable blanked; `TestCase::guardAgainstProtectedDatabase()` runs on every setUp and `exit(1)`s if the resolved database matches `TEST_PROTECTED_DATABASES`. **No `RefreshDatabase` path can reach live MySQL.**

Live table counts recorded before any check ran — see §19.

---

## 2. REPOSITORY HEALTH

| Check | Result |
|---|---|
| Missing imports | ✅ none — build resolves every module |
| Broken routes | ✅ none — 97 total / 55 Library, unchanged through cleanup |
| Dead referenced components | ✅ none — every component imported ≥1× |
| Removed dependency still referenced | ✅ none |
| Stale `copy.barcode` | ✅ **zero** occurrences repo-wide |
| UI text claiming barcode/QR | ✅ **zero** occurrences |
| References to deleted middleware in code | ✅ none |
| Tracked `.env` / SQL dumps / covers / dist / logs / cache | ✅ **none** |
| App-shell files required by Library | ✅ all present and routed |

The only remaining mentions of the deleted middleware are in three **documents** — see §20.

---

## 3. ROLE MATRIX

Probed live against the running API. Route-level **and** service-level.

| Capability | Student | Faculty | Admin | Super Admin |
|---|---|---|---|---|
| Catalog | 200 | 200 | 200 | 200 |
| Categories (read) | 200 | 200 | 200 | 200 |
| Own borrowing | 200 | 200 | 200 | *(no tab)* |
| Own course reserves | 200 | — | — | — |
| Own sections | — | 200 | 200 | — |
| Student directory | **403** | 200 | 200 | 200 |
| Circulation desk | **403** | **403** | 200 | 200 |
| Category **create** | **403** | **403** | 201 | 201 |
| Settings | **403** | **403** | 200 | 200 |
| Inventory audits | **403** | **403** | 200 | 200 |
| All reserves | **403** | — | 200 | 200 |
| Fines admin | **403** | — | 200 | 200 |

**Classmates scoping verified per-section**, not per-role: `DelaCruz_Juan_C1234` is enrolled in sections 1, 3, 4 and gets **200** for those and **403** for section 2. The roster is not a second student directory.

**Service-level enforcement — the important one.** The borrower on `POST /checkout` sends no header, so the Super Admin borrow ban cannot be a route check. Verified live:

```
POST /checkout {user_id: 4 (super admin), copy_id: 1}  as Admin
→ {"error":"Super Admin accounts cannot borrow library materials."}
```

Confirmed afterwards: `transactions` still 4, copy 1 still `available` — **nothing was created**. `can_borrow: false` and `is_super_admin: true` on the summary; the My Borrowing tab is absent and the "Management account" notice renders.

---

## 4. CATALOG / CATEGORY STATUS

| Check | Result |
|---|---|
| Catalog loads | ✅ |
| Search | ✅ `Algorithms` → 1 correct hit |
| Category dropdown | ✅ 2 mounts (mobile+desktop), options `All Categories / Computer Science / Software Engineerings` |
| Search + category together | ✅ `Algorithms` + `Computer Science` → only *Introduction to Algorithms* |
| All Categories resets | ✅ value `''` clears the filter |
| Pagination | ✅ resets to page 1 on category change |
| No chip clutter | ✅ chip rows fully removed |
| Archived hidden from borrowers | ✅ covered by `InventoryLifecycleTest` |
| `include_archived` librarian-gated | ✅ borrower-bypass asserted in tests |
| Duplicate ISBN blocked | ✅ 409 |
| ISBN normalization | ✅ `978-…` ≡ `978…` |
| ISBN-less duplicate (title+author) | ✅ 409 |
| Metadata persists (edition/publisher/year) | ✅ |
| Admin/Super Admin create category | ✅ 201 |
| Student/Faculty create category | ✅ **403** |
| Case/spacing duplicate | ✅ `"  computer   SCIENCE  "` → 409 |
| Add Book uses managed categories | ✅ |
| Edit Book uses managed categories | ✅ |
| Rename carries to books | ✅ `CAT14` |

**The live typo `"Software Engineerings"` still exists** — `library_categories.category_id = 2`, and `books.book_id = 2` still carries that string. **Not renamed**, as instructed. `PUT /api/library/categories/2` with `"Software Engineering"` would fix both in one call whenever you approve it.

---

## 5. COVER STATUS

| Check | Result |
|---|---|
| Upload | ✅ verified through the real file input |
| URL resolves | ✅ `http://localhost:8000/storage/…` → `200 image/*` |
| Replace | ✅ new file written, **old file deleted** |
| Remove | ✅ DB `NULL`, file deleted, placeholder returns |
| Broken cover falls back | ✅ `BookCover` unmounts the `<img>` and renders the placeholder icon — **no broken-image glyph** |
| Validation unchanged | ✅ JPEG/PNG/WebP, 5 MB, `mimetypes:` sniffing |

No book currently has a cover (the last one was removed during earlier verification), so `cover_url` is `null` for all three titles — expected, not a fault.

---

## 6. INVENTORY STATUS

| Check | Result |
|---|---|
| ABC-LIB display | ✅ `ABC-LIB-000001`…`000006`; **zero** legacy `CPY-n` labels anywhere |
| Uniqueness | ✅ 6 copies / 6 distinct accessions / unique index present |
| Immutability | ✅ not fillable; model throws on change |
| Counter consistency | ✅ counter = 6, max sequence = 6, copies numbered 6/6 |
| Condition / availability / shelf | ✅ separate fields, live |
| Reserve relationship | ✅ copy 3 → reserve 2 |
| Condition history | ✅ endpoint + append-only trail (0 rows so far) |
| Archive/restore blockers | ✅ routes live, blockers return sentences |
| Archived history readable | ✅ no row deleted by archiving |

No barcode/QR behaviour added.

---

## 7. SETTINGS STATUS

Effective values read live — **all at shipped defaults**:

`student` 3 books / 7 days / ₱10 / 0 grace / no cap · `faculty` 10 / 14 / ₱10 / 0 / no cap ·
renewals enabled both · `max_renewals_per_loan` unlimited · `reserve_loan_days` 14.

| Check | Result |
|---|---|
| Values hydrate | ✅ every field populated |
| Nullable show Unlimited / No cap | ✅ placeholders render |
| Unchanged Save sends no request | ✅ "No changes to save." — **no PUT issued** |
| Save shows diff | ✅ e.g. `Loan duration 7 days → 10 days` |
| Successful save refreshes baseline/history | ✅ |
| Required field cleared | ✅ blocked with a named message, no PUT |
| Forward-only | ✅ loans keep their due dates, fines not recalculated |

`library_setting_history` holds **6 rows for 6 real changes** — a clean round trip, nothing spurious. Verified without modifying live settings in this pass.

---

## 8. BORROWING / CIRCULATION STATUS

Covered by `CirculationRulesTest` (25) and `PreUatEndToEndTest` (7), all passing. Live data shows **4 completed borrow→return cycles**, all `returned` with `actual_return_date` set and no active loans.

Checkout · check-in · borrow limits · outstanding-fine block · due-date calculation · future due date not flagged overdue · overdue calculation · **fine charged only at check-in** · returned rows carry no Check-In action · return date visible · Active / All History / Returned filtering — ✅ all verified by suite.

---

## 9. HOLDS STATUS

Covered by `HistoryHoldsReservesTest` (14) and `AvailabilitySemanticsTest` (7).

Normal hold · FCFS queue · duplicate blocked · promotion after return · **general hold never consumes a course-reserved copy** · cancel/release · queue resequencing — ✅ all covered.

Live state: 3 holds — two `cancelled`, one `fulfilled` against reserve 2. See the pickup-pinning note in §21.

---

## 10. COURSE RESERVE STATUS

Covered by `CourseReserveRequestTest` (20) + `ReserveAuthorizationTest` (27) + `ClassmatesAccessTest` (12).

Full flow, unrelated-student denial, reserve-only copy pools, reserve loan duration from settings, queue position, classmates scoping — ✅ all covered and passing. **No second approval step was added** to student reserve requests; the product rule stands: allocation makes a copy eligible → the student request prepares it → Admin/Super Admin performs the physical checkout.

**Availability semantics correct in live data:** *Clean Code* has 2 copies, 1 allocated to reserve 2, so the catalog shows `available=1, reserved=1, total=2` — a reserved copy is excluded from general availability even while physically on the shelf.

⚠ **Operational state a UAT tester should know:** reserves **1 and 3 are `approved` with zero allocated copies** (`copies_requested` 1 and 2). A student in those sections gets *"No physical copies have been allocated to this course reserve yet."* That is correct handling of an incomplete setup, not a defect — but it will look like a bug during UAT unless copies are allocated first.

---

## 11. RENEWAL STATUS

Covered by `RenewalApprovalTest` (21). Live data shows one request, `status = approved`.

Borrower requests without the due date moving · Admin/Super Admin sees and decides · approval extends by the borrower's role duration · respects `max_renewals_per_loan` · blocked when another borrower waits · denial leaves the due date untouched — ✅ all covered.

⚠ **Approved/Denied visibility is only half-delivered (F-04).** The backend appends `renewal_status` to every transaction and exposes `GET /renewals/me`, but the frontend branches on `'pending'` in exactly one place and never renders an Approved or Denied state to the borrower.

---

## 12. FINES STATUS

Covered by `FinesAndOverdueTest` (14).

Rate from settings · grace period · maximum cap · partial payment · partial waiver · **no negative balance** · borrowing unblocks at zero · **old fines never recalculated after a settings change** — ✅ all covered. Live balance ₱0.00 across all users.

---

## 13. FRONTEND / RESPONSIVE STATUS

**Zero horizontal overflow at every required width** (`scrollWidth === innerWidth` in all seven):

| 1440 | 1280 | 1024 | 768 | 430 | 390 | 360 |
|---|---|---|---|---|---|---|
| ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

Hamburger present at 360. Search + category share one row from `sm` up (category 240 px) and stack below it. Sidebar/drawer, tab transitions, hover/pressed states, toasts, confirm dialogs, skeletons, mobile circulation filters, pagination, cover aspect ratio (`aspect-[3/4]` + `object-cover`), accession tags, classmates modal, reserve cards and the Inventory | Settings switcher all render correctly.

**Zero console errors** on a clean tab for all four roles. **Zero broken images** on all four roles.

Inventory Audit is hidden from the switcher by design and was **not** judged broken — its backend, panel and 15 tests are intact.

---

## 14. PERFORMANCE STATUS

Distinct endpoints on first load, per role — matching the Pass-C targets exactly:

| Role | Distinct endpoints | Raw requests |
|---|---|---|
| Student | 7 | 14 |
| Admin | **6** | 12 |
| Super Admin | **3** | 6 |

**Every endpoint fires exactly 2×.** The cause is **React StrictMode's dev double-invoke** (`main.jsx` wraps the app in `<StrictMode>`), not the dual mobile/desktop mount and not a fetch bug — the factor is a uniform 2 across every role and every endpoint, and data fetching lives in a single `LibraryPortal` instance. **This does not exist in a production build.**

**No role-irrelevant requests:** Super Admin correctly skips all borrower endpoints (loans, holds, history, sections).

**Apparent 8-second latencies are a dev-server artifact, not a regression.** A single warm request measures **~90 ms**; `php artisan serve` is single-threaded, so 14 simultaneous requests queue behind one another. Behind nginx/php-fpm this disappears.

Remaining real performance risk: the **F-19 N+1** on the admin Circulation Desk (below).

---

## 15. AUDIT FINDINGS RECHECK

Each verified against current code this pass. **No fix was applied.**

| # | Sev | Finding | Status | Evidence |
|---|---|---|---|---|
| F-01 | **HIGH** | Any user can create a section | **STILL OPEN** | `POST /sections` sits in the general authenticated group with no role middleware, and `store()` has no role check. A Student gets **422** (validation), not 403 — proving authorization is passed |
| F-02 | MED | `GET /sections` serialises full User models | **STILL OPEN** | Embedded user objects expose `total_fines`, `is_super_admin`, `status`, `two_factor_confirmed_at`. **No credential leak** — a `#[Hidden]` attribute covers `password` and both 2FA secrets |
| F-03 | LOW | `addStudent` does not verify the target is a student | **STILL OPEN** | Rule is `exists:users,user_id` only |
| F-04 | MED | Borrower never sees Approved / Denied | **PARTIALLY CLOSED** | Backend done (`renewal_status` appended, `/renewals/me` live). Frontend branches on `'pending'` only |
| F-07 | **HIGH** | Releasing a reserve orphans its queue and loans | **STILL OPEN** | `updateStatus` nulls `reserve_id` on copies and touches neither the reserve's holds queue nor outstanding loans |
| F-08 | **HIGH** | Denying after allocation strands copies | **STILL OPEN** | Only `released` clears `reserve_id`; `denied` does not. *(No stranded copies in live data right now — 0 found.)* |
| F-09 | MED | Removed student keeps their reserve request | **STILL OPEN** | `removeStudent` deletes the enrolment row only |
| F-13 | MED | Manual status edits contradict live holds/loans | **PARTIALLY CLOSED** | A guard now blocks flipping a loaned copy to `available`. `lost`/`damaged` are still settable during an active loan |
| F-14 | MED | Reserve `updateStatus` is not transactional | **STILL OPEN** | Copy update and `$reserve->save()` are two unwrapped writes |
| F-16 | MED | Duplicate-request guards outside the lock | **PARTIALLY CLOSED** | `requestReserveCopy` is now fully inside `DB::transaction`, but the duplicate check is an **unlocked read** and `holds` has **no unique index** on `(reserve_id, user_id, status)` to back it |
| F-17 | LOW | `updateCopyStatus` has no transaction or lock | **STILL OPEN** | Confirmed: neither. The active-loan check is read-then-write, and the condition-history insert is not atomic with the copy update |
| F-18 | LOW | `acceptHold` read-modify-write unguarded | **PARTIALLY CLOSED** | Now wrapped in `DB::transaction`, but `findOrFail` has no `lockForUpdate` |
| F-19 | MED | `renewal_status` N+1 on three endpoints | **PARTIALLY CLOSED** | Eager-loaded on `myLoans` and `myHistory`; **still missing** on the admin circulation index (`CirculationController:120`) — the highest-row endpoint |
| F-28 | — | `is_super_admin` mass-assignable | **CLOSED** | Removed from `$fillable`; documented rationale in the model |
| F-29 | MED | Raw exception text returned | **STILL OPEN** | e.g. `acceptHold` returns `'error' => $e->getMessage()` |
| — | — | Stale documentation | **STILL OPEN** | See §20 |

**Nothing regressed. No finding moved backwards, and none was newly introduced by the recent passes.**

---

## 16. DATABASE / TABLE HEALTH

**No table was dropped and none is proposed for dropping.** 24 tables present.

Critical constraints all present:

| Table | Unique constraint |
|---|---|
| `books` | `isbn`, `isbn_normalized` |
| `book_copies` | `accession_number` |
| `library_categories` | `normalized_name` |
| `library_settings` | PK `key` |
| `inventory_audit_items` | `(audit_id, copy_id)` |

**Accession counter consistent:** counter 6 · copies numbered 6/6 · 6 distinct · max sequence 6.

**Referential integrity — all zero:** orphan copies 0 · transactions with missing copy 0 · holds with missing user 0 · copies on non-approved reserves 0 · books with no matching category row 0 · duplicate normalized ISBNs 0.

**Migration/table mismatch: none.** 28 files, 28 applied rows.

> ⚠ `information_schema.table_rows` reports nonsense for InnoDB (it showed `migrations 17`, `transactions 3`, `course_reserves 0`). Those are **optimiser estimates**, not counts. Every figure in this report uses exact `COUNT(*)`.

**Truly unused tables:** `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `passkeys` — all empty and unused by the Library. They are **Laravel/Fortify framework tables**, required by the installed stack. **Reported, not dropped, and dropping them is not recommended.**

---

## 17. TEST RESULTS

```
docker exec scisp_backend php artisan test tests/Feature/Library tests/Feature/TestDatabaseSafetyTest.php
```

### ✅ 313 passed · 1 skipped · 0 failed · 873 assertions · 57.6s

**Exactly the expected baseline.** 314 `test()` declarations across 19 files = 313 + 1 skipped (the guard self-test, which terminates the process by design and runs only with `GUARD_SELFTEST=1`).

| Suite | Tests | Suite | Tests |
|---|---|---|---|
| ReserveAuthorization | 27 | InventoryLifecycle | 19 |
| CirculationRules | 25 | InventoryAudit | 15 |
| MockAuthIdentity | 25 | SuperAdminRestriction | 15 |
| CategoryManagement | 24 | FinesAndOverdue | 14 |
| AccessionAndMetadata | 23 | HistoryHoldsReserves | 14 |
| RenewalApproval | 21 | ClassmatesAccess | 12 |
| CourseReserveRequest | 20 | StudentDirectoryAccess | 12 |
| LibrarySettings | 20 | BookCover | 10 |
| AvailabilitySemantics | 7 | PreUatEndToEnd | 7 |
| TestDatabaseSafety | 3 (+1 skipped) | | |

**Pre-existing and out of scope:** running the *whole* `Feature` suite also runs the Laravel starter-kit tests (`Auth`, `Settings`, `DashboardTest`), producing **38 failures**, because `tests/Pest.php` has `->use(RefreshDatabase::class)` commented out — a deliberate safety decision unchanged since `f4d0458 Initial project template`. Not a Library regression; re-enabling the global trait is **not** safe while the default connection can resolve to live MySQL.

---

## 18. BUILD / LINT

| | Result |
|---|---|
| `npm run build` | ✅ **exit 0** — `index-DwaVFAZv.js 518.25 kB` (gzip 139.71 kB), `index-Cu9eYK0I.css 49.61 kB` |
| `npm run lint` | ✅ **exit 0** — 8 warnings, all pre-existing, none new |

Warnings: 2 × `only-export-components` (`ConfirmDialog`, `ToastProvider`), 2 × `exhaustive-deps` (`LibrarySettingsPanel`, `InventoryAuditPanel`), 4 × unused identifiers in `InventoryAuditPanel`.

---

## 19. LIVE DB SAFETY

Counts captured before the first check and again after everything — **byte-identical**:

```
users 11 · books 3 · book_copies 6 · transactions 4 · holds 3
course_reserves 3 · course_sections 4 · course_section_students 4
fines 0 · renewal_requests 1 · library_categories 2
library_settings 3 · library_setting_history 6 · copy_condition_history 0
inventory_audits 1 · inventory_audit_items 6 · library_counters 1
total_fines_sum ₱0.00
```

`diff before after` → **IDENTICAL. No live mutation.** The one write-shaped probe (Super Admin checkout) was *rejected* by the service, and I confirmed afterwards that no transaction row and no copy-status change resulted. All mutation testing ran on SQLite `:memory:`.

**`library_recovery_scratch` untouched — 16 tables still present.**

> Note: `course_reserves` 2→3, `course_sections` 3→4 and `inventory_audits` 0→1 since the cleanup pass. That is **normal app usage between passes**, not a change made by this check.

---

## 20. DOCUMENTATION DRIFT

| Document | Status |
|---|---|
| `LIBRARY_MANUAL_UAT.md` | ✅ Current — 84 cases, correctly still marked NOT EXECUTED |
| `LIBRARY_FINAL_AUDIT.md` | ✅ Accurate — every finding recheck in §15 matched its description |
| `LIBRARY_REPO_CLEANUP_AUDIT.md` | ⚠ Written as *proposals*; those removals are now **executed**. Reads as a plan rather than a record |
| `LIBRARY_CURRENT_STATE.md` | ⚠ **Four drifts** (below) |
| `diagram_alignment_report.md` | ⚠ Historical academic artifact (2026-09-04). Describes checkout as gated by `RequireAdminRole` — a class that never actually gated those routes and no longer exists. **Deliberately not rewritten** |

**`LIBRARY_CURRENT_STATE.md` drift:**

1. Line 58 — "API surface: **53 routes**". Actual: **55** (the two category write routes).
2. Line 416 — "**289 passed**, 1 skipped (811 assertions)". Actual: **313 passed, 1 skipped, 873 assertions**.
3. **Category management is entirely undocumented.** The whole feature — `library_categories`, the three endpoints, normalization rules, the dropdown replacing chips — has no section. Only 2 incidental uses of the word "categor" in the document.
4. Lines 923–924 — the §22.11 live-count block is now stale (reserves 3, sections 4, audits 1).

**One fix applied this pass** (a factual self-contradiction I introduced during cleanup): §16 listed the dead-middleware cleanup as *deferred* while §19 said it was *done*. §16 now reads as closed. **No other document was rewritten.**

---

## 21. TOP 10 REMAINING RISKS

| # | Risk | Sev |
|---|---|---|
| 1 | **Manual UAT still not executed** — 84 cases, the actual sign-off gate | **Blocking for sign-off** |
| 2 | **F-01** — any authenticated user can create a course section, then add students and read the roster | HIGH |
| 3 | **F-07 / F-08** — releasing a reserve orphans its queue and loans; denying after allocation strands copies | HIGH |
| 4 | **F-29** — raw exception text returned to clients on older endpoints | MED |
| 5 | **F-02** — `GET /sections` leaks `total_fines`, `is_super_admin`, `status` per student (no credentials) | MED |
| 6 | **F-19** — N+1 remains on the admin Circulation Desk, the largest result set | MED |
| 7 | **F-14 / F-16 / F-17 / F-18** — missing transactions and row locks on four write paths; `holds` has no unique index to backstop duplicate reserve requests | MED |
| 8 | **F-04** — borrower never sees Approved/Denied; the backend already provides it | MED |
| 9 | `LIBRARY_CURRENT_STATE.md` drift, incl. an entire undocumented feature (§20) | MED |
| 10 | A `fulfilled` hold pins copy 3 indefinitely — nothing expires pickups; `ExpireHolds` exists but is **unscheduled** | LOW |

---

# VERDICT

## B. READY WITH NON-BLOCKING ISSUES

Nothing prevents a human from executing the 84 UAT cases today. The environment is healthy, all 313 automated tests pass, build and lint are clean, all four roles behave correctly at both route and service level, live data is intact, and the repository is consistent after cleanup. **No finding regressed and none was newly introduced.**

It is not verdict A because the open HIGH findings (F-01, F-07, F-08) are real authorization and lifecycle gaps, and because `LIBRARY_CURRENT_STATE.md` no longer fully describes the system it documents.

---

### WHAT MUST BE FIXED BEFORE UAT

1. **Allocate copies to reserves 1 and 3**, or expect testers to hit *"No physical copies have been allocated"* and report it as a bug. This is data setup, not code.
2. **Update `LIBRARY_CURRENT_STATE.md`** — the route count, the test count, and the missing category-management section. Testers use it as the behaviour reference.

Nothing else blocks UAT.

### WHAT CAN WAIT UNTIL AFTER UAT

- F-04 frontend Approved/Denied display (backend already supplies it)
- F-13 remainder (`lost`/`damaged` during an active loan)
- F-19 N+1 on the admin circulation index
- The `LIBRARY_REPO_CLEANUP_AUDIT.md` proposal→record rewording
- The 8 lint warnings and the unscheduled `ExpireHolds`
- `library_recovery_scratch` backup-and-drop

### WHAT MUST BE FIXED BEFORE JWT

1. **F-01** — section creation must be role-gated. Under mock auth its blast radius is bounded because authentication is fake anyway; under JWT it becomes a genuine privilege boundary.
2. **F-02** — stop serialising full User models once tokens identify real people.
3. **F-29** — stop returning raw exception text before the API faces real clients.
4. **F-07 / F-08** — reserve release/deny lifecycle, so tokened users cannot strand inventory.
5. **Reconcile the two API clients** — `src/api.js` (mock headers) and `src/services/api.js` (bearer token) must converge, or half the app will authenticate one way and half the other.
6. **Re-evaluate the external-module scaffold** (`ExternalModuleServiceProvider`, the two contracts, the two fakes) — it was built for exactly this integration.

### WHAT CAN WAIT UNTIL AFTER JWT

- F-03 `addStudent` role validation
- F-09 removed student keeps their reserve request
- F-14 / F-16 / F-17 / F-18 transaction and locking hardening, plus the `holds` unique index
- Pickup expiry automation (wiring `ExpireHolds` to a scheduler)
- The 38 pre-existing starter-kit test failures
- Dropping `library_recovery_scratch`

---

**No fixes were implemented in this pass. No features were added. JWT was not started. Nothing was committed.**
