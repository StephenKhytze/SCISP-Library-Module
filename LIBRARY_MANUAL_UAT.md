# Library Module — Manual UAT Script

**Repo:** `SCISP-Library-Module` · **Branch:** `lyndon`
**Prepared:** 2026-09-09, after automated pre-UAT validation
**Status:** ⏳ **NOT YET EXECUTED — awaiting a human tester**

> **Manual UAT is still pending and must be performed by a human user.**
>
> Automated pre-UAT validation has been run and passed (127 backend tests + a full API
> role matrix). That does **not** substitute for this document. Every case below is still
> to be executed by a person, including those marked AUTOMATION VERIFIED — automation
> confirms the backend rule, a human confirms the actual on-screen workflow.

---

## Legend

| Marking | Meaning |
|---|---|
| **AUTOMATION VERIFIED** | The underlying backend rule is proven by an automated test. The human step re-confirms it through the UI. |
| **REQUIRES HUMAN UAT** | Not provable by automation — visual correctness, wording, layout, usability, or subjective judgement. Only a person can pass this. |

Fill in **Actual Result**, **PASS/FAIL** and **Notes** as you go. Leave anything you did not run blank rather than guessing.

---

## Environment setup (do this once)

| | |
|---|---|
| **App URL** | `http://localhost:5173/library` |
| **Containers** | `scisp_frontend`, `scisp_backend`, `scisp_db` must all be running (`docker ps`) |
| **Persona switching** | Top-right user menu → pick a persona. The page reloads. |

**Personas available**

| Persona | Username | DB role |
|---|---|---|
| Juan Dela Cruz | `DelaCruz_Juan_C1234` | student |
| Prof. Maria Santos | `Santos_Maria_F12` | faculty |
| Admin User | `Admin_User_00001` | administrator |
| System Admin | `SysAdmin_001` | administrator |

**Dev data at time of writing:** 3 titles / 5 copies / 0 loans / 0 holds / 1 course reserve / 2 sections / 2 enrolments / no outstanding fines.

⚠ **This UAT will change dev data** (loans, holds, fines, reserves). That is expected. Record the counts before you start if you want to compare afterwards. Do **not** run `migrate`, `migrate:fresh`, `db:seed`, or any test command against MySQL.

---

## A. Student — Catalog & Discovery

### UAT-A1 — Catalog loads with real data
**Persona:** Student · **Marking:** AUTOMATION VERIFIED
**Preconditions:** Signed in as Juan Dela Cruz, on the Library page.
**Steps:** 1. Open the **Catalog Search** tab. 2. Read the book cards.
**Expected:** All catalogued titles appear with title, author, ISBN, shelf location, and an "N of M Copies Available" badge showing real numbers.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-A2 — Search finds a book beyond the first page
**Persona:** Student · **Marking:** AUTOMATION VERIFIED
**Preconditions:** More than 12 titles exist. *(If the catalog has ≤12 titles, mark N/A and note it — this case needs more data than the dev DB currently holds.)*
**Steps:** 1. Note a title that is **not** visible on page 1. 2. Type part of its name into the search box. 3. Wait for results.
**Expected:** The title appears. Search is not limited to the current page.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-A3 — Pagination controls
**Persona:** Student · **Marking:** AUTOMATION VERIFIED
**Preconditions:** More than 12 titles exist.
**Steps:** 1. Scroll to the bottom of the catalog. 2. Click **Next**, then **Previous**.
**Expected:** "Page X of Y · N titles" is accurate; buttons disable at the first/last page.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-A4 — Category filter
**Persona:** Student · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. Click a category chip. 2. Click **All**.
**Expected:** Chips list only categories that exist in the catalog. Filtering narrows results correctly; **All** restores them.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-A5 — Copy inventory modal
**Persona:** Student · **Marking:** REQUIRES HUMAN UAT
**Steps:** 1. Click **View Copies** on any title. 2. Inspect each copy row.
**Expected:** Each copy shows an identifier, its condition, and a readable status (Available on Shelf / Currently on Hold / Already Borrowed). Shelf location and availability count are shown. Statuses are understandable without explanation.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

---

## B. Student — Borrowing, Fines, History

