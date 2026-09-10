# LIBRARY TASK 002
## SEC-04 + SEC-03 — Course Reserve Read & Create Authorization

**Repo:** `SCISP-Library-Module` · **Branch:** `lyndon`
**Predecessors:** `LIBRARY_TASK_001` (done), `LIBRARY_TASK_IDENTITY` (done), `LIBRARY_TASK_TEST_SAFETY` (done)
**Scope:** SEC-04 + SEC-03 only

> ⚠ **Provenance.** Recreated 2026-09-08 after the original was deleted from the repo root
> with the other `LIBRARY_*.md` specs (hard-deleted, never committed, unrecoverable).
> Reconstructed from the authoring session and re-verified against the current code before
> writing. Audit findings SEC-03/SEC-04 are the authority for the content.

---

## 1. Selected Issues

| Audit ID | Sev | Title |
|---|---|---|
| **SEC-04** | P1 | Any role can read every course reserve, including `teacher_to_admin_note` |
| **SEC-03** | P1 | Course reserve requests are not bound to the requester's own section |
| **API-02** (partial) | P1 | The `/reserves` half only; `/students` (SEC-05) stays out of scope |

**SEC-04.** `GET /api/library/reserves` sits in the outer Library group, needing only *some* `X-Mock-Role`. `ReserveController::index` returns every reserve system-wide with `user`, `section`, `book`, `copies` eager-loaded — including `teacher_to_admin_note`, private between faculty and staff.

**SEC-03.** `ReserveController::store` validates only that `section_id` *exists*. Any authenticated user can file a reserve against **any teacher's section**; it is attributed to the caller but attached to someone else's section and surfaced to that section's students via `GET /sections/me`.

### 1.1 Explicitly out of scope

| Item | Audit ID |
|---|---|
| `GET /students` directory leak + `firstOrCreate` in a GET | SEC-05 |
| Allocate 422 on empty body — **still broken after this task, not a regression** | BUG-03 |
| Denied reserve does not release copies | POT-09 |
| No allocation sanity checks | POT-08 |
| `/reserves` unpaginated | POT-17, API-09 |
| JWT / mock-auth replacement | SEC-01 |
| Role-vocabulary normalisation in `CirculationService` | BUG-04, BUG-05 |

---

## 2. Current Behaviour (verified)

`backend/routes/api.php` — reserve block inside the outer group:

```php
    // Course Reserves
    // NOTE: read/create authorization for these two is tracked separately (SEC-04 / SEC-03)
    // and is intentionally unchanged by this task.
    Route::get('/reserves', [\App\Http\Controllers\Api\ReserveController::class, 'index']);
    Route::post('/reserves', [\App\Http\Controllers\Api\ReserveController::class, 'store']);

    // Admin/Super Admin may approve, deny or release. Faculty may release ONLY their own
    // reserve — that ownership check lives in ReserveController::updateStatus.
    Route::put('/reserves/{id}/status', [...])
        ->middleware(\App\Http\Middleware\MockAuthMiddleware::class . ':Super Admin,Admin,Teacher,Faculty');
```

The `Super Admin,Admin` group already contains, from Task 001:

```php
        // Course Reserves (Admin only)
        Route::post('/reserves/{id}/allocate', [...ReserveController::class, 'allocateCopies']);
```

`ReserveController::index` and `::store` contain no role or ownership check.

### 2.1 Frontend call sites — exhaustive

| Endpoint | Only caller | Gate |
|---|---|---|
| `GET /library/reserves` | `LibraryPortal.jsx:87` | inside `if (isSuperAdmin)` at `:84` |
| `POST /library/reserves` | `TeacherReservesView.jsx:101` | component rendered only for Teacher/faculty |

`TeacherReservesView`'s section dropdown comes from `GET /library/sections` (`:31`), and `CourseSectionController::index` filters to `teacher_id = caller`. **The dropdown can only ever offer sections the caller owns**, so the new ownership check cannot reject a legitimate UI submission.

### 2.2 Live data consistency

