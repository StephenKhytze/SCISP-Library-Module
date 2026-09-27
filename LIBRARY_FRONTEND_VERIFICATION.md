# Library Frontend / UI-UX Verification Report

**Date:** 2026-09-12
**Scope:** Frontend verification only. No backend, API, schema, or business-rule changes.
**Repository state:** branch `lyndon`, after the Antigravity UI/UX polish pass (uncommitted).
**Environment:** Docker (`scisp_frontend` :5173, `scisp_backend` :8000, `scisp_db` :3306), live `laravel` MySQL.

> **One frontend edit was made.** See §12.1. Everything else in this document is observation only.

---

## 1. BUILD / LINT

### `npm run build` — **PASS**

```
vite v8.2.1  building client environment for production
✓ 1874 modules transformed
dist/index.html                   0.47 kB │ gzip:   0.30 kB
dist/assets/index-DpR4zfnQ.css   43.46 kB │ gzip:   8.15 kB
dist/assets/index-E4-1bT03.js   450.11 kB │ gzip: 124.22 kB
✓ built in 1.78s
```

0 errors, 0 warnings. Re-run after the §12.1 edit: still **PASS** (450.12 kB JS / 43.49 kB CSS).

### `npm run lint` (oxlint 1.75) — **PASS with 5 warnings, 0 errors**

| File | Line | Rule | Message |
|---|---|---|---|
| `StatusBadge.jsx` | 3:47 | `eslint(no-unused-vars)` | Parameter `type` declared but never used |
| `ConfirmDialog.jsx` | 77:14 | `react(only-export-components)` | Fast-refresh: file exports a non-component (`useConfirm`) |
| `TeacherReservesView.jsx` | 4:47 | `eslint(no-unused-vars)` | Parameter `user` declared but never used |
| `ToastProvider.jsx` | 72:14 | `react(only-export-components)` | Fast-refresh: file exports a non-component (`useToast`) |
| `ToastProvider.jsx` | 15:9 | `react-hooks(exhaustive-deps)` | `useCallback` missing dependency `removeToast` |

All five are benign and were **not fixed** (out of scope — unrelated lint cleanup):

- The two `only-export-components` warnings are the standard context-provider + hook pattern; they affect HMR only.
- The `exhaustive-deps` warning is harmless here: `removeToast` is itself a `useCallback([])`, so the closure is stable.
- `StatusBadge`'s unused `type` prop is a symptom of the dead-component problem in §6/§7.

---

## 2. DESKTOP UI STATUS — **PASS** (with findings)

Verified at **1440 × 900** as Student, Teacher, and Admin.

| Area | Result |
|---|---|
| Sidebar visible on `lg+`, no hamburger | PASS |
| Sidebar collapse / expand toggle | PASS (280px ↔ 100px, icon-only with `title` tooltips) |
| Topbar persona switcher | PASS (switches role, reloads, API headers follow) |
| Stats dashboard (role-aware: 3 cards student, 5 admin) | PASS |
| Catalog grid, search, category chips, pagination | PASS |
| Book detail / copy inventory modal | PASS (Escape does not close — §10) |
| My Borrowing (loans, fines, holds, history) | PASS |
| Course Reserves — admin actions | PASS (Approve / Deny / Allocate / Release render per status) |
| Course Reserves — teacher (`TeacherReservesView`) | PASS (sections, roster, reserves, allocated copies, renew) |
| Circulation Desk | PASS (see §5) |
| Admin Fines & Queue (`HoldQueuePanel` + `AdminFinesPanel`) | PASS |
| Add Title & Copies (`AdminInventoryPanel`) | PASS |

**Desktop findings:** D-1, D-2, D-3, D-4 (see §12).

---

## 3. MOBILE NAVIGATION STATUS

| Check | 1440 | 768 | 430 | 390 | 360 |
|---|---|---|---|---|---|
| Desktop sidebar visible | PASS | n/a (hidden, correct) | n/a | n/a | n/a |
| Desktop sidebar fully hidden | n/a | PASS | PASS | PASS | PASS |
| Hamburger hidden on `lg+` | PASS | n/a | n/a | n/a | n/a |
| Hamburger visible | n/a | PASS | PASS | PASS | PASS |
| Drawer opens | n/a | PASS | PASS | PASS | PASS |
| Closes via X | n/a | PASS | PASS | PASS | PASS |
| Closes via backdrop | n/a | PASS | PASS | PASS | PASS |
| Closes via nav selection | n/a | PASS (navigated to `/schedule`, drawer closed) | PASS | PASS | PASS |
| Closes via Escape | n/a | PASS | PASS | PASS | PASS |
| Body scroll locked while open | n/a | PASS (`body.style.overflow = "hidden"`, restored on close) | PASS | PASS | PASS |
| No horizontal overflow | PASS | PASS (`scrollWidth === clientWidth`) | PASS | PASS | PASS |
| Content uses full mobile width | PASS | **FAIL** (M-1) | PASS | PASS | PASS |
| Header layout correct | PASS | PASS | **FAIL → FIXED** (M-2) | **FAIL → FIXED** | **FAIL → FIXED** |

`MobileNavDrawer.jsx` is well built: all four close paths work, the scroll lock and Escape listener are both registered and torn down in the same `useEffect` cleanup, and the backdrop carries `aria-hidden="true"`.

### M-1 — Tablet range (768–1023px) renders the phone layout capped at 448px

`LibraryPortal`'s mobile block is `max-w-md mx-auto p-4 lg:hidden`. The desktop block is gated on `lg` (1024px). At 768px the user gets a 448px-wide column centred in a 768px viewport, with ~160px of empty gutter on each side.

**Severity:** medium (cosmetic, but the whole tablet range is affected).
**Not fixed** — changing the breakpoint or the max-width is a layout decision, not an obvious regression.

### M-2 — Topbar user name overflowed the header at ≤430px **(FIXED — see §12.1)**

Measured before the fix, at 430 / 390 / 360:

```
headerHeight: 86
nameBox: { top: -1.5, height: 72, width: 73.6 }   // 430px
nameBox: { top: -1.5, height: 72, width: 41.5 }   // 390px and 360px
```

