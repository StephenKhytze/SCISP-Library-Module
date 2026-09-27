# Library Module — Inventory & Settings Implementation Pass

**Repo:** `SCISP-Library-Module` · **Branch:** `lyndon`
**Date:** 2026-09-13 · **Scope:** eight confirmed inventory / settings additions
**Result:** all eight implemented, **289 backend tests passing** (811 assertions, 0 failures), live database intact.

**Not started, as instructed:** JWT. **Not added, as instructed:** barcode / QR scanning, external
notifications, email/SMS. No unrelated SCISP module was touched.

---

## 1. PREFLIGHT FINDINGS

Recorded before any change was made.

| # | Finding | Consequence |
|---|---|---|
| P-1 | **No accession-number column existed.** `book_copies` had `copy_id`, `book_id`, `condition`, `availability_status`, `reserve_id` — nothing human-readable | Had to be added, plus a generator and a backfill |
| P-2 | **The frontend already read `copy.barcode`, which does not exist in the schema** — `book_copies` has no `barcode` column. Every such read was `undefined` and silently fell back to `CPY-{id}` | Dead references; replaced with the real accession number rather than left in place |
| P-3 | **Zero normalised-ISBN collisions in live data** — required check before a unique index could be added | Safe to proceed. Had there been any, the migration would have been **stopped** and the conflicts reported; nothing would have been deleted or merged |
| P-4 | **`books.isbn` was NOT NULL** | Directly blocks the confirmed requirement that a title may have no ISBN. Needed a relaxation (§2) |
| P-5 | **No file-upload pattern exists anywhere in SCISP** — no disk config beyond the framework default, no existing upload endpoint to copy | Chose Laravel's `public` disk + `storage:link`; symlink verified present in the container |
| P-6 | **Policy values were hardcoded in three places** — `CirculationService` (limits, loan days), `FinesCalculator` (₱10/day), reserve loan length | The settings feature had to capture these *exactly* so behaviour does not change on upgrade |
| P-7 | **GD in the backend container has no JPEG support** | Affects test fixtures only. Production validation uses `mimetypes:` (finfo sniffing), never `image:`/`getimagesize`, so uploads are unaffected. Test fixtures were switched to `UploadedFile::fake()->create($name, $kb, $mime)` |
| P-8 | **`users.is_super_admin` was mass-assignable** (audit finding F-28) | Closed in this pass (§14) |
| P-9 | Test isolation confirmed resolving to SQLite `:memory:` **before** any test was run; all four safety layers intact | Safe to run the suite |
| P-10 | Live baseline recorded: `users 11 · books 3 · book_copies 6 · transactions 4 · holds 2 · course_reserves 2 · course_sections 3 · fines 0 · renewal_requests 1 · total fines ₱0.00` | The after-comparison in §18 |

---

## 2. SCHEMA CHANGES

Seven migrations. **Six are purely additive. One is a deliberate relaxation.** No column was
dropped, no table was rewritten, no row was deleted or merged. Applied with plain `migrate` —
never `migrate:fresh`, never `db:seed`.

| Migration | Change | Kind |
|---|---|---|
| `2026_09_13_100000_add_accession_numbers_to_book_copies` | new `library_counters` table; `book_copies.accession_number` varchar(32) nullable **unique**; deterministic backfill; counter seeded | additive |
| `2026_09_13_100001_add_bibliographic_fields_to_books` | `edition` (100), `publisher` (255), `publication_year` (smallint), `cover_image_path` (255), `isbn_normalized` (64, unique) + backfill of the normalised value | additive |
| `2026_09_13_100002_create_library_settings_tables` | `library_settings` (key PK, value, type, updated_by) + `library_setting_history` | additive |
| `2026_09_13_100003_create_copy_condition_history_table` | `copy_condition_history` | additive |
| `2026_09_13_100004_add_archive_to_books` | `archived_at`, `archived_by`, `archive_reason` | additive |
| `2026_09_13_100005_create_inventory_audit_tables` | `inventory_audits` + `inventory_audit_items`, unique on `[audit_id, copy_id]` | additive |
| `2026_09_13_100006_make_books_isbn_nullable` | **`books.isbn` NOT NULL → NULL** | **modification** |

