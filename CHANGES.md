# Documents module — fixes & cleanups

This drop contains the changed files for three connected issues in the
Documents review module, plus a full removal of the AI / Puter validation
feature you said you didn't want anymore. Just drag the folders over your
project root.

Verified `php -l` clean on every changed file.

---

## 1. "Approve All in Review" button removed

The green "Approve All in Review (N)" button that appeared next to the
**Filter** dropdown on `/staff/applicants` has been removed. Staff now
use the bulk-select checkboxes + the "Approve Docs" toolbar button at
the bottom instead — that operates on an explicit set rather than
blanket-approving everyone who happens to have a pending document.

The unused server-side handler (`approve_all_in_review`), the AJAX
helper (`approveAllListAjax`), and the undo-toast scaffolding
(`showListUndoToast`, `dismissListUndoToast`, `@keyframes
listUndoSlideUp`) were all deleted along with the button — no dead code
left behind.

### Files touched
- `modules/documents/staff_review.php`

---

## 2. "Approve Docs" bulk button — 304 / not-found fixed

### Root cause
The bulk-approve hidden form had no `action` attribute, so it
POSTed to whatever the current URL was — i.e. `/staff/applicants`.
But the router only had a **GET** route for `/staff/applicants`. A POST
to an unmatched route fell through to the 404 handler, which the browser
showed as the 304-looking redirect you saw.

```php
// Before
<form id="bulk-approve-form" method="POST" style="display:none">

// After
<form id="bulk-approve-form" method="POST"
      action="<?= url('/staff/applicants') ?>" style="display:none">
```

And the router now has the matching `POST /staff/applicants` →
`documents/staff_review` route registered in both `public/index.php`
and `api/index.php`. The POST handler for `bulk_approve_selected` was
already inside `staff_review.php` (lines 14–50) — it just couldn't be
reached. Now it can.

### Files touched
- `modules/documents/staff_review.php`
- `public/index.php`
- `api/index.php`

---

## 3. "Not yet eligible to take the entrance exam" — self-heal

You were right that the rejected-then-replaced-then-approved path is
where this comes from. The normal "approve" handler auto-advances
`overall_status` to `exam` when the last unapproved doc is approved,
but if the row gets back into an odd state (e.g. a doc rejection
happened while `overall_status` was already past `submitted`, or the
auto-advance step raced with something else), the applicant could end
up with all docs `approved` and yet `overall_status` still stuck on
`documents` / `submitted`. The strict gate at the exam page then
rejected them with the misleading "not yet eligible" message.

### Fix
Both `modules/exam/take.php` and `modules/documents/student_upload.php`
now run a small **self-heal** check at the top of the request:

> If `overall_status` is one of `submitted` / `documents`, look at the
> *required* documents for this applicant's type. If **every single one
> of them** is `approved`, advance `overall_status` to `exam`, set
> `documents_approved_at`, fire `notify_stage_transition()` and
> `auto_assign_exam_slot()`, and audit-log it as
> `applicant_advanced_exam_selfheal`. Then continue serving the page.

That means:
- When the applicant lands on `/student/documents`, they're advanced
  silently before the page renders, so the stepper and the buttons
  reflect reality.
- If they go directly to `/student/exam`, the same check runs there
  too, so the gate no longer turns them away.
- It only ever advances if the data actually says they should be —
  it's a healing fix, not a bypass.

### Files touched
- `modules/exam/take.php`
- `modules/documents/student_upload.php`

---

## 4. All AI / Puter validation code removed from Documents

You asked to rip everything documents-related that touches AI. Done:

### UI / template
- "AI Validate All" button on the per-applicant document review page
  (`staff_review.php`) — gone.
- The whole "AI Document Validation" modal (collage progress UI, status
  bar, result cards, error states) — gone.
- The Puter SDK loader (`loadDocPuter`) and all
  `openAiValidateAllModal` / `startAiValidateAll` /
  `docPuterSignIn`/`docPuterSignOut` JS — gone.
- The per-document "Auto-validated" / "Validation failed" / "Needs
  review" badges shown in the staff document list — gone.
- The `_aiPendingDocs` JS variable and the `$pendingDocsForAi` PHP
  array that fed the AI modal — gone.
- The "Auto-validated and approved" label that the student-side page
  used to show next to approved docs — replaced with the plain
  "Approved by admissions staff" label.

### Upload pipeline
- `modules/documents/student_upload.php` no longer calls
  `auto_validate_document()` on file upload, no longer re-fetches doc
  rows for auto-approval, and the JSON response for AJAX uploads no
  longer carries `validation` / `auto_approved` fields.

### Helpers (`core/automation.php`)
Removed:
- `auto_validate_document(int $documentId): string`
- `extract_pdf_text(string $pdfPath): ?string`
- `save_ai_validation(int $documentId, string $status, float $confidence, string $reason): void`
- `log_document_validation(int $documentId, string $type, string $status, float $confidence, array $details): void`
- `ensure_document_validations_table(): void`

(The "DOCUMENT AUTO-VALIDATION" section header comment was removed
along with these.)

### Routes
- `POST /api/auto-validate` and `GET /api/auto-validate` removed from
  both `public/index.php` and `api/index.php`.

### Endpoint file
- `modules/api/auto_validate.php` — **delete this file from your
  project**. There's a small `DELETE_auto_validate.php.txt` marker in
  the zip in that folder to remind you. (I didn't include the original
  file in the zip because the goal is to remove it; the marker is
  there only so you don't miss the deletion.)

### CSP comment
- The Puter notes in `views/layouts/app.php` no longer claim Puter is
  used for "AI Validate" in documents. The Puter CSP entries themselves
  are kept because the **exam builder** still uses Puter AI — that's a
  different feature than what you asked to nuke.

### Database
The `document_validations` table is left untouched in the schema. No
migration runs against your DB. If you ever want to drop it, run a
plain `DROP TABLE document_validations;` — nothing reads from it
anymore.

### CSS
- The `.auto-badge`, `.auto-badge-passed`, `.auto-badge-failed`,
  `.auto-badge-uncertain` classes in `public/assets/css/app.css` were
  removed since nothing references them now.

### Files touched
- `modules/documents/staff_review.php`
- `modules/documents/student_upload.php`
- `core/automation.php`
- `public/index.php`
- `api/index.php`
- `views/layouts/app.php`
- `public/assets/css/app.css`
- (`modules/api/auto_validate.php` — delete this file from your repo)

---

## Quick verification

1. Visit `/staff/applicants`. The "Approve All in Review" button next
   to the Filter dropdown should be gone. The header should just be
   Search + Filter on the left, nothing on the right.
2. Tick a couple of applicants with pending docs. The bottom toolbar
   appears. Click **Approve Docs** → should land on `/staff/applicants`
   with a success flash, *not* a 404 / 304.
3. Take an applicant who had a doc rejected, has now had the doc
   re-uploaded and re-approved, and whose `overall_status` is still
   `documents` in the DB. Have them open `/student/exam` (or
   `/student/documents`) — they should now be advanced to `exam`
   silently, get the stage-transition notification, and be allowed in.
4. Open any document review page — there should be no "AI Validate
   All" button, no auto-validated badges, and no Puter modal.
5. Check `php -l` is clean — all changed files were verified.