"Juan Dela Cruz" wrapped to three lines in a ~41px column, overflowed the 86px header, and was clipped above the top edge; the avatar was pushed against the right edge.

**Cause:** the polish pass added the hamburger (`w-6` + `p-2` + `space-x-3` ≈ 52px) to the left of the header and changed the padding, but the right-hand name/role column was left with no breakpoint guard, no `truncate`, and no `whitespace-nowrap`.

After the fix: `headerHeight: 86`, avatar at `top: 20, right: 344` on a 360px viewport — fully inside, vertically centred, no clipping, no horizontal overflow.

---

## 4. TOAST / CONFIRM STATUS — **PARTIAL PASS**

### Native dialog scan

Searched the whole `frontend/src` tree for `alert(`, `window.alert(`, `confirm(`, `window.confirm(`, `prompt(`, `window.prompt(`.

| File | Native calls | Status |
|---|---|---|
| `LibraryPortal.jsx` | 1 × `confirm(` — this is the **`useConfirm()` hook**, not `window.confirm` | OK |
| **`TeacherReservesView.jsx`** | **10 × `alert()` + 1 × `window.confirm()`** | **NOT MIGRATED** |
| everything else | none | OK |

**Confirmed at runtime.** With `window.alert` instrumented, clicking **Add** with an empty Student ID produced:

```json
{ "alerts": ["Please enter or select a Student ID."], "confirms": [] }
```

So the entire Teacher/Faculty course-reserve workflow still uses blocking browser dialogs. The 11 sites are:

| Line | Call | Trigger |
|---|---|---|
| 57 | `alert` | Create section failed |
| 62 | `alert` | Add student with empty ID |
| 69 | `alert` | Add student failed |
| 74 | `window.confirm` | Remove student from section |
| 79 | `alert` | Remove student failed |
| 86 | `alert` | Renew succeeded |
| 91 | `alert` | Renew failed |
| 98 | `alert` | Reserve request missing section/book |
| 102 | `alert` | Reserve request succeeded |
| 114 | `alert` | Reserve request failed |

**Severity:** high for UX consistency. This is the single largest gap between the polished Student/Admin surfaces and the Teacher surface.

### Toast system (`ToastProvider.jsx`)

| Check | Result | Evidence |
|---|---|---|
| Success toast | **PASS** | Green `CheckCircle`, "Hold request accepted! The book is now Ready for Pickup." / "Book checked out successfully!" / "Book checked in successfully!" |
| Error toast | **PASS** | Red `XCircle`, "Failed to submit request: You already have an active hold for this book." (real 422 from the API — not simulated) |
| Warning toast | **Wired, not triggered** | 3 call sites (`toast.warning`), all guard-clause validation. Amber `AlertTriangle` + amber palette present in the component. |
| Info toast | **Never called** | `toast.info` exists on the context but has 0 call sites in the app. |
| Close button | **PASS** | X dismisses immediately |
| Auto-dismiss | **PASS** | success/warning/info 3000ms, error 5000ms (errors deliberately persist longer) |
| Stacking | PASS | `flex-col gap-2`, `pointer-events-none` container with `pointer-events-auto` items |
| Reduced motion | **FAIL** | See §10 — no `prefers-reduced-motion` handling anywhere |

### Confirm dialog (`ConfirmDialog.jsx`)

Only one call site in the entire app: `handleRenew` in `LibraryPortal.jsx:314`.

| Check | Result |
|---|---|
| Opens | **PASS** — "Renew Loan / Renew this loan and extend the due date?" |
| Cancel | **PASS** — resolves `false` |
| Confirm | **PASS** — resolves `true`, `POST /library/loans/renew` → 200, success toast with the new due date |
| Backdrop click | **PASS** — closes, resolves `false` |
| Escape | **FAIL** — no `keydown` listener; the dialog stays open |
| Keyboard focus | **FAIL** — focus stays on the button behind the dialog (`document.activeElement` was still "Renew Loan" after the dialog opened). No focus move, no focus trap, no focus restore. |
| Destructive variant | Supported (`isDestructive` → rose button) but **never used** — no caller passes it |

---

## 5. CIRCULATION UI STATUS — **PASS** (desktop) / **PARTIAL** (mobile)

Verified with a real transaction created through the UI (hold → accept → check out → renew → check in).

| Requirement | Desktop | Mobile |
|---|---|---|
| Future due date shows as a **normal** due date, not red | **PASS** — `9/26/2026` in neutral slate | **PASS** — `DUE: 9/26/2026` in neutral slate |
| Actual overdue shows the Overdue state | **PASS by construction** — `item.isOverdue` (server-derived `is_overdue`) drives `text-rose-600` + `OVERDUE:` prefix. **Not reachable with current data** — see §5.1 | same |
| Returned transaction has **no** Check-In action | **PASS** — Actions cell empty for both returned rows | **PASS** |
| Active transaction has Check-In action | **PASS** | **PASS** |
| Filter: Active | **PASS** — 1 row | **ABSENT** |
| Filter: All History | **PASS** — 3 rows, Check-In only on the active one | **ABSENT** |
| Filter: Returned | **PASS** — 2 rows, no Check-In | **ABSENT** |
| Search by borrower / copy / title | **PASS** | **ABSENT** |
| Human-readable dates, no raw ISO | **PASS** — `toLocaleDateString()` throughout | **PASS** |

### 5.1 Overdue state is correct in code but was not reachable

The live database contains no overdue loan, and creating one would require back-dating a `due_date` — a data mutation outside the scope of a frontend verification pass. The overdue branch is driven entirely by the server-computed `is_overdue` / `days_overdue` / `estimated_fine` appends, which the backend test suite already covers.

**This is the one item in §4 of the brief that could not be visually confirmed.** It should be a manual-UAT case with a seeded overdue loan.

### C-1 — Mobile Circulation Desk has no search and no scope filter

The mobile block renders `circulation.map(...)` directly; `filteredCirculation` and the `Active / All History / Returned` toggle exist **only** in the desktop block. A librarian on a phone always sees whatever `circulationScope` happens to be (default `active`) with no way to change it and no search.

### C-2 — Returned rows do not say they are returned

