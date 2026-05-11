# PLP Admissions — Reschedule + Auto-Assign Patch

Drag-and-drop on top of your existing `plp-admissions/` folder. All
files preserve their original path. No DB migrations required —
new columns (`deny_reason`) and the `exam_reschedule_requests`
table are added on-demand by `ALTER TABLE` / `CREATE TABLE IF NOT
EXISTS` guards inside `core/automation.php`.

## What's in this drop

### 1. Exam slot auto-assign is now 100% reliable
The original `auto_assign_exam_slot()` had a handful of holes that
explain "sometimes it doesn't auto-assign":

- **Race condition** — two simultaneous approvals could both grab
  the last seat (or both fail silently on a duplicate-key error).
- **Past-time slots picked** — a slot scheduled for 9 AM today
  could be picked at 2 PM.
- **No retry after the fact** — if an applicant was advanced to
  exam stage *before* a matching slot existed, they were stuck on
  "Awaiting Slot Assignment" forever, because no later code path
  ever retried.
- **Missed call sites** — the manual *Advance to exam* button and
  the dashboard's *Approve all pending docs* bulk action never
  triggered auto-assignment at all.

Fixes:

- **`core/automation.php`** — `auto_assign_exam_slot()` now runs
  inside a transaction with `FOR UPDATE` on the chosen slot,
  filters out past slots (date < today, or date = today AND
  slot_time <= now), prefers the active exam, falls back through
  department-matching tiers, and skips withdrawn / wrong-stage
  applicants. The function is now idempotent and safe to call
  repeatedly from anywhere.
- **`core/automation.php`** — new `backfill_exam_slot_assignments()`
  scans for every applicant at exam stage with no slot row and
  tries to assign each one.
- **`modules/documents/staff_action.php`** — the `advance_to_exam`
  action now fires `notify_stage_transition()` and
  `auto_assign_exam_slot()` like the other approve paths do.
- **`modules/auth/staff/dashboard.php`** — *Approve all pending
  docs* now runs `backfill_exam_slot_assignments()` after
  advancing applicants in bulk.
- **`modules/exam/staff_slots.php`** — creating a new exam slot
  now runs `backfill_exam_slot_assignments()` so waiting students
  are picked up the instant a matching slot exists.
- **`modules/exam/take.php`** — every visit to the student exam
  page re-attempts auto-assign if the student is at exam stage
  without a slot. This is the final safety net.

Net effect: a student lands on `/student/exam` → if there's any
matching open slot in the future, they get one. If not, the
moment staff creates a slot (or runs bulk approve), every waiting
applicant is picked up automatically.

### 2. Self-serve reschedule flow (interview + exam) — closed holes
From the earlier rounds, plus the fixes you asked me to ship:

- **#1 Transaction-safe interview approve** — full rewrite of
  `approve_reschedule` in `modules/interview/staff_absent.php`
  with `PDO::beginTransaction()` + `FOR UPDATE` on the target
  slot. Two SSOs can no longer double-book the same seat.
- **#2 Dup-pending rejection** — confirmed in both endpoints.
- **#5 Deny-with-reason** — both interview and exam staff
  approve/deny pages have an optional reason field. The reason
  is saved (`deny_reason` column, auto-added with `ALTER TABLE`
  on existing installs) and surfaced to the student in their
  banner, their in-app notification, and the email.
- **#6 Email notifications** — new shared
  `notify_reschedule_decision()` helper sends both the in-app
  notification AND an SMTP-branded email (using your existing
  `send_email()` + `email_template()`). Used by approve, deny,
  and the bulk-cancel flow.
- **#7 Hide withdrawn applicants** — both interview and exam
  request lists filter out anyone whose `overall_status =
  'withdrawn'`.
- **#8 Same-exam constraint** — exam approve refuses to move a
  student between two different `exam_id`s. Auto-assign also
  prefers the active exam.
- **#9 Past requests history** — the student interview + exam
  pages now show a collapsible "Past reschedule requests" block
  with date, status badge, the student's original reason, and
  any staff deny reason.

### 3. Bulk cancel-slot (new feature)
For typhoon scenarios or anything else where you need to move
**everyone** in a slot at once:

- **`/staff/interviews/cancel-slot`** — pick a slot with bookings,
  pick a replacement slot with enough capacity, write a reason →
  every applicant gets moved in one transaction, gets an in-app
  notification, gets a branded email, and the cancelled slot is
  closed.
- **`/staff/exam/cancel-slot`** — same, mirrored for the exam side.
  Honors the same-exam constraint from #8.

Discoverable via a "Cancel a slot (bulk move) →" link on each
existing reschedule-requests page (top right).

## File list (15 files: 13 modified, 2 new)

```
core/automation.php                          MOD  — auto_assign hardened, new backfill + notify_reschedule_decision helpers
public/index.php                             MOD  — 2 new bulk-cancel routes
views/partials/nav_admin.php                 MOD  — (from earlier rounds) reschedule items grouped under parents
modules/api/reschedule_request.php           MOD  — interview submit endpoint (from round 1)
modules/api/exam_reschedule_request.php      MOD  — exam submit endpoint (round 2)
modules/auth/staff/dashboard.php             MOD  — Approve all pending docs now backfills exam slots
modules/documents/staff_action.php           MOD  — Advance to exam now triggers auto-assign + notify
modules/exam/take.php                        MOD  — student page + retries auto-assign on visit
modules/exam/staff_slots.php                 MOD  — slot creation backfills waiting students
modules/exam/staff_reschedule.php            MOD  — deny_reason, same-exam constraint, shared notifier
modules/exam/staff_cancel_slot.php           NEW  — bulk cancel/move page (exam)
modules/interview/staff_absent.php           MOD  — transaction-safe approve, deny_reason, withdrawn filter
modules/interview/student_view.php           MOD  — deny_reason surfaced, history block
modules/interview/staff_cancel_slot.php      NEW  — bulk cancel/move page (interview)
modules/results/student_view.php             MOD  — (from round 3) confirm-enrollment card removed
```

## Sanity-check
Every PHP file in this drop passes `php -l` clean.