**Why the one modification.** The confirmed feature list requires registering a title that has no
ISBN. `books.isbn` was NOT NULL, so that was impossible. The migration relaxes nullability only:
**no existing value was converted**, no default was introduced, and the unique index is retained,
so duplicate ISBNs are still impossible while multiple ISBN-less titles are now allowed.

**Backfill was deterministic, not arbitrary.** Accession numbers were assigned in `copy_id` order,
so copy 1 → `ABC-LIB-000001` … copy 6 → `ABC-LIB-000006`, and the counter was seeded to 6. Re-running
the backfill would produce the same answer.

**`archived_at` is deliberately not Laravel `SoftDeletes`.** A `deleted_at` column makes every
existing query silently skip the row — including the loan and fine history archiving is meant to
preserve. Archiving is opt-in at the query level instead.

---

## 3. ABC-LIB ACCESSION IMPLEMENTATION

**ISBN and accession number answer different questions.** The module now keeps them strictly apart,
and this distinction is documented in `LIBRARY_CURRENT_STATE.md` §22.1 and tested:

| | ISBN | Accession number |
|---|---|---|
| Identifies | the **title and edition** | one **exact physical copy** |
| Shared by | every copy of that edition, worldwide | nothing — unique to this library |
| Column | `books.isbn` | `book_copies.accession_number` |
| Optional | yes | no |
| Editable | yes | **never** |

**Format `ABC-LIB-######`** — fixed prefix, hyphen, zero-padded six-digit sequence, starting at
`ABC-LIB-000001`. Numbers are never reused, even after a copy is gone.

**Concurrency.** `AccessionNumberService::reserve(int $count)` opens a transaction, takes
`lockForUpdate()` on the `library_counters` row, increments it by the block size and returns the
block. Two librarians adding copies at the same instant serialise on the lock instead of colliding.
The unique index is the second line of defence — if anything ever bypassed the service, the database
refuses the duplicate rather than accepting it.

**Immutability.** `accession_number` is excluded from `BookCopy::$fillable`, and the model's
`booted()` hook throws a `RuntimeException` if the attribute is changed once set. A mass-assignment
attempt, a stray `update()` or a future refactor all fail loudly instead of quietly renumbering a
physical book.

---

## 4. BOOK COVER IMPLEMENTATION

`POST /api/library/books/{id}/cover` (multipart, field `cover`) · `DELETE /api/library/books/{id}/cover`

- **Accepted:** JPEG, PNG, WebP · **maximum 5 MB**
- Validated with `mimetypes:image/jpeg,image/png,image/webp|max:5120` — real MIME sniffing, not the
  client-supplied `Content-Type` and not the file extension
- Re-validated inside `BookCoverService` as the last step before the filesystem
- **The uploaded filename is never used.** The stored name is generated: `book-{id}-{16 random}.{ext}`,
  with the extension derived from the *sniffed* MIME type. An attacker-supplied name cannot introduce
  path separators, a double extension or a null byte
- Stored on the `public` disk under `library/covers`, exposed as `books.cover_url`
- One cover per **title** (the picture describes the edition; all copies look the same)
- Replacing a cover deletes the previous file
- `deleteManagedFile()` refuses any path outside the covers directory or containing `..`, so a
  tampered `cover_image_path` cannot be turned into an arbitrary-file delete
- A title with no cover keeps the existing placeholder — nothing in the UI breaks

---

## 5. DUPLICATE PREVENTION

Adding a title that already exists returns **409 Conflict** carrying the existing record, rather
than creating a second row that would split the copies between two entries.

**Two detection rules:**

