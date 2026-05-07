# Merge Desks Into Sessions

This zip contains the file changes that consolidate the **Interview Desk** and
**Interview Session** concepts into a single entity called **Session**.

After applying these changes:

- The `interview_desks` table is gone.
- Each `interview_slots` row carries everything that used to live on a desk:
  - `assigned_to`    — the interviewer running the session
  - `location_label` — short label (e.g. `Rm 201`, `Desk A`)
  - `location_notes` — optional location notes
- The setup UI becomes a single flat list of sessions per college, with the
  assigned interviewer shown front-and-center on each row.
- The legacy `/staff/interviews/desks` URL keeps working — it now points to
  the same Session setup page.

---

## How to apply (drag & drop)

> **Back up your code and your database first.** A copy of `xampp/htdocs/plp-admissions/`
> and a `mysqldump` of your DB are enough.

### 1. Replace the files

This zip contains only the files that need to change, kept in their normal
folder structure:

```
core/automation.php
database/schema.sql
database/migration_merge_desks_into_sessions.sql
modules/documents/student_upload.php
modules/interview/_setup_colleges.php
modules/interview/_setup_sessions.php          (new)
modules/interview/staff_action.php
modules/interview/staff_call_next.php
modules/interview/staff_manage.php
modules/interview/staff_manual_checkin.php
modules/interview/staff_queue.php
modules/interview/staff_setup.php
modules/interview/staff_slot_view.php
modules/interview/student_view.php
```

Drag the `core/`, `database/`, and `modules/` folders from this zip onto your
project root (e.g. `xampp/htdocs/plp-admissions/`) and let your OS overwrite
the existing files.

### 2. Run the migration SQL

Open phpMyAdmin (or `mysql -u root -p plp_admissions`) and run the entire
contents of:

```
database/migration_merge_desks_into_sessions.sql
```

The migration:

1. Adds `assigned_to`, `location_label`, `location_notes` columns to
   `interview_slots` (no-ops if you re-run it).
2. Backfills those columns from `interview_desks` for existing rows.
3. Drops the old `desk_id` foreign key + column.
4. Drops the now-empty `interview_desks` table.

The script is idempotent — running it twice is safe.

### 3. (Optional) Delete the legacy include files

The new setup page no longer uses these two files. They are harmless dead
code, but if you want a clean tree you can delete them manually:

```
modules/interview/_setup_desks.php
modules/interview/_setup_desk_schedule.php
```

---

## Smoke test after applying

1. Log in as a staff/admin user.
2. **Staff → Interviews → Setup**
   - You should see the list of colleges.
   - Click into one — you should land directly on a flat list of sessions
     for that college (no more Desk → Schedule extra click).
   - Use **+ Add Session** to create a new one. The form asks for date,
     start time, end time, capacity, **assigned interviewer**, location
     label, and notes — all in one shot.
3. **Staff → Interviews → Live Queue**
   - Today's queue should still load. The location strip at the top should
     show the location of today's session for the logged-in interviewer.
4. **Student → Interview**
   - The student's booked session card should still show the interviewer
     name and the location label/notes.

---

## Rolling back

If something goes wrong:

1. Restore your project folder from your backup.
2. Restore your database from your `mysqldump` backup.

The migration drops `interview_desks` and the `desk_id` column, so a SQL
roll-forward isn't possible — that's why the backup matters.

---

## What hasn't changed

- The applicant queue, evaluation, attendance, and notes flows behave
  exactly as before.
- The auto-assignment + auto-reschedule logic in `core/automation.php` and
  `core/interview_scheduler.php` still works against `interview_slots`; it
  just no longer cares about `desk_id`.
- All `/staff/interviews/...` URLs still resolve. `/staff/interviews/desks`
  is kept as an alias of `/staff/interviews/setup` for backward-compat.
