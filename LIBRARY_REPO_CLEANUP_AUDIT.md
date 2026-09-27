# Library Module — Repository Cleanup Audit

**Repo:** `SCISP-Library-Module` · **Branch:** `lyndon` · **Date:** 2026-09-13
**Type:** Audit only. **Nothing was deleted, moved, renamed, uninstalled or committed.**

> **Headline: the repository is far cleaner than the brief anticipated.**
> There are no `nul` files, no SQL dumps, no scratch scripts, no `.bak`/`.tmp`/`.orig`
> files, no tracked build output, no tracked logs, and no tracked secrets. The nine loose
> debug scripts that `LIBRARY_CURRENT_STATE.md` §16 warns about are **already gone** from
> the working tree. The proposed removal list is therefore short: **2 dead backend files,
> 5 potentially-obsolete documents, and 1 live MySQL database** that is not a file at all.

---

## 1. ORIGINAL BASELINE SUMMARY

The baseline resolved cleanly — no guesswork was needed.

| | |
|---|---|
| Remote | `https://github.com/StephenKhytze/SCISP-Library-Module.git` (matches the brief) |
| Baseline commit | **`f4d0458` "Initial project template"** — the *entire* history of `origin/master` |
| Relationship | `git merge-base --is-ancestor origin/master HEAD` → **YES**, a direct ancestor |
| Baseline files | **128 tracked** |
| Current `HEAD` | **190 tracked** |

Because the baseline is a genuine ancestor, every classification below is derived from
`git diff f4d0458..HEAD` and `git cat-file -e f4d0458:<path>` — not from filename guesses.

### A. Existed in the original repository
128 files: the Laravel 12/13 skeleton, the Fortify/passkey auth scaffold, the React+Vite
shell (`App.jsx`, `Layout`, `Sidebar`, `Topbar`, routing, `index.css`, `services/api.js`),
the five non-Library modules (home, schedule, announcements, student_info, faculty, auth),
Docker config, and the starter-kit tests.

### B. Added during Library development
**62 committed** + **58 untracked in the working tree** = 120 files.
Committed additions are the first Library backend/frontend generation; the untracked set is
this session's work (renewals, super-admin, accession numbers, covers, settings, condition
history, archive, audits, categories) and the pass reports.

### C. Modified during Library development
**17 files.** Notably `backend/routes/api.php`, `User.php`, `phpunit.xml`, `TestCase.php`,
`config/cors.php`, `bootstrap/providers.php`, `docker-compose.yml`, `vite.config.js`,
`App.jsx`, `Layout.jsx`, `Topbar.jsx`, `index.css`, `LibraryPortal.jsx`,
`0001_01_01_000000_create_users_table.php`, `DatabaseSeeder.php`, `.env.example`,
`composer.json`.

### D. No longer exist locally
**None.** `git diff --diff-filter=D f4d0458 HEAD` returns nothing — **not one baseline file
has been deleted.** The original project is fully intact.

---

## 2. CURRENT LIBRARY ADDITIONS

Traced by actual imports and route registrations, not by directory name.

**Frontend** — every component under `frontend/src/modules/library/` is imported at least
once, and `LibraryPortal` is routed at `/library` in `App.jsx`. Shared primitives are the
most-used files in the codebase: `StatusBadge` (8 importers), `useDialog` (7),
`ToastProvider` (7), `ConfirmDialog` (6), `CategorySelect` (3).

**Backend** — 53 routes under `/api/library`. Every Library controller is routed, every
service is referenced, every model is referenced. Verified counts below.

---

## 3. KEEP — ORIGINAL BASE

All 128 baseline files. **This repository is still the SCISP application shell**, and the
evidence is unambiguous: every original frontend module is imported *and* routed in
`App.jsx` — Dashboard `/`, ScheduleView `/schedule`, AnnouncementList `/announcements`,
StudentProfile `/student-info`, FacultyList `/faculty`, Login `/auth`. None is orphaned.

Per §5 of the brief, no original file is proposed for deletion merely because the Library
does not import it. The two genuinely unrouted originals are in **REVIEW MANUALLY**.

---

## 4. KEEP — LIBRARY REQUIRED

### Controllers (all routed)

| Controller | Routes |
|---|---|
| `BookController` | 8 |
| `BookCopyController` | 3 |
| `CirculationController` | 7 |
| `CourseSectionController` | 7 |
| `FinesController` | 3 |
| `HoldController` | 4 |
| `ReserveController` | 7 |
| `RenewalController` | 5 |
| `InventoryAuditController` | 6 |
| `LibrarySettingsController` | 3 |
| `LibraryCategoryController` | 3 |