```
course_sections:  1 -> teacher_id 5   |  2 -> teacher_id 5
course_reserves:  1 -> user_id 5, section_id 1   (owner matches section teacher)
```

The only existing reserve satisfies the new rule. No backfill needed.

---

## 3. Expected Behaviour

### 3.1 `GET /reserves`
Admin and Super Admin only. Student/Teacher → **403**. No role header → **401**.

### 3.2 `POST /reserves`
- Student → **403** (route gate)
- Teacher/Faculty → **201** for a section they own; **403** otherwise
- Admin/Super Admin → **201** for any section (ownership bypass)
- No role header → **401**

---

## 4. Files To Modify

Two modified, one appended. **No frontend changes.**

### 4.1 `backend/routes/api.php`
Move `GET /reserves` into the `Super Admin,Admin` group; add a role gate to `POST /reserves`.

**Must NOT change:** Task 001's `PUT /reserves/{id}/status` route and its `:Super Admin,Admin,Teacher,Faculty` middleware; Task 001's `POST /reserves/{id}/allocate` line inside the admin group; the outer group declaration; the admin group's middleware string. Library route count must stay **29**.

### 4.2 `backend/app/Http/Controllers/Api/ReserveController.php`
`store()` only — one inserted authorization block.

**Must NOT change:** `index()` (SEC-04 is fixed at the route layer only — do **not** add an in-controller role check, that would replicate API-10); `updateStatus()` (**Task 001's work**); `allocateCopies()`; the `use` import block — reference `\App\Models\CourseSection` fully-qualified, matching the file's existing style for `\App\Models\BookCopy`.

### 4.3 `backend/tests/Feature/Library/ReserveAuthorizationTest.php`
**APPEND ONLY.** Do not edit, reorder, or reformat T1–T15 or the existing fixture helpers — reuse them. Duplicate helper function names are a fatal PHP error.

---

## 5. Implementation Steps

**Step 1 — before state.** `php artisan route:list --path=library/reserves -v` and `--path=library | tail -3` (expect 4 routes, 29 total).

**Step 2 — replace the outer reserve block** with:

```php
    // Course Reserves
    // Faculty request a reserve for a section they own; that ownership check lives in
    // ReserveController::store. Admin/Super Admin may request for any section.
    // The admin-only listing (GET /reserves) is registered in the admin group below.
    Route::post('/reserves', [\App\Http\Controllers\Api\ReserveController::class, 'store'])
        ->middleware(\App\Http\Middleware\MockAuthMiddleware::class . ':Super Admin,Admin,Teacher,Faculty');
```

Removing the stale `NOTE` comment is intentional — this task resolves what it pointed at.

**Step 3 — add `GET /reserves` to the admin group**, above Task 001's allocate line:

```php
        // Course Reserves (Admin only)
        Route::get('/reserves', [\App\Http\Controllers\Api\ReserveController::class, 'index']);
        Route::post('/reserves/{id}/allocate', [\App\Http\Controllers\Api\ReserveController::class, 'allocateCopies']);
```

**Step 4 — insert into `store()`**, after `$validated = $request->validate([...]);` and before `$reserve = CourseReserve::create([`:

```php

        // Faculty may only file a request against a section they own. Admin/Super Admin may
        // file for any section. Mirrors the ownership idiom in CourseSectionController.
        $role = strtolower($request->attributes->get('role', ''));
        $isAdmin = in_array($role, ['admin', 'super admin']);

        if (! $isAdmin) {
            $ownsSection = \App\Models\CourseSection::where('section_id', $validated['section_id'])
                ->where('teacher_id', $request->attributes->get('user_id'))
                ->exists();

            if (! $ownsSection) {
                return response()->json([
                    'message' => 'Forbidden. You can only request a course reserve for your own section.',
                ], 403);
            }
        }
```

Three deliberate choices: the `strtolower(...) + in_array(['admin','super admin'])` idiom is copied from Task 001's `updateStatus`; an **explicit 403** (not `firstOrFail()`'s 404) keeps `ReserveController` internally consistent with Task 001; `exists()` avoids fetching a model that is not needed.

