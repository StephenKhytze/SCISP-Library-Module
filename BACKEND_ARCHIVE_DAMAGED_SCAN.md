# Backend Scan — Damaged / Lost / Archive Rules

**Repo:** `SCISP-Library-Module` · **Branch:** `lyndon` · **Date:** 2026-09-19
**Type:** Read-only inspection. No code, schema, docs or live data changed. No migration run. JWT not started.

Every finding below is either traced in code (with `file:line`) or observed in live data through read-only `SELECT`/`GET`. Nothing was inferred from names.

---

## Headline findings

1. **`damaged` is not a condition today.** `condition` is `enum('new','good','fair','poor')`. `damaged` and `lost` exist **only** in `availability_status`. The rule "condition = damaged" cannot be expressed without a schema change — or a decision to drive it from availability instead (**D-1**).
2. **The two fields contradict each other in live data right now:** copy `ABC-LIB-000006` is `condition=new` + `availability=damaged`.
3. **Check-in silently erases damaged/lost.** `CirculationService::checkin` either hands the returned copy to the next hold or sets it `available` — unconditionally. A copy marked damaged mid-loan comes back `available`, or goes to the next borrower.
4. **Checkout already blocks lost and damaged** correctly, via `availability_status !== 'available'`.
5. **Individual copy archive: no support.** `book_copies` has no field for it.
6. **UAT "can't find archived title to restore": the backend is not the cause.** It returns archived titles to librarians on `?include_archived=1`; **the frontend never sends that parameter**, so the title disappears from Admin inventory and the existing Restore button can never render.

---

## 1. CURRENT COPY CONDITION MODEL

| | |
|---|---|
| Column | `book_copies.condition` |
| Type | `enum('new','good','fair','poor')`, default `good` (`2026_08_25_000002_create_book_copies_table.php:17`) |
| Validation | `sometimes\|in:new,good,fair,poor` (`BookCopyController.php:53`, and `store`) |
| Meaning today | physical wear only; **never blocks anything** |
| `damaged` / `lost` | **not valid condition values** |

Condition is fully independent of availability. Nothing reads condition when deciding checkout, holds, reserves or counts.

## 2. CURRENT AVAILABILITY MODEL

| | |
|---|---|
| Column | `book_copies.availability_status` |
| Type | `enum('available','checked_out','on_hold','lost','damaged')`, default `available` |
| Set by circulation | `checked_out` (checkout), `available` (check-in / hold cancel), `on_hold` (hold promotion / reserve request) |
| Set manually | `InventoryService::updateCopyStatus` (`:248`) via `PUT /copies/{id}` |
| Manual values the **UI** offers | `available`, `lost`, `damaged` (`AdminInventoryPanel.jsx:37`) |
| Manual values the **API** accepts | **all five** — including `checked_out` and `on_hold` |

**Live distribution (read-only):** `good/available ×4` · `new/available ×1` · **`new/damaged ×1`**.

**Contradictions possible today:**

| State | Possible? | How |
|---|---|---|
| `condition=poor` + `available` | yes | by design — wear is not unavailability |
| condition says fine, availability `damaged` | **yes — exists live** | fields are independent |
| `checked_out` with no active loan | yes | API accepts manual `checked_out` |
| `on_hold` with no live hold | yes | API accepts manual `on_hold` |
| `damaged`/`lost` **with an active loan** | yes | no guard against it |
| `damaged`/`lost` **with a ready-for-pickup hold on it** | yes | no guard against it |
| `damaged`/`lost` **allocated to a live reserve** | yes | no guard, and explicit allocation doesn't check status (§11) |

Live check found **0** instances of the last five — they are possible, not present.

## 3. DAMAGED RULE STATUS

Every path that changes condition or availability:

| Path | Effect |
|---|---|
| `PUT /copies/{id}` → `updateCopyStatus` (`:248`) | writes `condition` and `availability_status` independently. **Only guard:** refuses `→ available` while an active loan exists |
| `POST /books/{id}/copies` → `addCopies` | new copy: chosen condition + `available` |
| checkout | `→ checked_out` |
| check-in (`CirculationService:237`) | **`→ on_hold` (next hold) or `→ available`, regardless of prior status** |
| hold promote (`HoldService:252`) | **`→ on_hold`, regardless of prior status** |
| hold cancel (`HoldService:303`) | `on_hold → available` only if currently `on_hold` — safe |

Against the four questions:

- **A. only changes condition** — setting condition never affects availability.
- **B. also changes availability** — **no**. There is no coupling at all.
- **C. blocks checkout elsewhere** — yes, but **only through `availability_status = damaged`**, never through condition.
- **D. still allows checkout accidentally** — **yes, via check-in.** A copy set `damaged` during a loan is reset to `available` on return (or promoted to the next borrower). After that, checkout succeeds.

**Status: NOT MET.**

## 4. LOST RULE STATUS

`lost` is **availability-only** — not a condition, not a separate flag.

- Checkout **blocks** it (`C22` covers this).
- It is excluded from general availability counts (`AV2`).
- It suffers the **same check-in erasure** as damaged: a copy marked lost during a loan becomes `available` when checked in.
- A lost copy can still carry a stale `condition` (e.g. `good`) — harmless but meaningless.

**Status: PARTIALLY MET** — blocked at checkout, but not protected through check-in or hold promotion.

## 5. CHECKOUT BLOCKERS

`POST /checkout` → `CirculationController@checkout` → `CirculationService::checkout` (in `DB::transaction`, copy row `lockForUpdate`). In order:

1. copy not found
2. borrower not found
3. **title archived** (`$book->isArchived()`)
4. borrower is Super Admin (`canBorrow()`)
5. borrowing disabled for the borrower's role (settings)
6. outstanding fine balance
7. role loan limit reached
8. reserve copy (approved reserve) + student not enrolled
9. `availability_status !== 'available'`:
   - `on_hold` → allowed **only** for the holder's `fulfilled` hold
   - `checked_out`, `lost`, `damaged` → **blocked**