### Services (all referenced)

`AccessionNumberService` (3) · `BookArchiveService` (2) · `BookCoverService` (2) ·
`CategoryService` (2) · `CirculationService` (4) · `FinesCalculator` (5) · `HoldService` (4) ·
`InventoryAuditService` (1) · `InventoryService` (3) · `LibrarySettingsService` (7) ·
`RenewalService` (3)

### Models (all referenced)

`Book` (27) · `BookCopy` (26) · `Transaction` (20) · `Hold` (16) · `CourseReserve` (15) ·
`CourseSection` (13) · `CourseSectionStudent` (9) · `LibraryCategory` (5) ·
`CopyConditionHistory` (5) · `RenewalRequest` (5) · `InventoryAudit` (4) ·
`InventoryAuditItem` (4) · `LibrarySettingHistory` (3) · `Fine` (3) · `LibrarySetting` (2)

### Frontend (all imported)

`LibraryPortal` · `AdminInventoryPanel` · `AdminFinesPanel` · `HoldQueuePanel` ·
`TeacherReservesView` · `AdminReserveCard` · `StudentCourseReserveCard` · `ClassmatesModal` ·
`RenewalRequestsPanel` · `LibrarySettingsPanel` · `InventoryAuditPanel` · `AddBookForm` ·
`CategorySelect` · `StatusBadge` · `ToastProvider` · `ConfirmDialog` · `useDialog` ·
`components/MobileNavDrawer.jsx`

### Two files that look dead but are NOT — do not remove

| File | Why it looks dead | Why it stays |
|---|---|---|
| `InventoryAuditPanel.jsx` + audit backend | Currently hidden from the switcher | Explicitly preserved per the brief §8. Fully implemented, routed, and covered by 15 tests |
| `app/Console/Commands/ExpireHolds.php` | Not in `routes/console.php`, never referenced | It is **auto-discovered** by Laravel, so `php artisan holds:expire` works today. It is an *unwired* feature, not dead code — `LIBRARY_CURRENT_STATE.md` §17 item 5 documents the gap it fills |

---

## 5. KEEP — SHARED DEPENDENCY

`frontend/src/components/Layout.jsx`, `Sidebar.jsx`, `Topbar.jsx`, `MobileNavDrawer.jsx`,
`App.jsx`, `main.jsx`, `index.css`, `frontend/src/api.js`, `backend/app/Models/User.php`,
`app/Http/Middleware/MockAuthMiddleware.php` (5 referencing files), `config/cors.php`,
`routes/api.php`.

**CSS audit:** every class defined in `index.css` is used, and every class referenced from
JSX is defined. No dead CSS.

| Class | JSX files | CSS definitions |
|---|---|---|
| `lib-toggle` | 1 | 9 |
| `lib-field-prefix` / `lib-field-suffix` | 1 / 1 | 1 / 1 |
| `lib-btn-press` | 2 | 3 |
| `lib-drawer` | 1 | 1 |
| `anim-fade-in` / `anim-zoom-in` | 10 / 6 | 2 / 2 |
| `anim-slide-in-left` / `-right` / `anim-slide-up` | 1 each | defined |

> *Correction to an earlier statement in this session:* I previously reported the `lib-*`
> helper classes as "referenced but defined nowhere." They are all defined in `index.css`
> now. Nothing needs fixing.

---

## 6. KEEP — BUILD / RUNTIME

`docker-compose.yml` · `backend/Dockerfile` · `backend/artisan` · `backend/composer.json` ·
`composer.lock` · `bootstrap/app.php` · `bootstrap/providers.php` · `backend/config/**` ·
`backend/public/index.php` · `frontend/package.json` · `package-lock.json` ·
`frontend/vite.config.js` · `frontend/index.html` · `backend/.env.example` ·
`frontend/.env.example` · `phpstan.neon` · `pint.json` · `.prettierrc` · `.editorconfig` ·
`.gitattributes` · `backend/.github/**` · all `storage/**/.gitignore` placeholder files.

**Dependency audit — nothing removable.**

*Frontend:* `axios`, `lucide-react`, `react`, `react-dom`, `react-router-dom`, `tailwindcss`
are used in source. `@types/react`, `@types/react-dom`, `@vitejs/plugin-react`,
`autoprefixer`, `postcss`, `oxlint`, `vite` are build/lint tooling referenced by config.

*Backend:* `laravel/framework`, `laravel/fortify` (auth scaffold), `laravel/tinker`,
`laravel/chisel`, `laravel/wayfinder`; dev: `pestphp/pest` + laravel plugin (the entire test
suite), `larastan`, `pint`, `collision`, `mockery`, `faker` (used by `UserFactory`),
`laravel/pail`, `laravel/sail`, `laravel/pao`.