In `All History` and `Returned` scope the table shows only `COPY BARCODE / BOOK TITLE / BORROWER / DUE DATE / ACTIONS`. There is no status column and no return date, so a returned row is distinguishable from an active one **only by the absence of the Check-In button**. `returnedAt` is already mapped into component state (`LibraryPortal.jsx:142`) and is simply never rendered.

### C-3 — "COPY BARCODE" is not a barcode

The column renders `item.copyId` with `CPY-{copyId}` beneath it. `book_copies` has **no `barcode` column** (confirmed against the schema), so both values are derived from the primary key. Cosmetic, but the column header is misleading.

---

## 6. COURSE RESERVE UI STATUS — **BLOCKED / NOT INTEGRATED**

### 6.1 `StudentCourseReserveCard.jsx` is dead code

```
$ grep -rn "StudentCourseReserveCard" frontend/src
frontend/src/modules/library/StudentCourseReserveCard.jsx   (the definition only)
```

**Nothing imports it.** `LibraryPortal.jsx` renders its own inline reserve card in both the mobile block (≈1023–1065) and the desktop block (≈2015–2060). Confirmed visually as Student `DelaCruz_Juan_C1234` at 1440px and 390px: the rendered card has **no Request to Borrow button, no View Classmates button, and no availability counts**.

The same applies to `StatusBadge.jsx`, whose only importer is `StudentCourseReserveCard`. Both files are therefore unreachable at runtime.

### 6.2 State coverage — which states the component supports, and which can be reached

| State | Implemented in the component? | Reachable with real data today? | Blocker |
|---|---|---|---|
| AVAILABLE FOR SECTION | Yes (`available_for_section > 0` → Request to Borrow) | **No** | Field not returned by the API; component not mounted |
| REQUESTING | Yes (local `isRequesting` → spinner + "Requesting…") | **No** | No `onRequestBorrow` handler exists in `LibraryPortal` |
| REQUESTED | Yes (`student_request_status ∈ {pending, requested}`) | **No** | Field does not exist anywhere in the API |
| QUEUED | **No** — no branch for a queue position | **No** | Neither the field nor the UI branch exists |
| READY FOR PICKUP | Yes (`student_request_status === 'ready_for_pickup'`) | **No** | Field does not exist |
| ON LOAN | Yes (`student_request_status === 'on_loan'`) | **No** | Field does not exist |
| ALL COPIES IN USE | Yes (`available_for_section === 0 && allocated_copies > 0`) | **No** | Fields not returned |
| *(fallback)* "No copies allocated" | Yes | n/a | — |

**QUEUED has no implementation at all** — `StudentCourseReserveCard` has no branch for a queue position. Every other listed state is coded but unreachable.

### 6.3 Exact props / data the component needs

`StudentCourseReserveCard` expects a `reserve` object shaped as:

| Prop path | Currently supplied by `LibraryPortal`? | Source |
|---|---|---|
| `reserve.id` | yes | `r.reserve_id` |
| `reserve.course` | yes | `section.name` |
| `reserve.status` | yes | `r.status` |
| `reserve.title` | yes | `r.book.book_title` |
| `reserve.type` | yes | `r.target_group` |
| `reserve.requester` / `teacher_name` | yes | `section.teacher.username` |
| `reserve.note` | yes | `r.teacher_to_student_note` |
| **`reserve.allocated_copies`** | **no** | derivable client-side: `r.copies.length` |
| **`reserve.available_for_section`** | **no** | derivable client-side: `r.copies.filter(c => !c.active_transaction).length` |
| **`reserve.student_request_status`** | **no** | **needs backend** — see §13 |

`GET /api/library/sections/me` already eager-loads `reserves.copies.activeTransaction`, so **`allocated_copies` and `available_for_section` need no backend change at all** — they are pure client-side derivations from data already on the wire.

### R-1 — Reserve status badge is hardcoded green

Both inline cards render `{reserve.status}` inside a fixed `border-emerald-300 bg-emerald-50 text-emerald-700` span. A `denied` or `pending` reserve would render in success green. This is exactly what `StatusBadge` was written to solve.

*(Students currently only receive `status = 'approved'` reserves — `CourseSectionController::mySections` filters on it — so this is latent, not currently visible. It is visible for **Admin**, whose reserve list is unfiltered.)*

### R-2 — Teacher detection uses an exact string match

```jsx
{user?.role === 'Teacher' || user?.role === 'faculty' ? <TeacherReservesView .../> : ...}
```

Two occurrences (mobile ≈999, desktop ≈1994). The rest of the file uses the normalised `isFaculty` (`normalizedRole.includes('teacher') || .includes('faculty')`). A stored role of `"Faculty"` or `"teacher"` would fall through to the student branch and render an empty list, because `fetchData` skips both the admin and the student reserve fetch when `isFaculty` is true.

### R-3 — Empty reserve list renders a blank white panel

Verified as user `guest` (no sections): the Course Reserves tab shows only the header card, then nothing. `reserves.map(...)` over an empty array renders nothing and there is no empty state. Desktop has no count header either.

---

## 7. CLASSMATES UI STATUS — **BLOCKED / NOT INTEGRATED**

`ClassmatesModal.jsx` is **dead code** — its only would-be caller is `StudentCourseReserveCard` (itself dead), and nothing imports it.

Reviewing the component in isolation:

| Check | Result |
|---|---|
| Opens from the correct card | **Cannot verify** — no mount point |
| Section title is clear | PASS by inspection — "Classmates" + `{sectionName}` subtitle |
| Read-only list layout | PASS by inspection — avatar initial, full name, `ID: {username}`; no actions |
| Close button | PASS by inspection (icon button, `onClose`) |
| Escape | **FAIL** — no `keydown` listener |
| Backdrop | **FAIL** — the backdrop is the positioning container; clicking it does nothing |
| Mobile layout | PASS by inspection — bottom sheet under `md`, centred dialog at `md+`, `max-h-[85vh]`, internal scroll |
| Honest empty/unavailable state | **PASS — and it is honest.** `classmates === undefined` → "Classmates Unavailable / The class roster is currently pending synchronization." `classmates === []` → "No other students enrolled in this section." No placeholder names anywhere. |