1. **Normalised ISBN** — `isbn_normalized` strips hyphens, spaces and case, so `978-0132350884` and `9780132350884` are recognised as the same book. Backed by a unique index.
2. **Normalised title + author** — trimmed, whitespace-collapsed, case-insensitive — for titles with no ISBN.

**The librarian is given a way forward, not just a refusal.** The 409 payload includes
`existing_book`, and the UI turns it into: *"This book is already in the catalog — add another
physical copy to it instead?"* Accepting posts to the existing add-copies route, so the natural
recovery is one click and produces the correct data shape.

**No existing book was deleted or merged to make this possible.** Live data was checked for
collisions first (P-3: zero found); had any existed the migration would have stopped and reported
them.

---

## 6. METADATA FIELDS

`books.edition` (100), `books.publisher` (255), `books.publication_year` (smallint) — **all nullable,
all optional**. Present on the add form, the edit form and the inventory row.

Existing titles show blank. Nothing was invented, inferred or scraped to populate them.

---

## 7. SETTINGS IMPLEMENTED

`GET /api/library/settings` · `PUT /api/library/settings` — librarians only.

| Key | Default | Governs |
|---|---|---|
| `student_borrowing_enabled` | `true` | whether students may borrow at all |
| `student_max_books` | `3` | concurrent active loans |
| `student_loan_days` | `7` | loan duration |
| `student_fine_per_day` | `10.00` | overdue rate (₱) |
| `student_grace_days` | `0` | days not charged |
| `student_max_fine` | none | per-loan cap (₱) |
| `faculty_borrowing_enabled` | `true` | |
| `faculty_max_books` | `10` | |
| `faculty_loan_days` | `14` | |
| `faculty_fine_per_day` | `10.00` | |
| `faculty_grace_days` | `0` | |
| `faculty_max_fine` | none | |
| `student_renewal_enabled` | `true` | students may *request* renewals |
| `faculty_renewal_enabled` | `true` | |
| `max_renewals_per_loan` | unlimited | enforced at request **and** approval time |
| `reserve_loan_days` | `14` | course-reserve loan duration |

**The defaults are byte-for-byte the values that were hardcoded before this pass** (P-6). An
installation where nobody ever opens the settings page behaves exactly as it did yesterday.

**Defaults are not written to the database.** `LibrarySettingsService::DEFAULTS` is a constant;
`library_settings` stores only keys a librarian has actually changed. The live database still holds
**zero** settings rows. This means a future default change reaches every installation that never
overrode it, instead of being frozen into rows at migration time.

**Forward-only semantics, stated in the UI:**
- loans already checked out keep the due date they were given
- fines already charged are never recalculated
- a lowered limit blocks the *next* checkout; it does not retroactively invalidate loans already out

**Validation is per-key and explicit.** The controller never touches `$request->all()`; each key has
its own rule, and an unrecognised key is rejected rather than stored. This is what stops the
settings table becoming an arbitrary key-value store writable by anyone who can reach the endpoint.

**Registered as a container singleton** so that reads within one request-response cycle cannot
observe a stale cache after a write in the same cycle.

**Deliberately not added, as instructed:** notification settings; and a pickup-expiry setting —
nothing currently enforces pickup expiry, and a setting that silently does nothing is worse than
no setting at all.

---

## 8. SETTINGS HISTORY

`GET /api/library/settings/history` — librarians only.

Every change writes a `library_setting_history` row: key, previous value, new value, who changed it,
when, and an optional note. **Append-only** — there is no edit or delete path, in the API or the UI.

Only genuinely changed keys are recorded: the panel diffs the draft against the loaded values and
sends just the differences, so the trail stays meaningful rather than filling with no-op saves.

---

## 9. CONDITION HISTORY

`GET /api/library/copies/{id}/condition-history` — librarians only.

Every condition change writes to `copy_condition_history`: the copy, previous condition, new
condition, the acting user, an optional note, the timestamp. Append-only. Reachable from a
**History** button on each copy row in Manage Copies.

