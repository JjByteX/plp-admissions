# Exam Slots — edit (and add) Close Time not saving

## Cause

The Edit Slot modal had a "Closes" field (`name="end_time"`) but the
server's `edit_slot` handler never read it — the `UPDATE
exam_slot_schedule` query only included `exam_date`, `slot_time`,
`room_label`, `department`, and `capacity`. So any change you made to
the close time silently disappeared on save. From your view the form
posted fine and you got redirected, but the value reverted.

The exact same bug existed on `add_slot` — new slots ignored the
"Closes" field and got the DB's default `09:30:00` regardless of what
you typed.

(Curiously, the **batch** create handler — same page — already handled
`end_time` correctly. The single-slot Add/Edit handlers just missed it.)

## What's changed in `modules/exam/staff_slots.php`

### `add_slot` handler
- Reads `end_time` from the POST.
- Defaults to `slot_time + 90 minutes` if the form left it blank
  (matches batch behaviour).
- Rejects with "Close time must be after the start time." if it's
  earlier than or equal to `slot_time`.
- INSERT now writes `end_time` into the row alongside `slot_time`.

### `edit_slot` handler
- Same three changes as above.
- UPDATE now writes `end_time` into the row alongside `slot_time`.
- Audit log entries now record the full window (`HH:MM-HH:MM`) instead
  of just the open time.

## Files

- `modules/exam/staff_slots.php`

Lint clean (`php -l`). Drop it over the matching path.

## Verify

1. Open an existing slot's Edit modal. Change both "Opens" and
   "Closes". Hit "Save Changes". Page reloads with the success flash
   and both values now stick.
2. Try setting Closes earlier than or equal to Opens — you should see
   "Close time must be after the start time." instead of a silent
   no-op.
3. Add a brand-new slot via the "+ Add Slot" card. Set Closes to
   something distinct from Opens. After save, both values are visible
   on the slot card and on the roster page header.
4. Batch-create slots still works the same (unchanged).