The `animate-[slideUp_0.3s_ease-out]` / `animate-[fadeIn_...]` classes reference keyframes that **do not exist** in this project (see §10) — the modal will appear instantly.

### Backend data shape needed

There is **no classmates endpoint**. `GET /api/library/sections/me` returns `teacher` and `reserves` but **not** `students` — verified in `CourseSectionController::mySections`. The component expects `{ user_id, first_name, last_name, username }` per classmate. See §13 for the proposed contract.

---

## 8. STATUS BADGES — **INCONSISTENT**

`StatusBadge.jsx` is a good component (uppercase normalisation, `inline-flex`, `rounded-full`, border + tinted background per state) — but it is **unreachable** (§6.1). The app instead uses ad-hoc badge markup in at least seven places with different sizes, casings, and palettes.

| Status | In `StatusBadge` | Colour | Actually used in the running UI? |
|---|---|---|---|
| Available | yes | emerald | via ad-hoc markup (`Available on Shelf`, `X of Y Copies Available`) |
| Course Reserved | yes | blue | **no** — no ad-hoc equivalent |
| On Hold | yes | amber | ad-hoc (`Currently on Hold`) |
| Ready for Pickup | yes | emerald (darker) | ad-hoc (`HoldQueuePanel`: `bg-emerald-600` solid) |
| Checked Out | yes | orange | ad-hoc (`TeacherReservesView`: `bg-amber-100`; circulation: no badge) |
| Overdue | yes | rose | ad-hoc (`text-rose-600` inline text, not a badge) |
| Returned | yes | slate | ad-hoc (`RETURNED` in history) |
| Pending | yes | amber | **no** — reserve badge is hardcoded green (R-1) |
| Approved | yes | emerald | ad-hoc hardcoded green |
| Denied | yes | rose | **no** — would render green (R-1) |
| Damaged | yes | rose | **no** — no UI surfaces it |
| Lost | yes | rose | **no** — no UI surfaces it |
| Active | yes | sky | **no** |
| On Loan | yes | orange | ad-hoc (`ON LOAN` in mobile history) |
| Queued / Requested | yes | amber | **no** |

**Findings:**
- **B-1:** casing is inconsistent — `StatusBadge` forces `uppercase`; the inline reserve badges print the raw lowercase enum (`approved`, `pending`); history badges are uppercase.
- **B-2:** the same logical state uses different colours in different panels (Ready for Pickup: `emerald-100/800` in `StatusBadge` vs. solid `emerald-600` in `HoldQueuePanel`).
- **B-3:** `Damaged`, `Lost`, `Course Reserved`, `Denied`, `Queued` have styling defined but no UI that renders them.
- Badge text is `text-[9px]`–`text-[10px]`. It is legible on the tested devices but sits at the lower bound of readability; all badges fit within mobile width (no clipping observed at 360px).

---

## 9. LOADING / SKELETON STATUS — **PARTIAL**

| Surface | Skeleton? | Location |
|---|---|---|
| Stats dashboard (mobile) | **yes** | `LibraryPortal.jsx:492` |
| Stats dashboard (desktop) | **yes** | `:1358`, `:1432` |
| Catalog grid (mobile) | **yes** | `:741` |
| Catalog grid (desktop) | **yes** | `:1620` |
| Teacher sections | **yes** | `TeacherReservesView.jsx:296` |
| My Borrowing / active loans | **no** | falls straight to the empty state |
| Borrowing history | **no** | — |
| Course Reserves | **no** | blank panel (R-3) |
| Circulation table | **no** | headers with zero rows, no empty state |
| Admin Fines panel | **no** | shows "No outstanding fines." while loading |
| Hold queue | **no** | shows "No hold requests." while loading |
| Inventory panel | **no** | — |

**Findings:**

### L-1 — Panels without skeletons show a *false negative* empty state while loading

The Hold Queue renders "No hold requests. / Holds appear here when a borrower reserves a title." and the Fines panel renders "No outstanding fines." during every fetch, including the refetch after each mutation. This is worse than a blank panel: it asserts something untrue for ~1 second.

### L-2 — Blank white panels confirmed

- Course Reserves with zero reserves → header card, then nothing (observed as `guest`).
- Circulation with zero active loans → table headers, then nothing (observed after check-in).

### L-3 — Loading state shows the wrong borrowing rule

While `summary` is `null`, the "Your Borrowing Rule" card reads **`STUDENT` / `No borrowing limit (7-Day Loan)`** for every role — because `summary?.role` falls through to the student default and `borrowLimit` is `null`. An Admin sees "STUDENT / No borrowing limit" for ~1s, then "LIBRARIAN / No borrowing limit (30-Day Loan)". Observed directly.

### L-4 — No double-click prevention on any mutating action in `LibraryPortal` or `HoldQueuePanel`

```
disabled=  →  LibraryPortal.jsx: 2 (both are pagination buttons)
           →  HoldQueuePanel.jsx: 0
           →  AdminFinesPanel.jsx: 2  ✓
           →  AdminInventoryPanel.jsx: 3  ✓
```

Check Out, Check-In Copy, Accept, Cancel/Release, Approve, Deny, Allocate, Release Reserve, Add Book, and Borrow/Reserve Book have **no in-flight disabled state and no spinner**. Double-clicking fires duplicate requests. `AdminFinesPanel` and `AdminInventoryPanel` do this correctly (`submitting` state) — and so does the unused `StudentCourseReserveCard`.

### L-5 — Layout jump during refetch

After every mutation, `fetchData()` sets `loading = true`, which swaps the stats row back to skeletons while the panel below keeps its old content. The page visibly jumps. Not broken, but it reads as a flicker on every action.

---

## 10. ACCESSIBILITY STATUS — **NEEDS WORK**

| Check | Result |
|---|---|
| `prefers-reduced-motion` handling | **FAIL — none anywhere** |
| Escape closes modals | **FAIL** (book detail, loan detail, confirm dialog, add-book, teacher modals) / **PASS** (nav drawer only) |
| Backdrop closes modals | PASS (confirm dialog, teacher modals) / **FAIL** (`ClassmatesModal`) |
| Focus management in dialogs | **FAIL** — no focus move, no trap, no restore |
| `focus-visible` states | **PARTIAL** — 8 of 40 `focus:outline-none` occurrences have no replacement ring |
| Accessible labels on icon buttons | **PARTIAL** — only 3 `aria-label`s in the whole app |
| `role="dialog"` / `aria-modal` | **FAIL — 0 occurrences** |
| `aria-current` on active nav | **FAIL — 0 occurrences** |
| Contrast | PASS on spot checks (maroon `#80172B` on white, slate-on-white body text) |