**Step 5 — verify routes.** All four reserve routes gated; count still 29.

**Step 6 — append tests (§7).**

**Step 7 — verify (§7.2).**

---

## 6. Authorization Matrix

| Action | Student | Teacher | Admin | Super Admin |
|---|---|---|---|---|
| View all reserves | **DENY** | **DENY** | ALLOW | ALLOW |
| Create reserve | **DENY** | **OWNER ONLY** | ALLOW | ALLOW |
| Approve reserve | DENY | DENY | ALLOW | ALLOW |
| Deny reserve | DENY | DENY | ALLOW | ALLOW |
| Release reserve | DENY | OWNER ONLY | ALLOW | ALLOW |
| Allocate copies | DENY | DENY | ALLOW | ALLOW |

Rows 1–2 are what this task changes; rows 3–6 are Task 001's, unchanged.

---

## 7. Response Precedence & Tests

### 7.1 Precedence

**`GET /reserves`:** 401 (no role) → 403 (not admin) → 200.

**`POST /reserves`:** 401 (no role) → 403 (Student, route gate) → 422 (invalid body) → 403 (not owner) → 201.

Validation precedes the ownership check, so a malformed body from a non-owner returns 422 before 403 — the same precedence Task 001 established.

### 7.2 Tests to append (S1–S12)

| ID | Caller | Request | Expect |
|---|---|---|---|
| S1 | Student | `GET /reserves` | 403 |
| S2 | Teacher | `GET /reserves` | 403 |
| S3 | Admin | `GET /reserves` | 200, JSON array |
| S4 | Super Admin | `GET /reserves` | 200 |
| S5 | none | `GET /reserves` | 401 |
| S6 | Teacher | `POST /reserves`, own section | 201; row created with caller `user_id`, status `pending` |
| S7 | Teacher | `POST /reserves`, another teacher's section | 403; **no row created** |
| S8 | Student | `POST /reserves` | 403; **no row created** |
| S9 | Admin | `POST /reserves`, teacher's section | 201 (ownership bypass) |
| S10 | Teacher | `POST /reserves`, nonexistent `section_id` | 422 |
| S11 | none | `POST /reserves` | 401 |
| S12 | Faculty | `POST /reserves`, another teacher's section | 403 |

Assert the **absence of a row** in S7/S8 — that is the actual security property.

⚠ Fixture users must be **role-consistent** with their header role, because `LIBRARY_TASK_IDENTITY` now enforces role matching (Teacher→faculty, Admin/Super Admin→administrator, Student→student). A mismatch yields 403 from the middleware, not from this task's logic.

---

## 8. Frontend Impact

**None. Do not modify any frontend file.**

1. URLs and verbs are unchanged.
2. Both call sites are already correctly gated (§2.1).
3. `TeacherReservesView`'s dropdown only offers owned sections, so the ownership check cannot reject legitimate use.
4. Students never render the buttons; the fix hardens against direct API calls.
5. Both handlers already surface `err.response?.data?.message`, so a 403 displays without crashing.

If the implementer believes a frontend change is needed, **stop and report** rather than editing.

---

## 9. Regression Risks

| # | Risk | Assessment |
|---|---|---|
| R1 | Admin reserves dashboard stops loading | Low — called only under `isSuperAdmin`; both roles in the gate |
| R2 | Teacher "Request Course Reserve" breaks | Low — dropdown only offers owned sections |
| R3 | Admin cannot create a reserve | Low — `$isAdmin` bypasses ownership |
| R4 | Existing data violates the new rule | **None** — verified §2.2 |
| R5 | Student Reserves tab breaks | **None** — students read via `GET /sections/me`, untouched |
| R6 | **Overwriting Task 001 / IDENTITY work** | **Moderate — most likely failure mode.** Both files carry uncommitted work |
| R7 | Scope creep into SEC-05 | Moderate — `/students` is adjacent |
| R8 | Double middleware execution | Accepted (POT-15), same as Task 001 |
| R9 | 403-vs-404 inconsistency with `CourseSectionController` | Intentional; documented |

