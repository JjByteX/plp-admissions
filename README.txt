plp-admissions — reschedule flow fixes + sidebar / UI cleanup
==============================================================

Drop the files in this archive into your project root. The folder
structure already matches the original repo, so you can extract this
zip on top of plp-admissions/ and every file lands in the right place.

Files in this archive
---------------------

  core/automation.php                       (modified)
  public/index.php                          (modified)
  views/partials/nav_admin.php              (modified)

  modules/api/reschedule_request.php        (modified — interview)
  modules/api/exam_reschedule_request.php   (NEW      — exam)

  modules/interview/student_view.php        (modified)
  modules/interview/staff_absent.php        (modified)

  modules/exam/take.php                     (modified)
  modules/exam/staff_reschedule.php         (NEW)

  modules/results/student_view.php          (modified — UI cleanup)


This round's additional changes
-------------------------------

A. modules/results/student_view.php
   • Removed the "Confirm Your Enrollment" / "I Confirm My Enrollment"
     card on the results page. The matching "You have confirmed your
     enrollment. Welcome to PLP!" alert is removed too so the page no
     longer has any reference to confirmation. The withdraw flow is
     untouched.
   • The /student/result POST endpoint
     (modules/results/enrollment_intent.php) still recognizes
     action=confirm_enrollment, but the UI no longer surfaces it, so
     no normal user can hit it. Leaving the handler in place keeps
     any existing admin / scripting paths working without surprise.

B. views/partials/nav_admin.php — sidebar order
   • "Exam Reschedules" now sits directly under "Exam".
   • "Interview Reschedules" now sits directly under "Interviews".
     Final order:
        Documents → Exam → Exam Reschedules →
        Interviews → Interview Reschedules → Results → Users …

C. modules/exam/take.php — future-exam screen copy
   • Removed the "log in here and click Start Exam" instruction.
     Students just enter the access code the proctor announces, so the
     copy now reads:
        "On exam day, log in here. The proctor in your room will
         announce the access code when the exam opens — you'll have
         5 minutes to enter it to start."


What's still in this zip from the earlier rounds
------------------------------------------------

INTERVIEW reschedule flow:
  • student_view.php: pending/denied/approved banners; form hides
    after submit so no duplicates.
  • staff_absent.php: pre-check for an open slot before doing any
    destructive work; clear "create a slot first" error when nothing
    is open; student notification on approve/deny.
  • api/reschedule_request.php: also notifies SSO + Dean so the
    request reaches everyone who can approve.

EXAM reschedule flow (parity with interview):
  • api/exam_reschedule_request.php: student POST endpoint.
  • exam/take.php: "Need to reschedule?" form on the future-exam
    view, "Request a reschedule" form on the missed-exam view
    (replacing the dead-end "contact admissions office" message).
    Pending/approved/denied banners; form hides while pending.
  • exam/staff_reschedule.php: new admin page at
    /staff/exam/reschedule with the same approve/deny + pre-check +
    "create a slot first" guard. Approve does an atomic slot swap
    (FOR UPDATE on the target slot) so two admins can't double-book.
  • core/automation.php: ensure_exam_reschedule_requests_table().
  • public/index.php: 2 new routes.
  • nav_admin.php: new sidebar entry with a pending dot.

Database
--------
No manual migrations required. Both reschedule_requests and
exam_reschedule_requests are auto-created on first use by the
ensure_* helpers.

php -l reports no syntax errors on any of the changed files.