### A-1 — The animation utilities used throughout do not exist

`tailwind.config.js` has `plugins: []`, and **`tailwindcss-animate` is not in `package.json`**. Verified against the production bundle:

```
$ grep -c "animate-in\|slide-in-from\|zoom-in-95\|fade-in"  dist/assets/*.css   →  0
$ grep -o "@keyframes [a-zA-Z-]*"  dist/assets/*.css                           →  @keyframes pulse
                                                                                  @keyframes spin
$ grep -rn "@keyframes" src/                                                   →  (none)
```

Every `animate-in`, `fade-in`, `slide-in-from-left`, `slide-in-from-right-8`, `zoom-in-95` class — in `MobileNavDrawer`, `ToastProvider`, `ConfirmDialog`, and both Topbar dropdowns — is a **no-op**. `ClassmatesModal`'s `animate-[slideUp_0.3s_ease-out]` references a keyframe that is never defined. Elements simply appear.

Not a visual defect (nothing is broken), but the intended motion design is absent. Only `animate-pulse` (skeletons) and `animate-spin` (`Loader2`) actually run — and neither is gated on `prefers-reduced-motion`, so a reduced-motion user still gets pulsing skeletons.

### A-2 — `focus:outline-none` without a replacement ring (8 sites)

Including the sidebar collapse toggle, the drawer close button, the mobile hamburger, and the toast close button. These are invisible to keyboard users.

### A-3 — Only three `aria-label`s exist

`"Close navigation menu"`, `"Open mobile menu"`, `"Notifications"` — all in `Topbar`/`MobileNavDrawer`. The icon-only close buttons in `ClassmatesModal`, the book-detail modal, the loan-detail modal, and the teacher modals (`✕` glyph) have no label. No modal carries `role="dialog"` or `aria-modal="true"`; no nav link carries `aria-current="page"`.

### A-4 — The Topbar is not sticky, and the app-shell scroll containment does not work

`Layout.jsx` builds an app shell (`min-h-screen overflow-hidden` → `flex-1 overflow-hidden` → `main overflow-y-auto`) intended to keep the header and sidebar fixed while `main` scrolls. It does not work, because `min-h-screen` lets the shell grow past the viewport, so `main` is never height-constrained. Measured at 1440×900:

```json
{ "winScrollY": 94, "docH": 994, "winH": 900,
  "mainScrollH": 908, "mainClientH": 908, "mainScrolls": false,
  "headerPosition": "relative" }
```

The window scrolls; `main` does not. **Consequence:** the Topbar and Sidebar scroll out of view on every viewport. On mobile this means the hamburger is unreachable until the user scrolls back to the top of a long page. The `overflow-hidden` / `overflow-y-auto` classes in `Layout.jsx` are inert.

---

## 11. BADGE COUNT STATUS

| Tab | Badge source | Counts what | Correct? |
|---|---|---|---|
| **Catalog Search** | `books.length` | books **on the current page** (≤ 12) | **NO** — should be `pagination.total`. With 3 books it happens to match; it will silently cap at 12. |
| **My Borrowing** | `activeLoanCount = summary?.active_loans ?? myLoans.length` | active loans | **YES** — server-sourced. Verified: 0 → 1 → 0 across checkout/check-in. |
| **Course Reserves** | `reserves.length` | reserves in state | **PARTIAL** — correct for Student (2) and Admin (2); **always `0` for Teacher/Faculty** (BC-2). |
| **Circulation Desk** | literal `2` | nothing | **NO — hardcoded** |
| **Admin Fines & Queue** | literal `2` | nothing | **NO — hardcoded** |

### BC-1 — Two admin badges are hardcoded to `2`

- Mobile: `LibraryPortal.jsx:650` and `:668` — `<span ...>2</span>`
- Desktop: `:1524` and `:1538` — `<span>Circulation Desk (2)</span>` / `<span>Admin Fines &amp; Queue (2)</span>`

Observed live as Admin with **1** active loan, **1** hold, and **₱0.00** in fines: both badges still read `2`.

**All the data is already in component state** — no backend work needed:
- Circulation Desk → `circulation.filter(c => c.status !== 'returned').length`
- Admin Fines & Queue → `holdRequests.length + finesData.debtors.length` (or whichever the product wants)

### BC-2 — Course Reserves badge is always 0 for Teacher/Faculty

`fetchData` populates `reserves` only in the `isSuperAdmin` branch and the `else if (!isFaculty)` student branch. Faculty get neither, and `TeacherReservesView` fetches its own `sections` independently. Observed as `Santos_Maria_F12`, who owns section "Water" with one approved reserve: the badge read **0** while the panel below listed the reserve.

### BC-3 — "Checked Out" stat counts held copies too

`books.reduce((n,b) => n + (b.total - b.available), 0)` counts any non-available copy. After placing a hold (status `on_hold`, no transaction), the card read **Checked Out: 1** with zero checkouts. Observed directly.

---

## 12. FRONTEND BUGS FOUND

### Fixed in this pass

| ID | Severity | Finding |
|---|---|---|
| **M-2** | high | Topbar user name overflowed and was clipped by the 86px header at ≤430px. **FIXED** — §12.1 |

### Reported, not fixed