### UAT-B1 — Real counters, no placeholders
**Persona:** Student · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. Open **My Borrowing**.
**Expected:** "Your Active Book Loans (N)" matches reality; "Max Allowed: N / 3 Books"; fine balance shows the real amount. **No hardcoded 0s, no ₱150.00, no fake fine cards named FINE-101 / FINE-102.**
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-B2 — Borrowing rule panel
**Persona:** Student · **Marking:** AUTOMATION VERIFIED
**Expected:** Badge reads **STUDENT**; "Max 3 Books (7-Day Loan)"; overdue fine "₱10.00 / Day".
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-B3 — Borrowing history
**Persona:** Student · **Marking:** AUTOMATION VERIFIED
**Preconditions:** Ideally after UAT-D2 so at least one returned loan exists.
**Steps:** 1. Open **My Borrowing**. 2. Scroll to **Your Borrowing History**.
**Expected:** Lists both active and returned loans with borrowed / due / returned dates and a status of On Loan, Overdue Nd, or Returned. Shows only **this** student's records.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-B4 — Place a hold
**Persona:** Student · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. **View Copies** on any title. 2. Click **Borrow / Reserve Book**. 3. Return to **My Borrowing**.
**Expected:** Confirmation; the hold appears under "Your Book Hold Requests" with a status such as "Waiting for Approval" or a queue position.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-B5 — Cancel own hold
**Persona:** Student · **Marking:** AUTOMATION VERIFIED
**Preconditions:** UAT-B4 done.
**Steps:** 1. Click **Cancel Hold** on your hold.
**Expected:** Hold disappears; the copy becomes available again in the catalog.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-B6 — Renew own loan
**Persona:** Student · **Marking:** AUTOMATION VERIFIED
**Preconditions:** The student has an active loan (see UAT-D1) and **no other user is waiting** on that title.
**Steps:** 1. Open **My Borrowing**. 2. Click the loan card. 3. Click **Renew Loan**. 4. Confirm.
**Expected:** Success message with a new due date; the due date on the card moves forward.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-B7 — Overdue is visible before return
**Persona:** Student · **Marking:** AUTOMATION VERIFIED
**Preconditions:** An overdue active loan exists. *(Ask a developer to backdate a due date, or reuse one created during UAT-E1.)*
**Steps:** 1. Open **My Borrowing**. 2. Inspect the loan card, then open it.
**Expected:** A red "Overdue Nd" badge; the detail modal states days overdue and the estimated fine, and says the fine is charged at check-in.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-B8 — Student cannot see admin tools
**Persona:** Student · **Marking:** AUTOMATION VERIFIED
**Expected:** **No** Circulation Desk, Admin Fines & Queue, or Add Title & Copies tabs anywhere.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

---

## C. Faculty

### UAT-C1 — Faculty borrowing rule
**Persona:** Prof. Maria Santos · **Marking:** AUTOMATION VERIFIED
**Expected:** Badge reads **FACULTY**; "Max 10 Books (14-Day Loan)".
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-C2 — Course sections visible
**Persona:** Faculty · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. Open **Course Reserves**.
**Expected:** The teacher's own sections are listed with enrolled students and any reserves. Other teachers' sections are not shown.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-C3 — Create a section and add a student
**Persona:** Faculty · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. **+ Create Section**, name it `UAT Section`. 2. **+ Add Student**, pick a student from the dropdown. 3. **Add**.
**Expected:** Section is created; the student appears in the roster. The dropdown is populated (faculty may read the student directory).
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-C4 — Request a course reserve
**Persona:** Faculty · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. **Request Course Reserve**. 2. Choose your section and a book, set copies, add notes. 3. **Submit Request**.
**Expected:** Success; the reserve appears against that section with status `pending`.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-C5 — Allocated reserve copies show their borrower
**Persona:** Faculty · **Marking:** AUTOMATION VERIFIED
**Preconditions:** UAT-F3 done (reserve approved + allocated) and UAT-F4 done (a student has borrowed a reserved copy).
**Steps:** 1. Open **Course Reserves** and find the reserve.
**Expected:** Under **Allocated Copies**, the borrowed copy shows "Checked Out" with the borrower's username and due date, and a **Renew** button.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-C6 — Faculty cannot see librarian tools
**Persona:** Faculty · **Marking:** AUTOMATION VERIFIED
**Expected:** No Circulation Desk, Admin Fines & Queue, or Add Title & Copies tabs.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

---

## D. Admin — Circulation