---

## 10. Acceptance Criteria

| ID | Criterion |
|---|---|
| AC-1 | `GET /reserves` carries `MockAuthMiddleware:Super Admin,Admin` |
| AC-2 | `POST /reserves` carries `MockAuthMiddleware:Super Admin,Admin,Teacher,Faculty` |
| AC-3 | Both keep their exact URL and verb |
| AC-4 | Library route count still **29** |
| AC-5 | Student and Teacher get 403 on `GET /reserves`; Admin and Super Admin get 200 |
| AC-6 | Student gets 403 on `POST /reserves`, no row created |
| AC-7 | Teacher: 201 own section, 403 other's, no row created on 403 |
| AC-8 | Admin gets 201 for any section |
| AC-9 | No role header → 401 on both endpoints |
| AC-10 | `index()` and `allocateCopies()` byte-for-byte unchanged |
| AC-11 | No frontend file modified |
| AC-12 | Task 001's `updateStatus` block intact |
| AC-13 | Task 001's route work intact |
| AC-14 | `GET /students` untouched (SEC-05 not started) |
| AC-15 | No vendor / composer / phpunit.xml / migration / seeder change |
| AC-16 | T1–T15 unmodified; S1–S12 appended |
| AC-17 | Exactly three files touched |
| AC-18 | No Policy, Gate, Form Request, service or middleware introduced |
| AC-19 | Allocate still 422s on empty body (BUG-03 untouched) |
| AC-20 | Live MySQL row counts unchanged: `11 / 3 / 5 / 1 / 2 / 2` |

---

## 11. Working Tree Protection

Uncommitted work from **Task 001**, **Task IDENTITY** and **Task TEST_SAFETY** is present. Nothing is committed; there is no history to recover it from.

| File | Task | Rule |
|---|---|---|
| `routes/api.php` | 001 | Edit **surgically** (this task changes it) |
| `Api/ReserveController.php` | 001 | Edit **surgically** (this task changes `store` only) |
| `tests/Feature/Library/ReserveAuthorizationTest.php` | 001 | **Append only** |
| `Http/Middleware/MockAuthMiddleware.php` | IDENTITY | **Do not touch** |
| `Api/CourseSectionController.php` | IDENTITY | **Do not touch** |
| `database/seeders/MockPersonaSeeder.php` | IDENTITY | **Do not touch** |
| `tests/Feature/Library/MockAuthIdentityTest.php` | IDENTITY | **Do not touch** |
| `phpunit.xml`, `tests/TestCase.php`, `tests/Feature/TestDatabaseSafetyTest.php`, `.env.testing` | TEST_SAFETY | **Do not touch** |

**Rules:** never `git checkout/restore/stash/reset`; never rewrite a file wholesale; no reformatting outside named regions; **do not commit**.

**Pre/post-flight greps (must be identical):**

```bash
cd backend
grep -c "Super Admin,Admin,Teacher,Faculty" routes/api.php                                    # 1 before → 2 after (status + store)
grep -c "not the owner of this course reserve" app/Http/Controllers/Api/ReserveController.php  # 1
grep -c "Unauthorized. Unknown user." app/Http/Middleware/MockAuthMiddleware.php               # 1
grep -c "T1: student cannot approve" tests/Feature/Library/ReserveAuthorizationTest.php        # 1
grep -c "I8: a missing username is rejected" tests/Feature/Library/MockAuthIdentityTest.php    # 1
grep -c "only request a course reserve for your own section" app/Http/Controllers/Api/ReserveController.php  # 0 before → 1 after
```

---

## 12. Test Execution

Tests run **inside the container only** (host PHP 8.2 cannot run them):

```bash
docker exec scisp_backend php artisan test --filter=ReserveAuthorization
```

`LIBRARY_TASK_TEST_SAFETY` guarantees this uses sqlite `:memory:` and can never touch the live MySQL database.