| ID | Severity | Area | Finding |
|---|---|---|---|
| **F-1** | high | Teacher | `TeacherReservesView` still uses 10 × `alert()` + 1 × `window.confirm()` (§4) |
| **F-2** | high | Reserves | `StudentCourseReserveCard`, `ClassmatesModal`, `StatusBadge` are dead code — never imported (§6.1, §7) |
| **F-3** | high | Badges | `Circulation Desk (2)` and `Admin Fines & Queue (2)` are hardcoded (BC-1) |
| **F-4** | medium | Layout | App-shell scroll containment is inert; Topbar/Sidebar scroll away on all viewports (A-4) |
| **F-5** | medium | A11y | No Escape handling on any modal except the nav drawer (§10) |
| **F-6** | medium | A11y | No focus management or focus trap in dialogs (§4) |
| **F-7** | medium | A11y | `tailwindcss-animate` is missing — all `animate-in`/`slide-in`/`zoom-in` classes are no-ops (A-1) |
| **F-8** | medium | Loading | Hold queue and fines panel assert "no results" while loading (L-1) |
| **F-9** | medium | Loading | No double-click prevention on any mutating action in `LibraryPortal`/`HoldQueuePanel` (L-4) |
| **F-10** | medium | Circulation | Mobile Circulation Desk has no search and no scope filter (C-1) |
| **F-11** | medium | Badges | Course Reserves badge always 0 for Teacher/Faculty (BC-2) |
| **F-12** | medium | Catalog | Mobile/tablet catalog has **no pagination controls** — capped at the first 12 titles (controls exist only in the desktop block, `:1732`) |
| **F-13** | medium | Tablet | 768–1023px renders the phone layout capped at 448px (M-1) |
| **F-14** | low | Reserves | Reserve status badge hardcoded green; `denied`/`pending` would render as success (R-1) |
| **F-15** | low | Reserves | Teacher detection uses exact string match, inconsistent with `isFaculty` (R-2) |
| **F-16** | low | Loading | Blank white panels for empty reserves and empty circulation (L-2) |
| **F-17** | low | Loading | Role/limit card shows "STUDENT / No borrowing limit" for every role while loading (L-3) |
| **F-18** | low | Circulation | Returned rows show no status and no return date; `returnedAt` is mapped but never rendered (C-2) |
| **F-19** | low | Badges | Catalog badge counts the current page, not `pagination.total` (§11) |
| **F-20** | low | Stats | "Checked Out" counts held copies as checked out (BC-3) |
| **F-21** | low | Topbar | Name / department / ID render blank for the real signed-in user — only the mock persona objects carry `name`, `department`, `idNumber`. Observed as `guest`. |
| **F-22** | low | Tabs | "Add Title & Copies" never shows an active state — its className is a static string with no `activeTab` check (mobile `:672`, desktop `:1545`) |
| **F-23** | low | Hold queue | `HoldQueuePanel.label()` returns `Queue #${request.queuePosition ?? request.pos}`, but `LibraryPortal` supplies `pos` already formatted as `"#3"` and never sets `queuePosition` → renders **"Queue ##3"**. Only reachable for a waitlisted (`pending`) hold, which needs zero available copies; reported from code, not observed. |
| **F-24** | low | Perf/a11y | Both the mobile and desktop layouts are always mounted (`lg:hidden` / `hidden lg:flex`), so every control exists twice in the DOM and the accessibility tree |
| **F-25** | info | Circulation | "COPY BARCODE" column is derived from the primary key; `book_copies` has no `barcode` column (C-3) |

### 12.1 The one edit made

**File:** `frontend/src/components/Topbar.jsx`, line 120.

```diff
- <div className="text-right flex flex-col justify-center leading-tight">
+ <div className="hidden sm:flex text-right flex-col justify-center leading-tight">
```

Hides the persona name/role text below `sm` (640px). The avatar button, the dropdown, and the dropdown's own name/department/ID header are untouched, so nothing is lost — the name is still one tap away.

**Why this qualifies under the brief's "tiny frontend fixes" rule:**
- It is a regression introduced by the polish pass — `git diff` shows the hamburger and padding were added on the left of the header in this pass while the right-hand column was left unguarded.
- Text clipped outside its container is an unambiguous defect, not a design preference.
- No business logic, no API call, no schema, no change to any Antigravity-authored layout or styling.

Build and lint were re-run after the edit: still 0 errors, same 5 pre-existing warnings.

---

## 13. BACKEND / API DEPENDENCIES

### WORKING NOW (no backend work needed)

| Feature | Component | Endpoint |
|---|---|---|
| Catalog search, filter, pagination | `LibraryPortal` | `GET /library/books`, `GET /library/categories` |
| Book detail + copy inventory | `LibraryPortal` | included in `/library/books` |
| Place hold / borrow request | `LibraryPortal.handleHoldRequest` | `POST /library/books/{id}/holds` |
| Cancel hold | `handleCancelHold` | `DELETE /library/holds/{id}` |
| Accept hold | `HoldQueuePanel` → `handleAcceptHold` | `PUT /library/holds/{id}/accept` |
| Check out / check in | `handleCheckout` / `handleCheckin` | `POST /library/checkout`, `POST /library/checkin` |
| Renew (with confirm dialog) | `handleRenew` | `POST /library/loans/renew` |
| My loans / holds / history / summary | `LibraryPortal` | `/library/loans/me`, `/holds/me`, `/loans/me/history`, `/me/summary` |
| Fines list + settlement | `AdminFinesPanel` | `GET /library/fines`, `POST /library/fines/settle` |
| Inventory management | `AdminInventoryPanel` | `POST /library/books`, `/books/{id}/copies`, `PUT /copies/{id}` |
| Teacher sections + roster + reserve request | `TeacherReservesView` | `/library/sections`, `/sections/{id}/students`, `POST /library/reserves` |
| Reserve approve / deny / allocate / release | `LibraryPortal` | `PUT /library/reserves/{id}/status`, `POST /library/reserves/{id}/allocate` |

### NEEDS FRONTEND WIRING ONLY (data already on the wire)

| Need | Fix |
|---|---|
| `allocated_copies` per reserve | `reserve.copies.length` — `GET /library/sections/me` already eager-loads `reserves.copies` |
| `available_for_section` per reserve | `reserve.copies.filter(c => !c.active_transaction).length` — same payload |
| "ON LOAN" state for the current student | `reserve.copies.some(c => c.active_transaction?.user_id === myUserId)` — same payload |
| Circulation Desk badge | `circulation.filter(c => c.status !== 'returned').length` |
| Admin Fines & Queue badge | `holdRequests.length` and/or `finesData.debtors.length` |
| Catalog badge | `pagination.total` (already in state) |
| Teacher Course Reserves badge | populate `reserves` for faculty, or lift `TeacherReservesView`'s section count |