### UAT-D1 — Check out a book
**Persona:** Admin User · **Marking:** AUTOMATION VERIFIED
**Preconditions:** Target borrower has no outstanding balance and is under their limit.
**Steps:** 1. **Admin Fines & Queue** tab → **Check-Out Book Copy** form. 2. Enter the borrower's user ID, pick a book, pick a copy. 3. **Check Out**.
**Expected:** Success; the copy becomes `checked_out`; the loan appears in Circulation Desk with the correct due date for that borrower's role.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-D2 — Check in a book
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Preconditions:** UAT-D1 done.
**Steps:** 1. **Circulation Desk**. 2. Find the loan. 3. **Check In**.
**Expected:** Success; the loan leaves the Active list and appears under Returned/All History; the copy returns to Available.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-D3 — Borrowing limit is enforced
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. Check out **3** books to the same student. 2. Attempt a **4th**.
**Expected:** The 4th is refused with a clear message naming the limit (3 of 3). No 4th loan is created.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-D4 — Transaction history scope toggle
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. **Circulation Desk**. 2. Switch between **Active**, **All History**, **Returned**.
**Expected:** Active shows only open loans; Returned shows only completed ones; All History shows both.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-D5 — Circulation search
**Persona:** Admin · **Marking:** REQUIRES HUMAN UAT
**Steps:** 1. Search by borrower name, borrower ID, and title.
**Expected:** Results narrow sensibly for each. Useful in practice, not just technically functional.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

---

## E. Admin — Fines

### UAT-E1 — Overdue return charges a fine
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Preconditions:** An overdue active loan. *(Ask a developer to backdate a `due_date` on a live loan — this is the one setup step automation cannot stage for you in MySQL.)*
**Steps:** 1. **Circulation Desk** → **Check In** the overdue loan. 2. Open **Admin Fines & Queue**.
**Expected:** The borrower now appears under Unpaid Library Fines at ₱10 × whole days overdue, partial days rounded **up**.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-E2 — Record a partial payment
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Preconditions:** A borrower owes a balance (UAT-E1).
**Steps:** 1. **Admin Fines & Queue**. 2. **Record Payment** on that borrower. 3. Enter **less** than the balance. 4. Confirm.
**Expected:** Message states the amount recorded and remaining balance; the row updates to the remainder; **Outstanding** total drops.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-E3 — Waive the remainder
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. **Waive** on the same borrower. 2. Accept the pre-filled remaining balance. 3. Confirm.
**Expected:** Message says "Fine waived"; balance reaches ₱0.00; the borrower disappears from the list. Waive is visibly a **different action** from Record Payment.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-E4 — Over-payment cannot go negative
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. On a borrower owing e.g. ₱30, **Record Payment** of ₱999. 2. Confirm.
**Expected:** Only the outstanding amount is applied; new balance is exactly ₱0.00, never negative.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-E5 — Outstanding balance blocks borrowing
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. With a borrower owing money, attempt to check a book out to them. 2. Settle the balance. 3. Retry.
**Expected:** First attempt refused with a message naming the balance; after settlement the checkout succeeds.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-E6 — Student sees their own balance
**Persona:** Student (with a balance) · **Marking:** AUTOMATION VERIFIED
**Expected:** "Unpaid Library Fines Balance" shows the real amount, matching what the admin sees for that student.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

---

## F. Admin — Holds, Reserves, Inventory

### UAT-F1 — Hold queue: accept then check out
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Preconditions:** A student has placed a hold (UAT-B4).
**Steps:** 1. **Admin Fines & Queue** → Hold Request Queue. 2. **Accept** the hold. 3. **Check Out** to the holder.
**Expected:** Status moves Getting Approval → Ready for Pickup; checkout succeeds; the copy becomes checked out to that student and the hold clears.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-F2 — Release a stuck hold
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. On any hold, click **Cancel / Release**.
**Expected:** The hold is cancelled and the copy returns to circulation — a copy cannot be left permanently unavailable.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-F3 — Approve and allocate a course reserve
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Preconditions:** UAT-C4 done (a pending reserve exists).
**Steps:** 1. **Course Reserves** tab. 2. **Approve Reserve**. 3. **Allocate Physical Copies**.
**Expected:** Status → approved; allocation succeeds and reports how many copies were attached. **It must not fail with a validation error.**
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-F4 — Reserve eligibility follows the borrower ⚠ key security case
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Preconditions:** UAT-F3 done. You need one student **enrolled** in the section and one **not** enrolled.
**Steps:** 1. Check the reserved copy out to the **enrolled** student → expect success. 2. Check the reserved copy out to an **unrelated** student → expect refusal.
**Expected:** The unrelated student is refused **even though an Admin is operating the desk**. The admin's role must not override the borrower's ineligibility.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-F5 — Edit a title
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. **Add Title & Copies** tab → Manage Inventory. 2. **Edit Title** on a book. 3. Change the shelf location. 4. **Save Changes**.
**Expected:** Saved; the new location shows in the catalog.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-F6 — Add copies to an existing title
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. **Manage Copies** on a book. 2. Add 2 copies with condition `new`. 3. **Add**.
**Expected:** Copy count and availability increase accordingly.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-F7 — Copy status consistency ⚠ key data-integrity case
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Preconditions:** At least one copy is currently checked out.
**Steps:** 1. **Manage Copies** on that title. 2. Try to set the checked-out copy's status to **available**.
**Expected:** Not possible — the status control is disabled for checked-out/on-hold copies, with a tooltip explaining that check-in is the route back to the shelf.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-F8 — Register a new title
**Persona:** Admin · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. **Add Title & Copies**. 2. Fill in title, author, ISBN, category, copies, location. 3. Submit.
**Expected:** Title is created with the requested number of copies and appears in the catalog.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