| Case | Blocked? |
|---|---|
| checked_out | ✅ |
| on_hold (someone else's) | ✅ |
| damaged | ✅ (by availability only) |
| lost | ✅ |
| reserve copy, non-enrolled student | ✅ |
| archived title | ✅ |
| **archived individual copy** | — does not exist. **Would need an explicit new blocker** under Option A; automatic under Option B (§13) |
| copy with `reserve_id` pointing at a **denied/released** reserve | ❌ **not blocked** — `$isReserved` is false, so it checks out as a general loan (links to open audit finding F-08) |

## 6. TITLE ARCHIVE STATUS

`POST /books/{id}/archive` → `BookArchiveService::archive` — solid.

- `DB::transaction` + `lockForUpdate` on the book row
- sets `archived_at`, `archived_by`, `archive_reason`
- refuses if already archived
- **Blockers** (`BookArchiveService:80`): active loan on any copy · open hold on the title (`pending`, `pending_approval`, `fulfilled`) · live reserve (`pending`, `approved`, `active`)
- returns blockers as readable sentences
- does **not** touch copy statuses — copies stay `available` in the row and are blocked at checkout by the title check

Archived titles are excluded by default (`InventoryService:42`, `notArchived()` scope) and returned to librarians on `?include_archived=1`.

*Minor:* the reserve blocker lists `'active'`, which is not a status this codebase ever writes (`approved|denied|released|pending`). Harmless.

## 7. TITLE RESTORE STATUS

`POST /books/{id}/restore` → `BookArchiveService::restore` — clears all three archive fields in a locked transaction; refuses if not archived. Covered by `AR10`. Correct.

## 8. INDIVIDUAL COPY ARCHIVE SUPPORT

### CURRENT SUPPORT: **NO**

`book_copies` has: `copy_id, accession_number, book_id, condition, availability_status, created_at, updated_at, reserve_id`. Nothing can represent an archived copy. The closest existing options (`lost`, `damaged`) mean something else and would be lost on archive.

Smallest change later: see §13 and §14.

## 9. REQUIRED ARCHIVE BLOCKERS (individual copy)

A copy must **not** be archivable while any of these hold:

| # | Check | Query shape |
|---|---|---|
| 1 | active loan | `transactions` where `copy_id = ?` and `status = 'active'` |
| 2 | copy set aside for someone | `holds` where `copy_id = ?` and `status IN ('pending_approval','fulfilled')` |
| 3 | allocated to a **live** reserve | `reserve_id IS NOT NULL` and that reserve's `status IN ('pending','approved')` |
| 4 | circulation state | `availability_status IN ('checked_out','on_hold')` — derivative of 1–2, but catches the manually-set contradictions from §2 |
| 5 | already archived | refuse, as titles do |

**Not a hard blocker, but decide (D-3):** title-level `pending` holds have no `copy_id`. Archiving the last usable copy of a title leaves those borrowers queued for a book that can never return. Recommend: allow, but return a warning — or block if zero usable copies would remain.

**Stranded reserve pointer** (`reserve_id` set, reserve `denied`/`released`): not live, so don't block — clear `reserve_id` as part of the archive.

## 10. COPY COUNT IMPACT

| Count | Where | How | Archived copy under **A** (`archived_at`) | under **B** (`status='archived'`) |
|---|---|---|---|---|
| `total_copies` | `books.total_copies`, stored | only ever **incremented** (`InventoryService:239`), never decremented | included ✔ | included ✔ |
| `available_copies_count` | `InventoryService:19` | live: `status='available' AND reserve_id IS NULL` | **wrongly counted** unless a filter is added | excluded automatically |
| `reserved_copies_count` | `InventoryService:19` | live: `reserve_id IS NOT NULL` | fine if archive clears/blocks `reserve_id` | same |
| section reserve availability | `CourseSectionController:141` | `status === 'available'` | **wrongly counted** | excluded |
| catalog "X of Y" | frontend: `available_copies_count` / `total_copies` | — | Y includes archived ✔ | same |
| "active copies" | — | **does not exist today** | `whereNull('archived_at')` | `status <> 'archived'` |

Your proposed interpretation fits the current model well: **total** = stored counter (includes archived and lost — already true), **active** = new derived count excluding archived, **available** = the existing live subquery plus an archive exclusion.

## 11. RESERVE INTERACTION

- **Auto allocation** (`ReserveController`, no `copy_ids`) filters `whereNull('reserve_id')` + `availability_status='available'` — safe.
- ⚠ **Explicit allocation** (`copy_ids`, `ReserveController:183`) checks book and `reserve_id` only — **no availability check**. A lost, damaged or checked-out copy can be allocated today; an archived copy would be too under Option A.
- Reserve request (`HoldService:166`) picks `reserve_id = ? AND status='available'` — safe under B, needs a filter under A.
- Ready for Pickup and final Admin checkout are unaffected as long as blockers #2 and #3 are enforced.
- **Admin archives a copy with `reserve_id` set:** block when the reserve is live (`pending`/`approved`) with "release or unallocate it first"; when not live, allow and clear the pointer.

Queue flow unchanged by any of this.

## 12. HOLD INTERACTION

| Hold status | Has `copy_id`? | Block copy archive? |
|---|---|---|
| `pending` (queued) | no | **no** — title-level; see D-3 |
| `pending_approval` (copy set aside, awaiting accept) | yes | **yes** |
| `fulfilled` (Ready for Pickup) | yes | **yes** |
| `cancelled`, `expired` | — | no |

Separately, and independent of archive: **`promote()` (`HoldService:252`) assigns a returned copy to the next hold without checking it is usable.** That is the path that hands a damaged copy to a borrower.

## 13. SCHEMA OPTIONS

### Option A — `book_copies.archived_at`, `archived_by`, `archive_reason`

| | |
|---|---|
| Effect on queries | every copy selector needs `whereNull('archived_at')`: `generalAvailabilityCounts`, `placeHold` pick (`HoldService:61`), `requestReserveCopy` pick (`:166`), auto + explicit allocation, `CourseSectionController:141`, checkout (explicit blocker). **~7 sites.** Mitigate with a `BookCopy::scopeActive()` used everywhere |
| Reversibility | clean — restore nulls three columns, touches nothing else |
| Historical clarity | who / when / why, same shape as `books` |
| Status conflicts | **none** — archive is orthogonal; a damaged copy stays damaged while archived |
| Migration | additive, 3 nullable columns + index. No data conversion. Trivial on MySQL and SQLite |

### Option B — `availability_status = 'archived'`

| | |
|---|---|
| Effect on queries | **zero** — every selector already requires `'available'` |
| Reversibility | **lossy** — archiving overwrites `damaged`/`lost`; restore cannot know what to return to without a new column |
| Historical clarity | none — no who/when/why |
| Status conflicts | **high** — archived vs damaged vs lost compete for one field |
| Migration | enum `MODIFY` — fine on MySQL; on SQLite Laravel rebuilds the table for the CHECK constraint. Workable, not free |

### Recommendation: **Option A**

Option B is cheaper to wire and wrong to own. It collapses an administrative lifecycle state into the physical/circulation field — the exact contradiction class this scan is about — and it makes restore unsafe, because a damaged copy archived under B comes back with no record that it was damaged. Option A mirrors the title archive the team already understands, and its only cost (the filter sites) is removed by routing every selection through one scope.

## 14. RECOMMENDED BACKEND DESIGN

### Decisions needed first — do not implement silently

- **D-1 — where does "damaged" live?**
  - **(a) Recommended:** add `damaged` to the `condition` enum, and have the service enforce `condition=damaged ⇔ availability=damaged`. `lost` stays availability-only (a lost book has no inspectable condition). Needs an enum migration, and the live `new/damaged` copy needs an explicit data decision — not an automatic rewrite.
  - **(b) No schema change:** drop "condition = damaged" and treat availability `damaged` as the only source of truth; the UI presents one Damaged control.
- **D-2 — Option A vs B** for copy archive. Recommend **A**.
- **D-3 — archiving the last usable copy** while title-level `pending` holds exist: warn or block. Recommend **block**, consistent with how title archive refuses while borrowers wait.
- **D-4 — restoring a copy whose title is archived:** recommend **refuse** with "restore the title first", so a restored copy is never silently unborrowable.

### Changes, in the order they should land

1. **Close the check-in hole — independent of archive.** In `checkin`, if the copy is `lost`/`damaged`, keep that status and do **not** advance the queue. Guard `promote()` so it only assigns a usable copy.
2. **Harden `updateCopyStatus`:** reject manual `checked_out`/`on_hold` (circulation-owned); refuse `→ lost/damaged` while an active loan or a `pending_approval`/`fulfilled` hold is on the copy — or define an explicit "mark damaged on return" path via check-in; apply the D-1 invariant; wrap in a transaction with `lockForUpdate` (also closes audit F-17).
3. **Explicit reserve allocation:** require `availability_status='available'` and not archived.
4. **Copy archive (Option A):** migration for 3 columns; `BookCopy::scopeActive()`; `CopyArchiveService` with §9 blockers; `POST /copies/{id}/archive` and `/restore` inside the existing librarian route group; route every copy selector through the scope.
5. **Restore semantics:** clear the three archive fields **only** — never touch `availability_status`. A damaged copy restores as damaged, a lost one as lost. The copy becomes borrowable only if it was already `available` and the title is not archived.
6. **Counts:** add the archive exclusion to `available_copies_count` and `CourseSectionController:141`; add an `active_copies_count` next to it; keep `total_copies` as total physical copies.
7. **Archived title detail:** `GET /books/{id}` returns archived titles to anyone (a Student gets **200** today). Return 404 for non-librarians.

**Out of backend scope but the actual UAT cause:** the Admin inventory must request `?include_archived=1` (or a "Show archived" toggle) for the Restore button to ever appear.

## 15. TEST IMPACT

**Existing coverage (all passing today):**

| Area | Tests |
|---|---|
| condition update + authorization | `CD1`, `CD2` (InventoryLifecycleTest) |
| damaged / lost blocked at checkout | `C21`, `C22`, `C23` (CirculationRulesTest) |
| lost/damaged excluded from availability | `AV2` (AvailabilitySemanticsTest) |
| title archive / restore / blockers | `AR1`–`AR13` (InventoryLifecycleTest) |
| reserve allocation / request / queue | CourseReserveRequestTest (20), ReserveAuthorizationTest (27) |
| holds / FCFS / promotion | HistoryHoldsReservesTest (14) |
| checkout blockers | CirculationRulesTest (25), SuperAdminRestrictionTest (15) |

**Nothing tests the gaps in this report.** `C23` only marks a *free* copy damaged. No test marks a loaned or held copy damaged, and no test checks what check-in does to a damaged copy.

**New tests needed:**

- damaged/lost copy **stays** damaged/lost after check-in
- check-in of a damaged copy does **not** promote the next hold
- `promote()` never assigns a non-usable copy
- manual `checked_out` / `on_hold` rejected by `PUT /copies/{id}`
- marking damaged/lost refused (or handled) while loaned or held
- D-1 invariant: `condition=damaged ⇒ availability=damaged`
- explicit allocation of a lost/damaged/archived copy refused
- copy archive: succeeds on a free copy; accession number kept; still in librarian inventory; excluded from availability and from hold/reserve/allocation picks
- copy archive refused for each §9 blocker (loan, `pending_approval`, `fulfilled`, live reserve, already archived)
- copy archive of a stranded reserve pointer clears `reserve_id`
- copy restore preserves `damaged` / `lost`; restore under an archived title refused (D-4)
- archived copy blocked at checkout
- `total` / `active` / `available` counts with archived copies present
- `GET /books/{id}` of an archived title → 404 for Student/Faculty, 200 for librarians
- authorization: only Admin/Super Admin may archive/restore a copy

## 16. IMPLEMENTATION RISKS

1. **Check-in is the highest-traffic write in the module.** Changing it touches every return and both queues. It needs its own tests before anything else lands.
2. **Missed filter sites under Option A.** One selector without the archive exclusion puts an archived copy back into circulation silently. Enforce the scope; grep-test for raw `BookCopy::where('availability_status'`.
3. **Enum migration (D-1a)** on a live table — a `MODIFY`, not additive. Low risk at 6 rows, but not zero, and SQLite rebuilds the table.
4. **Existing contradictory row** (`ABC-LIB-000006`, `new/damaged`). Any new invariant must decide what happens to it — do not rewrite it in the migration.
5. **Manual `checked_out`/`on_hold` rejection** could break a hidden caller. None found in the frontend (`MANUAL_STATUSES` excludes them), and no test sets them manually.
6. **UAT will still report "can't restore"** until the frontend sends `include_archived` — no backend change fixes it.
7. Open audit findings intersect this work: **F-08** (denied reserve strands copies — and, as found here, those copies can still be checked out as general loans), **F-13**, **F-17**. Worth closing in the same pass.

---

## READY TO IMPLEMENT BACKEND CHANGES: **YES**

**Conditional on confirming D-1 through D-4 first** (§14 — recommended defaults given for each). The code paths, blockers, filter sites and restore semantics are fully mapped, and nothing found here requires reworking the queue or the physical-borrowing flow.

*Nothing was implemented. No documentation was updated. Live MySQL was only read.*