### NEEDS BACKEND

#### D-1 — Classmates roster for a student

- **Component:** `ClassmatesModal.jsx` (currently dead)
- **Handler:** `onViewClassmates(reserve)` in `StudentCourseReserveCard` — not implemented in `LibraryPortal`
- **Current state:** `GET /library/sections/me` returns `teacher` and `reserves` but **not** `students` (`CourseSectionController::mySections`)
- **Proposed:** `GET /api/library/sections/{sectionId}/classmates`
- **Request:** path param `sectionId`; identity from `X-Mock-Role` / `X-Mock-Username`
- **Authorisation:** 403 unless the caller is enrolled in that section (or is faculty/librarian). Must **not** reuse `GET /library/students`, which SEC-05 restricted to faculty + librarians for exactly this reason.
- **Response:**
  ```json
  [ { "user_id": 6, "username": "DelaCruz_Juan_C1234", "first_name": "Juan", "last_name": "Dela Cruz" } ]
  ```
- **Alternative (cheaper):** add `students.student:user_id,username` to the existing `mySections` eager-load. Note `users` has no `first_name` / `last_name` column — the component must tolerate their absence (it already falls back to `username[0]` for the avatar initial, but renders an empty name line).

#### D-2 — Per-student request state on a course reserve

- **Component:** `StudentCourseReserveCard` — drives REQUESTED, QUEUED, READY FOR PICKUP, ON LOAN
- **Field:** `student_request_status` ∈ `available | requested | queued | ready_for_pickup | on_loan`, plus `queue_position` for QUEUED
- **Current state:** does not exist. `ON LOAN` is derivable client-side (above); `requested` / `queued` / `ready_for_pickup` are hold states and are **not** joined to reserves anywhere.
- **Proposed:** extend `GET /library/sections/me` so each reserve carries the calling student's own state:
  ```json
  { "reserve_id": 2, "student_request_status": "queued", "queue_position": 2,
    "allocated_copies": 1, "available_for_section": 0 }
  ```
- **Derivation:** join `holds` on `book_id = reserve.book_id AND user_id = :me AND status IN ('pending','pending_approval','fulfilled')`; `pending_approval` → `requested`, `pending` → `queued` (+ `queue_position`), `fulfilled` → `ready_for_pickup`; then `transactions` where `copy_id ∈ reserve.copies AND status = 'active' AND user_id = :me` → `on_loan`.

#### D-3 — "Request to Borrow" for a course reserve

- **Component:** `StudentCourseReserveCard` → `onRequestBorrow(reserve.id)`
- **Handler:** does not exist in `LibraryPortal`
- **Nearest existing endpoint:** `POST /library/books/{bookId}/holds`
- **Gap:** `HoldService::placeHold` picks *any* copy with `availability_status = 'available'`. Reserve-allocated copies keep `availability_status = 'available'` and are distinguished only by `reserve_id` (confirmed in `ReserveController::allocateCopies`), so a reserve request can be fulfilled with a **general-circulation copy**, and a general request can consume a **section's reserved copy**.
- **Product decision required** before wiring: should a course-reserve request be scoped to `copies WHERE reserve_id = :reserveId`? If yes, either add `POST /api/library/reserves/{id}/request` or accept an optional `reserve_id` on the existing hold endpoint.
- **Note:** `CirculationService` already enforces borrower-based reserve eligibility at checkout, so the rule exists — it is the *hold allocation* step that is reserve-blind.

#### D-4 — Queue position for a student's own hold

`GET /library/holds/me` already returns `queue_position`, but it is not joined to the reserve view. Covered by D-2.

### NEEDS DATA SHAPE CHANGE (no new endpoint)

| Need | Change |
|---|---|
| Real barcodes in Circulation and Inventory | `book_copies` has no `barcode` column; both UIs already fall back to `CPY-{id}`. Add the column or drop the label. |
| Real names in Topbar and classmates | `users` has only `username`. The Topbar shows blank name/department/ID for the actual signed-in user (F-21). |
| Reserve status vocabulary | UI branches on `pending / approved / active / released / denied`; confirm this is the full enum before `StatusBadge` is wired in. |

### UX IMPROVEMENT ONLY (no backend)

Empty states for reserves and circulation (F-16); skeletons for the loan/history/reserve/circulation/fines/holds panels (L-1); submit-in-flight disabling (F-9); Escape + focus management (F-5, F-6); `aria-label` / `role="dialog"` / `aria-current` (A-3); `focus-visible` rings (A-2); status column + return date in circulation history (F-18); tablet breakpoint (F-13); mobile catalog pagination (F-12); sticky header / working app-shell scroll (F-4).

---

## 14. EXACT FILES / COMPONENTS NEEDING CLAUDE WIRING

Ordered by dependency — the first three unblock everything else.