---

## G. Super Admin

### UAT-G1 — Super Admin matches Admin
**Persona:** System Admin · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. Switch to System Admin. 2. Compare the tabs and available actions against Admin User.
**Expected:** Identical Library capabilities. Badge reads **LIBRARIAN**; "No borrowing limit (30-Day Loan)".
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-G2 — Spot-check a Super Admin action
**Persona:** System Admin · **Marking:** AUTOMATION VERIFIED
**Steps:** 1. Perform one settlement or one check-in as System Admin.
**Expected:** Works exactly as it does for Admin.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

---

## H. Cross-cutting & Presentation

### UAT-H1 — Mobile layout parity
**Marking:** REQUIRES HUMAN UAT
**Steps:** 1. Narrow the browser below ~1024px (or use a phone). 2. As Student: check catalog, My Borrowing, borrowing history, holds. 3. As Admin: check the hold queue (Accept / Check Out / Cancel), the fines panel (Record Payment / Waive), and Manage Inventory.
**Expected:** All documented actions are reachable and usable on a phone. Nothing overflows or is cut off.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-H2 — Error handling
**Marking:** REQUIRES HUMAN UAT
**Steps:** 1. Stop the backend container (`docker stop scisp_backend`). 2. Reload the Library page. 3. Restart it and click **Retry**.
**Expected:** A clear error banner with a Retry button — **not** a page that looks like an empty library. Retry recovers.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-H3 — Message clarity
**Marking:** REQUIRES HUMAN UAT
**Steps:** Trigger each refusal: borrowing limit, outstanding fine, reserve ineligibility, checked-out copy status change.
**Expected:** Each message explains **why** in plain language and what to do next. Judge as a librarian would.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-H4 — Visual consistency
**Marking:** REQUIRES HUMAN UAT
**Steps:** Walk every tab as each persona.
**Expected:** Consistent spacing, alignment, readable text, sensible badge colours, no overlap or truncation, no placeholder or lorem text.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-H5 — Confirmation prompts
**Marking:** REQUIRES HUMAN UAT
**Steps:** Trigger renew, remove student from section, and a fine settlement.
**Expected:** Destructive or consequential actions ask for confirmation first; the wording makes the consequence clear.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

### UAT-H6 — Overall workflow usability
**Marking:** REQUIRES HUMAN UAT
**Steps:** Complete one full librarian shift end-to-end: check out, take a hold, accept it, check in an overdue book, settle the fine.
**Expected:** The workflow makes sense without referring to documentation. Note any friction.
**Actual Result:**
**PASS/FAIL:**
**Notes:**

---

## Sign-off

| | |
|---|---|
| Tester name | |
| Date executed | |
| Build / commit | |
| Total PASS | |
| Total FAIL | |
| Blocking defects | |
| Approved for submission? | ☐ Yes ☐ No |

**Known issues to be aware of before testing** (already documented in `LIBRARY_CURRENT_STATE.md` §17):

1. Faculty and librarians can read the full student directory — that is the confirmed policy, not a defect.
2. `books.total_copies` never decrements, so a title with a lost/damaged copy may read e.g. "1 of 2 available". The availability number is correct; the total is inflated.
3. JWT authentication is **not** implemented. Roles come from mock headers by design at this stage.
4. Hold expiry is not automated; a librarian releases stuck holds manually (UAT-F2).

---

**Manual UAT is still pending and must be performed by a human user.**