Condition and availability status remain **separate concerns**, as they were before: this trail
records physical quality, not lendability.

---

## 10. ARCHIVE / RESTORE

`POST /api/library/books/{id}/archive` · `POST /api/library/books/{id}/restore` — librarians only.

**Nothing is ever deleted.** A title that has circulated is referenced by transactions and fines,
and those records must stay readable. Archiving sets `archived_at`, `archived_by` and
`archive_reason`; the row, its copies and its whole history stay exactly where they were.

- Archived titles disappear from the catalog and cannot be borrowed, held or reserved
- They stay visible to librarians via `include_archived=1`, which is **gated to librarians** — a borrower who passes the flag still sees only the live catalog (this gap was found and closed during implementation)
- Restore returns the title immediately, unchanged

**Archiving is refused while the title is still in use** — active loans, open holds, or live course
reserves. `BookArchiveService::blockers()` returns **human sentences** ("This title still has 1
active loan."), not codes, so the librarian knows what to resolve.

---

## 11. INVENTORY AUDIT

`GET|POST /api/library/audits` · `GET /api/library/audits/{id}` ·
`POST /api/library/audits/{id}/record|complete|cancel` — librarians only.

Starting an audit snapshots every copy currently on the shelves into `inventory_audit_items` with
its expected location, all `pending`. The librarian walks the shelves and records
**found · missing · damaged · wrong_shelf**, finding each copy by typing its accession number or
title into the search box. A running summary reports expected / found / missing / damaged /
wrong-shelf / pending.

**Two rules that matter more than the feature itself:**

1. **An audit observation never changes a copy's operational status.** Marking a copy "missing" does *not* set it to `lost`. Removing a book from circulation stays a separate, deliberate decision, so a mis-tap during a shelf walk cannot silently take a title out of service. `InventoryAuditService` touches no operational copy field at all.
2. **One open audit at a time.** A second `start` is refused while one is in progress, so two half-finished shelf checks cannot interleave and produce a meaningless combined result.

Completing closes the audit with still-pending copies left **visibly pending** — nothing is quietly
assumed found. Cancelling keeps the observations and marks the session cancelled. Neither deletes
anything. The unique `[audit_id, copy_id]` index means a copy cannot be double-counted.

---

## 12. API ENDPOINTS

14 new routes. **All are inside the existing `MockAuthMiddleware:'Super Admin,Admin'` group** — there
is no new middleware and no new authorisation path to get wrong.

| Method | Route | Purpose |
|---|---|---|
| GET | `/api/library/copies/{id}/condition-history` | condition trail for one copy |
| POST | `/api/library/books/{id}/cover` | upload / replace a cover |
| DELETE | `/api/library/books/{id}/cover` | remove a cover |
| POST | `/api/library/books/{id}/archive` | archive a title |
| POST | `/api/library/books/{id}/restore` | restore a title |
| GET | `/api/library/settings` | read operational rules |
| PUT | `/api/library/settings` | change operational rules |
| GET | `/api/library/settings/history` | settings change trail |
| GET | `/api/library/audits` | list audits |
| POST | `/api/library/audits` | start an audit |
| GET | `/api/library/audits/{id}` | audit detail + summary (supports `search`) |
| POST | `/api/library/audits/{id}/record` | record one observation |
| POST | `/api/library/audits/{id}/complete` | close the audit |
| POST | `/api/library/audits/{id}/cancel` | abandon the audit |

Changed behaviour on existing routes: `POST /api/library/books` now accepts the metadata fields and
a null ISBN and returns **409** on a duplicate; `GET /api/library/books` accepts `include_archived`
(librarians only). **API surface: 33 → 53 routes** under `/api/library` across this and the previous
integration pass.

---

## 13. MINIMAL FRONTEND WIRING

Functional wiring only. **The completed Antigravity/Claude UI/UX work was not redesigned**, and no
new top-level navigation was introduced.

- **No new tab.** The two new screens live behind a small `Inventory · Library Settings · Inventory Audit` switcher inside the existing **Add Title & Copies** tab, in both the mobile and desktop layouts. The navigation design is untouched.
- `LibrarySettingsPanel.jsx` and `InventoryAuditPanel.jsx` are new, built from the existing visual vocabulary (same card, radius, weights, palette) rather than a new style.
- `AdminInventoryPanel.jsx` gained Add/Replace/Remove Cover, Archive/Restore, a per-copy **History** button, the new metadata fields on the edit form, and accession numbers in place of `CPY-{id}`.
- `LibraryPortal.jsx` shows real cover images when present (placeholder otherwise), displays accession numbers, and turns the 409 into the "Add Copy Instead" offer.
- The dead `copy.barcode` reads (P-2) were replaced with the real accession number.

**Verified against the live backend in the browser**, not simulated: the settings panel loads the
real defaults (student 3 / 7 days / ₱10), the audit panel reports no audit in progress, and Manage
Copies shows `ABC-LIB-000003` and `ABC-LIB-000006` on a real title.

**Build:** PASS — `dist/assets/index-CITz8T4g.js 505.06 kB (gzip 135.69 kB)`, `index-BztTnOky.css 45.95 kB`.
**Lint:** PASS — only the two long-standing benign `react(only-export-components)` warnings on
`ConfirmDialog.jsx` and `ToastProvider.jsx`, which predate this pass.

---

## 14. SECURITY / AUTHORIZATION

| Area | Protection |
|---|---|
| All 14 new routes | inside the existing `Super Admin,Admin` middleware group; no new auth path |
| Settings write | per-key explicit validation; `$request->all()` is never used, so an unknown key is rejected, not stored |
| Cover upload | `mimetypes:` sniffing (not extension, not client `Content-Type`); 5 MB cap; generated filename — the uploaded name is never trusted |
| Cover delete | refuses any path outside `library/covers` or containing `..` / a null byte |
| Accession number | not fillable; model throws on any attempt to change one |
| `include_archived` | **gated to librarians** — found and closed during implementation; a borrower passing the flag still sees only the live catalog |
| Archive | refused while loans, holds or reserves reference the title, so history cannot be orphaned |
| Audit | observations cannot change operational copy state; one audit at a time; `[audit_id, copy_id]` unique |
| **F-28 closed** | `is_super_admin` removed from `User::$fillable`. It was mass-assignable, which meant a payload containing that key could have granted management identity. This is the one audit finding fixed in this pass |

---

## 15. MIGRATION RESULTS

Every migration was run first against SQLite `:memory:` (through the test suite), then against the
live MySQL database. All seven applied cleanly, in order, with plain `migrate`.

- **No `migrate:fresh`, no `db:seed`, no `DatabaseSeeder`, no destructive reset** at any point
- **No `RefreshDatabase` against live MySQL** — the `phpunit.xml` `force="true"` barrier was never touched, relaxed, or worked around
- The duplicate-ISBN pre-check (P-3) was run **before** the unique index was added: zero collisions, so the migration proceeded. Had there been any, it would have stopped and reported them
- Backfills were deterministic and idempotent in effect (accession numbers by `copy_id`; normalised ISBN derived from the existing value without touching `isbn`)

---

## 16. TESTS ADDED

**87 new tests** across five new files, all on the protected SQLite `:memory:` environment.

| File | Tests | Covers |
|---|---|---|
| `AccessionAndMetadataTest.php` | 23 | format, uniqueness, immutability, sequence continuation, block reservation under concurrency, backfill order, nullable ISBN, metadata round-trip, duplicate 409 by normalised ISBN and by title+author |
| `BookCoverTest.php` | 10 | accepted types, rejected types, size cap, generated filename, replace deletes the old file, remove falls back to placeholder, path-traversal refusal, authorisation |
| `LibrarySettingsTest.php` | 20 | defaults match the previously hardcoded values, each key's effect on limits / due dates / fines / grace / caps / renewals, history rows, unknown key rejected, borrower gets 403 |
| `InventoryLifecycleTest.php` | 19 | condition history append-only, archive hides from catalog, archive blocked by loans / holds / reserves, restore, `include_archived` gated to librarians (with an explicit borrower-bypass assertion) |
| `InventoryAuditTest.php` | 15 | snapshot contents, each observation status, summary counts, **observation does not change copy status**, one-audit-at-a-time, complete leaves pending visible, cancel, authorisation |

Fine and grace calculations are tested with a frozen clock (`Carbon::setTestNow`) because
microsecond drift otherwise makes a 3-day overdue score as 4.

---

## 17. FULL LIBRARY TEST RESULT

```bash
docker exec scisp_backend php artisan test tests/Feature/Library tests/Feature/TestDatabaseSafetyTest.php
```

**289 passed · 1 skipped · 0 failed · 811 assertions · 52.25s**

**87** of those are the five new files added by this pass (§16); the remaining 202 are the existing
Library suite, still green. The 290 `test()` declarations across the 18 files match 289 + 1 skipped. The skipped test is the guard self-test, which terminates the process by design and
runs only with `GUARD_SELFTEST=1`.

Per-file counts are recorded in `LIBRARY_CURRENT_STATE.md` §14.

> **One honest caveat.** Running the *whole* `Feature` suite also executes the Laravel starter-kit
> tests in `tests/Feature/Auth`, `tests/Feature/Settings` and `DashboardTest.php`, which produce
> **38 failures**. These are **pre-existing and not a Library regression**: `tests/Pest.php` has
> `->use(RefreshDatabase::class)` commented out — a deliberate safety decision — and those
> starter-kit files never declare the trait themselves, so no tables exist when they run.
> `tests/Pest.php` is unchanged since `f4d0458 Initial project template`. Re-enabling the global
> trait is **not** safe while the default connection can resolve to live MySQL, so it was left alone.

---

## 18. LIVE DB BEFORE / AFTER

| Table | Before | After |
|---|---|---|
| `users` | 11 | **11** |
| `books` | 3 | **3** |
| `book_copies` | 6 | **6** |
| `transactions` | 4 | **4** |
| `holds` | 2 | **2** |
| `course_reserves` | 2 | **2** |
| `course_sections` | 3 | **3** |
| `fines` | 0 | **0** |
| `renewal_requests` | 1 | **1** |
| total fine balance | ₱0.00 | **₱0.00** |

New state introduced by the pass:

```
copies with an accession number   6 / 6      (ABC-LIB-000001 … ABC-LIB-000006)
accession counter                 6
archived titles                   0
library_settings rows             0          (defaults are constants, not rows)
inventory_audits                  0
copy_condition_history            0
```

**Every pre-existing count is unchanged.** No row was added to an existing table and no existing
value was altered, except the backfilled `accession_number` and `isbn_normalized` columns that the
migrations created. Spot-checked: all three ISBNs intact, reserve allocation on copy 3 intact.

---

## 19. DOCUMENTATION UPDATED

**`LIBRARY_CURRENT_STATE.md`** — header and status; §1 feature table (12 new rows, route count 33 → 53);
§6 borrowing limits now settings-driven with defaults; §7 fine rules with grace, caps and per-role
rates; §11 inventory rules rewritten for accession numbers, condition history, archiving and audits;
§14 test results replaced with the verified 289-test breakdown plus the starter-kit caveat; §16
deferred features; §17 known issues; and a new **§22** covering all eight features, the schema
changes, and the live-database verification.

**`LIBRARY_MANUAL_UAT.md`** — new **section I** with **28 cases** (UAT-I1 … UAT-I28) covering
accession numbers, ISBN-vs-accession, covers, duplicate prevention, metadata, settings and their
forward-only semantics, settings history, condition history, archive/restore, and the audit
workflow. Total **84 cases**. Header updated.

**Explicitly documented in both files:** *Barcode and QR scanning are NOT part of the current scope
and are not implemented.* The accession number is a human-readable identifier, read by eye and typed
by hand. Nothing scans, decodes or generates a barcode or QR code, and no scanner hardware is
assumed, supported or required. The audit search box exists so a librarian can *type* an accession
number.

---

## 20. ITEMS FOR ANTIGRAVITY UI POLISH

The wiring is functional and consistent with the existing vocabulary, but these are visual decisions
that belong to the design pass, not to me:

1. **The `Inventory · Library Settings · Inventory Audit` switcher** was placed inside the existing Add Title & Copies tab specifically to avoid inventing a new top-level tab. Whether these deserve their own destination is a navigation decision.
2. **Cover images in the catalog** currently drop into the same slot as the placeholder. Real covers have varied aspect ratios; cropping, letterboxing and a loading state are unstyled.
3. **The cover upload control** is a hidden file input behind a styled `<label>`. There is no drag-and-drop, no preview before commit, and no progress indicator on a slow connection.
4. **The settings panel** is a functional grid of labelled number inputs. Grouping, spacing, help text and the ₱ affordance are plain.
5. **The audit item list** is a flat scrolling list with four buttons per row. On a real shelf walk this is the screen a librarian stares at longest — it likely wants larger touch targets, a progress indicator, and a way to jump to the next pending item.
6. **The ARCHIVED badge** is a neutral grey pill. Archived rows are not otherwise visually de-emphasised.
7. **The duplicate-book dialog** reuses the standard confirm dialog. Showing the existing book's cover and copy count inside it would make the decision more obvious.
8. **The condition-history dialog** is a plain list; a timeline treatment would read better.
9. **Empty states** for settings history and past audits are single italic lines.

---

## 21. REMAINING RISKS

| # | Risk | Severity | Note |
|---|---|---|---|
| R-1 | **Manual UAT has still not been executed** | **Blocking for sign-off** | 84 cases in `LIBRARY_MANUAL_UAT.md`, 28 of them new. Automated tests prove the backend rules; they do not prove the on-screen workflow |
| R-2 | Settings changes are immediate and global | Medium | A librarian can set `student_max_books` to 0 and stop all student borrowing with one save. There is no confirmation step, no staging, and no undo beyond reading the history and typing the old value back |
| R-3 | `library_settings` starts empty by design | Low | Correct behaviour, but it means "the settings page shows 3 books" and "the database says nothing" are both true. Anyone debugging from SQL alone will not find the active values in a table |
| R-4 | Cover files are not garbage-collected on title deletion | Low | Titles are archived rather than deleted, so this is currently unreachable. It would become real if hard deletion were ever added |
| R-5 | The audit snapshot is taken at start | Low | Copies added mid-audit are not in that audit. Intended (an audit is a point-in-time check) but it will surprise someone |
| R-6 | `books.total_copies` still only ever increments | Low | Pre-existing. `lost`/`damaged` copies still count in the denominator. Availability is computed live and is correct |
| R-7 | 38 pre-existing starter-kit test failures | Low | §17. Not a Library regression, but the whole-suite output is no longer clean, which can mask a future real failure |
| R-8 | Open audit findings remain open | Mixed | F-28 closed. F-01, F-02, F-03, F-04, F-07, F-08, F-09, F-10, F-13, F-14, F-16, F-17, F-18, F-19, F-30, F-31 are still deferred — see `LIBRARY_FINAL_AUDIT.md` |
| R-9 | Nothing is committed | Info | All work remains in the working tree on branch `lyndon` |
| R-10 | JWT is still not started | Info | Deferred as instructed. Mock header auth remains the identity mechanism, and every authorisation decision above rests on it |

---

**Stopping here.** JWT not started. Barcode / QR scanning not added.