| # | File | Work | Backend needed? |
|---|---|---|---|
| 1 | `LibraryPortal.jsx` — student reserves block (mobile ≈1023–1065, desktop ≈2015–2060) | Replace the inline reserve card with `<StudentCourseReserveCard>`; add `onViewClassmates` and `onRequestBorrow` handlers; derive `allocated_copies` / `available_for_section` from `reserve.copies` | No, for the mount + derived counts |
| 2 | `LibraryPortal.jsx` — mount `<ClassmatesModal>` | Add `classmatesFor` state, fetch on open, pass `open` / `sectionName` / `classmates` / `onClose` | **Yes — D-1** |
| 3 | `LibraryPortal.jsx` — `handleRequestBorrow` | New handler behind the Request to Borrow button | **Yes — D-3** (product decision first) |
| 4 | `LibraryPortal.jsx:650, 668, 1524, 1538` | Replace the hardcoded `2` badges with real counts | No |
| 5 | `LibraryPortal.jsx:591, 1470` | Catalog badge → `pagination.total` | No |
| 6 | `LibraryPortal.jsx` — mobile circulation block (≈1115–1155) | Add the search input and the Active/All History/Returned toggle; render `filteredCirculation` | No |
| 7 | `LibraryPortal.jsx` — desktop circulation table (≈2105–2160) | Add a Status column and a Returned date; `returnedAt` is already in state | No |
| 8 | `LibraryPortal.jsx` — mobile catalog block | Add pagination controls (mirror `:1732`) | No |
| 9 | `LibraryPortal.jsx:999, 1994` | Replace `user?.role === 'Teacher' \|\| 'faculty'` with the normalised `isFaculty` | No |
| 10 | `LibraryPortal.jsx` — reserve/catalog/borrowing/fines/holds panels | Empty states + skeletons; gate the "no results" copy on `!loading` | No |
| 11 | `LibraryPortal.jsx` + `HoldQueuePanel.jsx` | `submitting` state on every mutating button (copy the `AdminFinesPanel` pattern) | No |
| 12 | `LibraryPortal.jsx` — reserve status badges | Swap the hardcoded green span for `<StatusBadge status={reserve.status} />` | No |
| 13 | `HoldQueuePanel.jsx:27` | Fix `Queue #${pos}` double-hash; pass a numeric `queuePosition` from `LibraryPortal` | No |
| 14 | `TeacherReservesView.jsx` (11 sites) | Replace `alert()` / `window.confirm()` with `useToast()` / `useConfirm()` | No |
| 15 | `ConfirmDialog.jsx` | Escape handler, focus into the dialog, focus trap, focus restore, `role="dialog"` + `aria-modal` | No |
| 16 | `ClassmatesModal.jsx` | Escape handler, backdrop click, `aria-label` on the close button, `role="dialog"` | No |
| 17 | `LibraryPortal.jsx` — book-detail and loan-detail modals | Escape handler + `aria-label` on the `✕` buttons | No |
| 18 | `Layout.jsx` | Make the app shell actually contain scroll (`h-screen` instead of `min-h-screen`), or make the Topbar `sticky top-0` | No |
| 19 | `Topbar.jsx` | Fall back to `username` when the stored user has no `name` / `department` / `idNumber` | No |
| 20 | `tailwind.config.js` + `package.json` | Install/configure `tailwindcss-animate`, or delete the dead `animate-in` classes; add a `prefers-reduced-motion` block | No |
| 21 | `LibraryPortal.jsx:672, 1545` | Give "Add Title & Copies" an active state | No |

---

## 15. READY / NOT READY FOR BACKEND INTEGRATION

### Verdict: **READY for backend integration, on the Course Reserve track only — and only after one product decision.**

**Ready.** The foundation is sound: the build is clean, the lint is clean, the mobile navigation is correct on every tested viewport, the toast and confirm systems work against real API responses, circulation renders due dates and check-in affordances correctly, and every backend dependency is a clearly-bounded addition rather than a rewrite. The dead components (`StudentCourseReserveCard`, `ClassmatesModal`, `StatusBadge`) are well written — they need mounting, not repair.

**The one blocker before D-3 can be built:** should a course-reserve borrow request be restricted to copies allocated to that reserve (`copies WHERE reserve_id = :id`), or may it consume any available copy of the title? `HoldService::placeHold` is currently reserve-blind. This is a product rule, not a technical choice, and it determines whether D-3 is a new endpoint or a parameter on the existing one.

**Recommended sequence:**

1. **Answer the D-3 question.** Everything on the reserve track depends on it.
2. **Frontend-only pass, no backend** — items 1, 4, 5, 6, 7, 8, 9, 12, 13 in §14. These are all derivable from data already on the wire and would close nine findings, including all three hardcoded/misleading badges.
3. **Backend D-1** (classmates) → wire item 2. Small, self-contained, unblocks §7 entirely.
4. **Backend D-2** (`student_request_status`) → makes five of the seven reserve card states reachable.
5. **Backend D-3** (request to borrow) → wire item 3. Last, because it needs the decision from step 1.
6. **Polish, parallel to all of the above** — items 10, 11, 14–21. Independent of the backend.

**Do not start JWT yet.** The identity contract (`X-Mock-Role` / `X-Mock-Username` → request attributes `role` / `user_id`) is working correctly and was exercised across all four personas during this pass. Swapping it while the reserve data shape is still in flux would confuse two unrelated sources of failure.

---

## Appendix A — What was exercised against the live system

To verify the toast, confirm, and circulation behaviour without faking backend success, one full borrow cycle was driven through the UI as real users:

| Step | Persona | Result |
|---|---|---|
| Place hold on "Clean Code" | `DelaCruz_Juan_C1234` (Student) | 201 — success toast |
| Place the same hold again | same | 422 — error toast, "You already have an active hold for this book." |
| Accept the hold | `Admin_User_00001` (Admin) | 200 — success toast, badge → READY FOR PICKUP |
| Check out | Admin | 200 — success toast, active transaction created |
| Open loan details → Renew → Confirm | Student | 200 — success toast with the new due date |
| Check in | Admin | 200 — copy returned to `available` |
| Native `alert` probe (Add Student, empty ID) | `Santos_Maria_F12` (Teacher) | `alert("Please enter or select a Student ID.")` captured |

### Resulting database state

**Restored** — verified by direct query after the pass:

- `book_copies`: all 6 `available` (as found)
- `holds`: 2 rows, both `cancelled` (as found)
- `users.total_fines`: all zero (as found)

**Residue (one row):** `transactions` grew from 2 rows to 3. The extra row is `transaction_id = 3` — user 6, copy 3, status `returned`, borrowed and returned 2026-09-12. It cannot be removed without deleting live data, which was out of scope. It is a normal completed loan and does not affect any business rule.

**No `migrate`, `migrate:fresh`, `db:seed`, or destructive test run was executed at any point.**

## Appendix B — Not verified

| Item | Why |
|---|---|
| Overdue circulation rendering | No overdue loan exists; creating one requires back-dating `due_date` (§5.1) |
| `toast.warning` visual | Wired at 3 sites but not triggered during this pass |
| `toast.info` visual | 0 call sites in the application |
| `ConfirmDialog` destructive variant | Supported but no caller passes `isDestructive` |
| "Queue ##N" double-hash (F-23) | Requires a waitlisted hold, which needs zero available copies |
| QUEUED reserve state | Has no implementation to verify (§6.2) |
| Catalog pagination controls | Only 3 titles in the catalog, so `pagination.last_page > 1` is never true |