---

## 7. KEEP — TEST / SAFETY

All 19 files under `backend/tests/Feature/Library/`, plus
`backend/tests/Feature/TestDatabaseSafetyTest.php`, `backend/tests/TestCase.php`,
`backend/tests/Pest.php`, `backend/phpunit.xml`, `backend/.env.testing`,
`backend/database/factories/UserFactory.php`.

Current suite: **313 passed, 1 skipped, 0 failed (873 assertions).**

`phpunit.xml` + `TestCase::guardAgainstProtectedDatabase()` are the barrier that keeps tests
off live MySQL. **These must never be removed or relaxed.**

### Migrations — KEEP ALL 28, without exception

Per §6 of the brief I was conservative and verified rather than reasoned:

- **28 migration files on disk, 28 rows in the live `migrations` table, 0 pending.**
- Later migrations depend on earlier ones by column (`add_copy_id_to_holds_table` →
  `create_holds_table`; `add_reserve_id_to_holds_table` → both; `update_book_copies_table_for_reserves`
  → `create_book_copies_table`; `add_accession_numbers_to_book_copies` → the same;
  `make_books_isbn_nullable` → `create_books_table`).
- Deleting any of them breaks a from-scratch build. **No migration is a removal candidate.**

---

## 8. KEEP — CURRENT DOCUMENTATION

| File | Why it stays |
|---|---|
| `LIBRARY_CURRENT_STATE.md` | The living system description. 22 sections, updated 2026-09-13 |
| `LIBRARY_MANUAL_UAT.md` | 84 UAT cases, **still unexecuted** — this is a pending deliverable, not a record |
| `LIBRARY_FINAL_AUDIT.md` | **The only place the audit findings are defined.** 101 `F-nn` references here vs 18 mentions in `LIBRARY_CURRENT_STATE.md`, which points *at* this file for the 16 still-open findings. Removing it would orphan §17 |
| `Project Setup Guide.md` | ORIGINAL. Setup/onboarding |
| `TEAM_GUIDE.md` | ORIGINAL. Team conventions |

---

## 9. PROPOSED REMOVALS

### 9.1 Frontend

**No whole files proposed for removal.** Every component is imported and reachable.

Two *code-level* issues found, listed for completeness — these are edits, not deletions, and
are **not** part of the removal list:

| Location | Issue |
|---|---|
| `frontend/src/modules/library/TeacherReservesView.jsx:457` | Reads `copy.barcode`. **`book_copies` has no `barcode` column** — confirmed by `DESCRIBE`. Always `undefined`, always falls back to `CPY-{id}`. The same dead read was fixed in `LibraryPortal.jsx` earlier; this one was missed |
| `LibraryPortal.jsx:1544` and `:2540` | UI copy reads *"Live barcode tracking of physical copies"*. Barcode scanning is explicitly out of scope, so this text promises a feature that does not exist |

### 9.2 Backend

| Path | In original GitHub? | Referenced now? | Why removal is safe | Risk if removed |
|---|---|---|---|---|
| `backend/app/Http/Middleware/ExternalAuthMiddleware.php` (62 lines) | **No** — added during Library dev | **No.** 0 references outside itself. Not aliased in `bootstrap/app.php`, not used in `routes/api.php` | It can never execute: Laravel middleware runs only via an alias or group registration, and it has neither | Low. If it were ever intended as the JWT bridge, `VerifyJwtToken` (original, aliased `auth.jwt`) already occupies that role. Its only consumer would be `AuthServiceInterface`, itself unreferenced elsewhere |
| `backend/app/Http/Middleware/RequireAdminRole.php` (28 lines) | **No** — added during Library dev | **No.** 0 references | Superseded by `MockAuthMiddleware:'Super Admin,Admin'`, which is what actually gates all 53 Library routes | Low. Role gating is covered by 313 passing tests, none of which touch this class |

**Not proposed, deliberately:** `BookController::categories()` is now unrouted (the endpoint
moved to `LibraryCategoryController@index` during the category work). It is a 5-line dead
method *inside a live file*, so removing it is an edit, not a file deletion. Flagged here so
it is not forgotten.

### 9.3 Tests

**No removals.** No abandoned fixtures, no stale factories. `UserFactory.php` is the only
factory and is used by the starter-kit tests.

### 9.4 Docs — potentially obsolete

All five are **tracked**. Each is a point-in-time development artifact whose durable content
now lives in `LIBRARY_CURRENT_STATE.md`.

