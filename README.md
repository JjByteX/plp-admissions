# PLP Admissions — Interview Auto-Assign + Auto-Absent + Wider Reschedule Tables

Drag-and-drop on top of your existing `plp-admissions/` folder.
Every file preserves its original path. No DB migrations required.

This is the combined drop covering the two follow-ups to the original
exam auto-assign zip:

1. Interview-side auto-assign hardening + wider tables (previous round)
2. Auto-absent for no-shows (this round)

## What's new in this round

### Crash fix — Results search box (PDOException HY093)

`/staff/results` was throwing
`SQLSTATE[HY093]: Invalid parameter number` whenever the search box
was used. The WHERE clause reused the same `:q` placeholder three
times (name / email / course), and the project's PDO connection has
`PDO::ATTR_EMULATE_PREPARES => false` (`config/db.php`), so MySQL
native prepared statements need a distinct placeholder per position.
Replaced with `:q1`, `:q2`, `:q3` — same pattern already used in
`modules/documents/staff_review.php`.

### Auto-absent for no-shows

Before: a student who didn't show up only got `interview_status='absent'`
when an interviewer explicitly clicked "Mark Absent" on the queue page.
Auto-routines (`auto_close_expired_sessions`, staff_queue.php inline
update, and `mark_no_show` itself) only set `q.status='no_show'` and
left `interview_status` at `'pending'` — which meant the Absent
Students tab (which filters `WHERE q.interview_status = 'absent'`)
never saw those students. They were stranded.

After: any queue row whose slot has fully ended without an evaluation
is now automatically flipped to the **canonical absent state**:

```
status            = 'no_show'
interview_status  = 'absent'
attendance_status = 'absent'
evaluated_at      = NOW()
```

…with these triggers:

- **Visiting `/staff/interviews/queue`** — already had an inline
  auto-no-show update. Now uses the shared helper so all three fields
  get set, not just `q.status`.
- **Visiting `/staff/interviews/absent`** (new) — runs the same sweep
  on page load, so the Absent Students tab is always up to date the
  moment any SSO / admin / dean opens it.
- **Dashboard → "Auto-close expired interview sessions"** — already
  closed the slots; now also marks every unfinished applicant in
  those slots as absent (the previous code was setting an *invalid
  enum value* `interview_status='no_show'` which silently dropped on
  strict MySQL).
- **Queue → "Mark Absent" button** — manual flip already worked for
  the queue row, but only set `q.status`; now sets all three fields
  so the student also lands in the Absent Students tab without a
  refresh dance.

Every auto-flipped row also fires:

- An in-app notification to the applicant: "Marked absent for your
  interview" → links to `/student/interview` so they can submit a
  reschedule request.
- The existing email template via `send_email()` /
  `email_template()` — same path as the reschedule-decision emails.
- The existing `notify_staff_no_show()` so the admissions desk sees
  it too.
- A full audit log entry (`interview_auto_no_show`).

Auto-reschedule for no-shows still runs after the flip (controlled by
`auto_reschedule_noshows` school setting). The path was retargeted at
`reschedule_absent_applicant()` since the row is now properly absent —
this also fixes a latent bug where the previous `auto_reschedule_noshow`
would leave a stale `interview_status='no_show'` (invalid enum) row
that violated the `uq_applicant_active` unique constraint when trying
to insert the new pending row.

### What was in the previous round (still included here)

- **Interview auto-assign safety net** on `/student/interview` — every
  page visit, if the applicant is at the interview stage with no
  active queue row, retry `assign_interview_slot()`. Mirrors the
  `modules/exam/take.php` pattern. So the "student sees Waiting →
  admin creates session → student refreshes → booked" loop works
  end-to-end with zero staff action.
- **`backfill_interview_slot_assignments()`** helper in
  `core/automation.php`, symmetric with the existing exam-side
  backfill. Walks every applicant at the interview stage without a
  slot and tries to assign each one, regardless of department
  matches.
- **`$pageWide = true;`** on `/staff/exam/reschedule`,
  `/staff/interviews/absent`, `/staff/exam/cancel-slot`, and
  `/staff/interviews/cancel-slot` so the tables fill the window
  instead of being squeezed into the narrow 900px container.

## File list (10 modified)

```
core/automation.php                          MOD  — auto_close_expired_sessions delegates to auto_detect_interview_no_shows;
                                                     auto_reschedule_noshow rewired to reschedule_absent_applicant;
                                                     backfill_interview_slot_assignments() helper.
core/interview_scheduler.php                 MOD  — new auto_detect_interview_no_shows() + _notify_applicant_marked_absent().
modules/results/staff_manage.php             MOD  — search box no longer crashes (HY093): :q split into :q1/:q2/:q3.
modules/interview/staff_queue.php            MOD  — inline auto-no-show update replaced with shared helper (all 3 fields).
modules/interview/staff_action.php           MOD  — mark_no_show sets full canonical absent state, not just q.status.
modules/interview/staff_absent.php           MOD  — auto-detect on page load + $pageWide = true.
modules/interview/staff_cancel_slot.php      MOD  — $pageWide = true so the table fills the window.
modules/interview/student_view.php           MOD  — page-load auto-assign safety net (mirrors modules/exam/take.php).
modules/exam/staff_reschedule.php            MOD  — $pageWide = true so the table fills the window.
modules/exam/staff_cancel_slot.php           MOD  — $pageWide = true so the table fills the window.
```

Every file passes `php -l` clean. No new migrations or schema changes.

## Quick smoke test

1. **Auto-absent:**
   - Find an interview slot whose `end_time` has passed today.
   - Confirm at least one queue row for that slot is still
     `status='scheduled'`.
   - Open `/staff/interviews/absent` as SSO / Admin.
   - The student should now appear in the Absent Students table
     immediately, even though no one clicked Mark Absent.
   - The student should also have an in-app + email notification
     about being marked absent.

2. **Auto-assign retry (interview side):**
   - Make a student pass the exam in a department with no interview
     session — they should land on `/student/interview` with the
     Waiting card.
   - Create an interview session for that department.
   - The student refreshes `/student/interview` — slot appears
     immediately, no staff action needed.

3. **Wider tables:**
   - `/staff/exam/reschedule` and
     `/staff/interviews/absent?tab=requests` should stretch full
     width on a wide monitor instead of squeezing the table into the
     middle.
