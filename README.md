# PLP Admissions

Pamantasan ng Lungsod ng Pasig — student admissions, document review,
entrance exam, and interview scheduling system.

The interview module uses a single **Session** entity (the legacy
`interview_desks` table has been merged into `interview_slots`); each
session row carries `assigned_to`, `location_label`, and `location_notes`.

---

## Fresh install (new computer)

> **Back up your database first if one already exists** — `database/schema.sql`
> drops every application table before recreating it.

### 1. Drop the project into XAMPP

Place the project at `xampp/htdocs/plp-admissions/` (or your preferred web
root).

### 2. Load the database

Create the `plp_admissions` database, then run **one** file:

```
mysql -u root -p plp_admissions < database/schema.sql
```

…or, in phpMyAdmin: select the database → Import → choose
`database/schema.sql` → Import.

That single file:

- Drops every application table (clean reset).
- Creates the full schema, including the merged `interview_slots`
  (with `assigned_to`, `location_label`, `location_notes`) — no separate
  desk-merge migration needed.
- Seeds default school settings, departments, courses, passing scores, and
  an admin account.

### 3. (Optional) Seed staff accounts

```
mysql -u root -p plp_admissions < database/seed_users.sql
```

### 4. (Optional) Delete the legacy include files

The setup page no longer uses these two files. They are harmless dead code,
but if you want a clean tree you can delete them manually:

```
modules/interview/_setup_desks.php
modules/interview/_setup_desk_schedule.php
```

---

## Smoke test

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

## Notes

- The auto-assignment + auto-reschedule logic in `core/automation.php` and
  `core/interview_scheduler.php` works against `interview_slots`; it does
  not use `desk_id`.
- All `/staff/interviews/...` URLs resolve normally. `/staff/interviews/desks`
  is kept as an alias of `/staff/interviews/setup` for backward-compat.