| Path | In original GitHub? | Referenced now? | Why removal is safe | Risk if removed |
|---|---|---|---|---|
| `LIBRARY_TASK_002.md` | No | No | A task *brief* for SEC-03/SEC-04 (course-reserve authorization). Both are implemented and described in `LIBRARY_CURRENT_STATE.md` §12, which carries 9 `SEC-0n` references. Covered by `ReserveAuthorizationTest` (27 tests) | Low — loses the original task wording, not the behaviour |
| `LIBRARY_TASK_TEST_SAFETY.md` | No | No | A task *brief* for the test-DB barrier. The delivered architecture is documented in `LIBRARY_CURRENT_STATE.md` §13 and enforced by `TestDatabaseSafetyTest` | Low — but see risk note below |
| `LIBRARY_SCOPE_GAP_ANALYSIS.md` | No | No | Pre-implementation gap analysis (2026-09-08). Every gap it lists has since been closed or explicitly deferred in §15/§16/§17 | Low — historical rationale for scope decisions is lost |
| `changelog_today.md` | No | No | Dated 2026-09-04, before most Library work. Superseded by git history and §21/§22 | Low |
| `diagram_alignment_report.md` | No | No | Dated 2026-09-04. Compares implementation to design diagrams that have since changed substantially | **Medium** — if the diagrams are an academic deliverable, this may be graded evidence. Confirm before deleting |

> **Risk note on the two `LIBRARY_TASK_*` files:** these read as assignment briefs. If this
> repository is coursework, a task brief can be required evidence even when its content is
> superseded. That is a call I cannot make from the code.

### 9.5 Scripts

**None found.** No ad-hoc scripts, no debug endpoints, no temporary SQL. The nine loose
backend debug scripts described in `LIBRARY_CURRENT_STATE.md` §16 are **no longer present** —
that line in the state doc is itself stale and should be corrected.

### 9.6 Generated files

**None tracked.** Verified: `git ls-files` matching `.env`, `*.log`, `*.sqlite`, `/dist/`,
`node_modules`, `/vendor/`, `*.sql`, cache, and uploaded images returns **zero results**.
The only tracked files under `backend/storage/` are the ten `.gitignore` placeholders Laravel
requires.

### 9.7 Other — one item, and it is not a file

| Item | Detail |
|---|---|
| **`library_recovery_scratch` MySQL database** | Still present on the `scisp_db` container. **528 KB**, 16 tables, holding a stale snapshot: `users` 11, `books` 3, `book_copies` **5** (live has 6), `transactions` 0, `holds` 0, `course_reserves` 0, `migrations` 17 (live has 28). A post-incident artifact predating most Library work |

**Why it is safe to drop:** nothing in the codebase references it — `backend/.env` points at
`DB_DATABASE=laravel`, and no config, test or script names it.

**Risk if dropped: this is irreversible destruction of live database state.** It is the only
proposed removal that cannot be undone with `git checkout`. **My recommendation: do not drop
it as part of a file cleanup.** If you want it gone, take `mysqldump library_recovery_scratch`
to a file outside the repo first, confirm the dump restores, and only then drop it. I have not
touched it.

---

## 10. REVIEW MANUALLY

