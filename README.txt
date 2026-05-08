PLP Admissions — UI updates
===========================

Drag the contents of `modules/` into your installation's `modules/`
directory, replacing the same-named files.

Files in this update:
  modules/exam/staff_slots.php           — red dot per college; bulk Select + Delete for slots
  modules/interview/_setup_colleges.php  — red dot on college cards with no upcoming sessions
  modules/interview/_setup_sessions.php  — bulk Select + Delete for interview sessions
  modules/interview/staff_setup.php      — backend handler for bulk session delete
  modules/interview/staff_queue.php      — empty queue card now extends full width like the populated table
  modules/settings/admin_courses.php     — Dean can now edit Max Slots for courses in their own college only

Drop-in replacement, no schema changes required.
