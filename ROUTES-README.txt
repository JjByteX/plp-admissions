ROUTES PATCH — fixes Courses & Strands 404
==========================================

WHAT WAS BROKEN
---------------
The sidebar links to /admin/courses but that route was never registered
in public/index.php, so clicking "Courses & Strands" returned 404.

Same problem (latent, would 404 the moment a student tries to submit
enrollment intent or withdraw): /student/result accepted GET but not POST.

WHAT THIS FIXES
---------------
public/index.php — adds these routes:

  GET  /admin/courses               -> settings/admin_courses
  POST /admin/courses               -> settings/admin_courses
  POST /student/result              -> results/enrollment_intent
  POST /staff/results/bulk          -> results/staff_bulk
  POST /staff/results/auto-waitlist -> results/staff_auto_waitlist

The bulk + auto-waitlist routes were already in the codebase as orphan
modules (no UI calls them yet) but they're now available when you wire
up the M6 bulk-action UI.

HOW TO APPLY
------------
Drag the inner `plp-admissions/` folder onto your local one and overwrite
when prompted.  Just one file gets replaced:

  public/index.php

No database changes needed.  No browser refresh shenanigans -- PHP picks
up the new routes immediately on the next request.

VERIFIED
--------
PHP syntax-checked.  Route order is correct -- exact-match `/staff/results/bulk`
and `/staff/results/auto-waitlist` are registered BEFORE the parameterised
`/staff/results/{id}` route, so they don't get swallowed by the wildcard.