| Item | Why it cannot be auto-classified |
|---|---|
| `backend/app/Http/Controllers/Settings/ProfileController.php` | **ORIGINAL SCISP file**, 0 routes, 0 references. Per brief §5 an original file is not removed just because the Library ignores it. `tests/Feature/Settings/` still exercises this area |
| `backend/app/Http/Controllers/Settings/SecurityController.php` | Same as above |
| **The external-module scaffold cluster** — `app/Providers/ExternalModuleServiceProvider.php`, `Services/Contracts/AuthServiceInterface.php`, `Services/Contracts/ProfileServiceInterface.php`, `Services/Fakes/FakeAuthService.php`, `Services/Fakes/FakeProfileService.php` (5 files, ~151 lines) | All added during Library dev, and functionally dead: the provider **is** registered in `bootstrap/providers.php` so it boots, but it only *binds* two interfaces that nothing ever resolves. `ProfileServiceInterface` has no consumer at all; `AuthServiceInterface`'s only consumer is the dead `ExternalAuthMiddleware`. **Not on the removal list** because (a) removing it requires editing `bootstrap/providers.php`, not just deleting files, and (b) it reads as deliberate scaffolding for a future SCISP-wide integration, which is a product decision |
| `backend/app/Http/Controllers/Library/LibraryController.php` | **ORIGINAL**. Routed at `GET /api/library/` but returns only `{"message":"Library endpoint placeholder"}`. No frontend call reaches it. Harmless; removing means also editing `routes/api.php` |
| `frontend/src/services/api.js` **vs** `frontend/src/api.js` | **Both are live and neither is a duplicate to delete.** `services/api.js` is ORIGINAL and sends a JWT bearer token — used only by `Login.jsx`. `api.js` was added for the Library and sends the mock-auth headers — used by 7 Library components. They implement *different* auth mechanisms. They will need to converge when JWT lands; until then, removing either breaks something |
| `backend/.env.example` carrying `DB_USERNAME=root` / `DB_PASSWORD=secret` | **Pre-existing in the baseline** (`f4d0458` lines 26–28) and identical to the values in the tracked `docker-compose.yml`. So it leaks nothing that is not already committed, and it is not a Library-introduced problem. Still worth deciding whether a working password belongs in a tracked example file |
| `LIBRARY_FRONTEND_VERIFICATION.md` (47 KB) | **Not obsolete.** `LIBRARY_CURRENT_STATE.md` has sections for the backend-integration pass (§21) and the inventory pass (§22) but **no section covering either frontend pass** — so this report's findings are not folded in anywhere |
| `LIBRARY_FRONTEND_COMPLETION.md` (23 KB) | Same as above |
| `LIBRARY_BACKEND_INTEGRATION.md` (23 KB) | Largely folded into `LIBRARY_CURRENT_STATE.md` §21, but §21 is a summary — the report carries the reasoning and the performance measurements. Lean keep |
| `LIBRARY_INVENTORY_FEATURES.md` (29 KB) | Largely folded into §22, same caveat. Lean keep |

---

## 11. GITIGNORE REVIEW — PASS

Every sensitive or generated path is already ignored, and I verified the *rule* that ignores
each one rather than trusting the outcome:

| Path | Ignored by |
|---|---|
| `backend/.env` | `backend/.gitignore:.env` — and it is **not tracked** |
| `backend/vendor/` | `backend/.gitignore:/vendor` |
| `backend/database/database.sqlite` | `backend/database/.gitignore:*.sqlite*` |
| `backend/storage/logs/laravel.log` | `backend/storage/logs/.gitignore:*` |
| `backend/storage/app/public/library/covers` (uploaded covers) | `backend/storage/app/public/.gitignore:*` |
| `backend/public/storage` (symlink) | `backend/.gitignore:/public/storage` |
| `frontend/dist/` | `frontend/.gitignore:dist` |
| `frontend/node_modules/` | `frontend/.gitignore:node_modules` |

**No credential is exposed by this cleanup**, and no new ignore rule is needed. There is no
root-level `.gitignore`, but `backend/` and `frontend/` each carry their own and between them
they cover everything present.

---

## 12. SUMMARY OF THE PROPOSED REMOVAL LIST

| # | Path | Category | Tracked? | Reversible? |
|---|---|---|---|---|
| 1 | `backend/app/Http/Middleware/ExternalAuthMiddleware.php` | DEAD CODE | committed | Yes — git |
| 2 | `backend/app/Http/Middleware/RequireAdminRole.php` | DEAD CODE | committed | Yes — git |
| 3 | `LIBRARY_TASK_002.md` | OBSOLETE DOC | committed | Yes — git |
| 4 | `LIBRARY_TASK_TEST_SAFETY.md` | OBSOLETE DOC | committed | Yes — git |
| 5 | `LIBRARY_SCOPE_GAP_ANALYSIS.md` | OBSOLETE DOC | committed | Yes — git |
| 6 | `changelog_today.md` | OBSOLETE DOC | committed | Yes — git |
| 7 | `diagram_alignment_report.md` | OBSOLETE DOC | committed | Yes — git |
| 8 | `library_recovery_scratch` MySQL DB | OTHER — live DB state | n/a | **NO — irreversible** |

Items 1–7 are all tracked in git, so every one is recoverable with `git checkout f4d0458 -- <path>`.
**Item 8 is not, and I recommend handling it separately from the file cleanup.**

### Code-level edits found during the audit (not deletions, listed so they are not lost)

1. `TeacherReservesView.jsx:457` — dead `copy.barcode` read; should use `accession_number`.
2. `LibraryPortal.jsx:1544, 2540` — "Live barcode tracking" copy contradicts the out-of-scope decision.
3. `BookController::categories()` — unrouted dead method.
4. `LIBRARY_CURRENT_STATE.md` §16 — claims nine loose debug scripts exist at `backend/`; they do not.

---

## 13. NOT DONE — AWAITING APPROVAL

No file was deleted, moved or renamed. No package was uninstalled. No migration was altered.
No database was dropped. Nothing was committed or staged. Git state is exactly as it was
before this audit, except for this report file.
