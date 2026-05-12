plp-admissions — SSO export-results + Dean reschedule cleanup
===============================================================

Drop the inner `plp-admissions/` folder onto your project root. Each
file in this archive matches its original path, so extracting on top
of plp-admissions/ lands every file where it belongs. No DB migrations
required.

Files in this archive
---------------------

  modules/results/admin_export.php           (modified)
  views/partials/nav_admin.php               (modified)
  modules/interview/staff_absent.php         (modified)
  modules/interview/staff_cancel_slot.php    (modified)
  modules/exam/staff_reschedule.php          (modified)


What changed and why
--------------------

1. SSO can now export results, just like Admin.
   - /admin/results (the results CSV export + summary view) was
     previously Admin-only. SSO now passes the requireRole check,
     because SSO is the office that actually files admissions
     paperwork with the registrar.
   - A new sidebar entry "Export Results" is shown to SSO and Admin,
     pointing at /admin/results. It uses a dedicated nav key
     (results-export) so it highlights independently of the
     per-college Results page that Admin and Dean already see.
   - Dean is intentionally NOT given access to the bulk export
     because Dean's job is academic oversight of results, not
     bulk export. Dean keeps their existing per-college Results
     view at /staff/results.

2. Dean is removed from reschedule flows entirely.
   Reschedules are a scheduling / registrar action that belongs
   to SSO. Dean has no real-world role in deciding which student
   moves to which slot, so:

   a. /staff/interviews/absent (the page with the Absent Students
      tab and the Reschedule Requests tab):
      - Dean can still see the Absent Students tab read-only
        (this is useful for an academic who wants to know who in
        their college didn't show up).
      - The "Reschedule Requests" tab is hidden from Dean. A
        bookmark to ?tab=requests is silently snapped back to
        the Absent tab so navigation feels seamless instead of
        403-ing.
      - The "Reschedule" action UI on the Absent tab is hidden
        from Dean.
      - canReschedule no longer includes Dean, so even a forged
        POST is rejected with a clear error.

   b. /staff/interviews/cancel-slot (bulk-cancel an interview
      slot and move every booked applicant to a replacement):
      - Dean is dropped from requireRole entirely. Bulk move is
        a scheduling action, not academic oversight.

   c. /staff/exam/reschedule:
      - Dean is dropped from requireRole. Previously Dean could
        view it read-only; now they 403. This matches the
        interview-side behavior (Dean sees no Reschedule
        Requests tab at all).

   d. views/partials/nav_admin.php:
      - The "Interview Reschedules" sidebar entry no longer
        appears for Dean. The pending-count query is still
        cheap so it stays in place for the SSO/Admin badge.

3. Dean's slot creation is unchanged (and was already blocked):
   - modules/interview/staff_setup.php was already SSO+Admin only.
   - modules/exam/staff_slots.php already gates canManage to
     SSO+Admin even though Dean can view the page.
   No new code was needed for this requirement; this archive just
   makes Dean's removal from reschedule flows consistent with that
   existing rule.


Verification
------------

Every modified file is php -l clean on PHP 8.1. No new database
columns or tables. No new routes. The /admin/results route already
exists in public/index.php — it just now accepts SSO sessions in
addition to Admin sessions.


How to roll it out
------------------

1. Extract this archive on top of your plp-admissions/ project root.
2. Reload any in-flight SSO/Dean sessions (or have them re-login)
   so the nav re-renders with the new sidebar items.
3. Smoke-check:
   - Log in as SSO    → sidebar shows "Export Results";
                        /admin/results opens; CSV export downloads;
                        /staff/interviews/absent?tab=requests still
                        works.
   - Log in as Dean   → sidebar shows no "Interview Reschedules"
                        and no "Export Results";
                        /staff/interviews/absent shows only the
                        Absent tab and no Reschedule action UI;
                        /staff/interviews/absent?tab=requests
                        falls back to the Absent tab;
                        /staff/interviews/cancel-slot → 403;
                        /staff/exam/reschedule → 403;
                        /admin/results → 403.
   - Log in as Admin  → no change in capability.
