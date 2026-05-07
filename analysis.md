# PLP Admissions System — Full Analysis

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Complete Workflow (Chronological)](#2-complete-workflow-chronological)
3. [What the System Automates](#3-what-the-system-automates)
4. [Special Situations & Edge Cases](#4-special-situations--edge-cases)
5. [Hard-Hitting Client Questions & Answers](#5-hard-hitting-client-questions--answers)
6. [Unaddressed Problems & Risks](#6-unaddressed-problems--risks)
7. [File-by-File Reference](#7-file-by-file-reference)

---

## 1. System Overview

The PLP Admissions System is a web application for **Pamantasan ng Lungsod ng Pasig** that manages the entire student admissions pipeline — from application through enrollment. It supports three user roles: **Student**, **Staff**, and **Admin**.

**Tech Stack:** PHP (no framework), MySQL/MariaDB, vanilla CSS/JS, hCaptcha (bot protection).

**Architecture:** Single entry point (`public/index.php`) routes all requests through a custom `Router` class. The system uses a module-based layout where each feature lives in `modules/`. Views use `ob_start()` / `ob_get_clean()` output buffering with layout includes.

### Roles & Access

| Role | Access |
|------|--------|
| **Student** | Register, upload documents, take exam, view interview schedule, check-in, view results, withdraw |
| **Staff** | Review documents, manage exams/questions, manage interview slots/queue, release results, suggest courses |
| **Admin** | Everything staff can do + manage users, school year settings, courses/strands, branding, audit logs |

---

## 2. Complete Workflow (Chronological)

### Phase 0: Admin Setup (Before Admissions Open)

1. **Admin sets the Admissions Window** (`admin_school_year.php`)
   - Configures open date, close date, and optional document submission deadline
   - School year is auto-derived from the open date (e.g., open in 2026 → AY 2026-2027)

2. **Admin configures Courses & Tiers** (`admin_courses.php`)
   - Sets tier thresholds per course (High/Average/Low score bands)
   - Sets enrollment caps (max accepted students per course)
   - Can add custom courses beyond the 13 built-in PLP programs
   - Maps courses to SHS strands (which strand graduates can apply to which course)

3. **Admin creates Staff accounts** (`admin_users.php`)
   - Assigns department to each staff member
   - Staff can only manage interviews for their assigned department

4. **Staff builds the Entrance Exam** (`staff_manage.php`)
   - Creates an exam with title, scheduled date/time, and access password
   - Adds sections with different question types (multiple choice, checkboxes, dropdown, short answer, paragraph, linear scale)
   - Sections have titles and descriptions/instructions
   - Supports question/choice shuffling

5. **Staff creates Exam Room Slots** (`staff_slots.php`)
   - Defines exam date, time, room label, department, and capacity
   - Supports batch creation of multiple rooms at once
   - Applicants are auto-assigned to slots after document approval

6. **Staff sets up Interview Infrastructure** (`staff_setup.php`, `staff_desks.php`)
   - Creates interview desks per department
   - Creates interview time slots (date, time, capacity, department)
   - Supports batch creation across date ranges with day-of-week selection

7. **Admin configures branding** (`admin.php`)
   - School name, accent color, logo upload

### Phase 1: Student Registration

1. **Student visits `/register`** — Only accessible during the admissions window
   - If admissions are closed, they see a message with the window dates
   - Fills in: name, birthdate, sex, address (Pasig barangays only), phone, email, password
   - Selects applicant type (Freshman / Transferee / Foreign)
   - Selects course to apply for (filtered by SHS strand for freshmen)
   - Course cap check at registration time — if the course is full, registration is blocked
   - hCaptcha verification required
   - System creates a `users` row + `applicants` row with `overall_status = 'pending'`

### Phase 2: Document Submission

1. **Student uploads required documents** (`student_upload.php`)
   - Document list depends on applicant type:
     - **All applicants:** Government ID, PSA Birth Certificate, Passport Photos, Parent ID, Proof of Income, Guardianship Affidavit
     - **Freshmen additionally:** Form 138 or Form 137
     - **Transferees additionally:** Transcript of Records, Good Moral Certificate
     - **Foreign additionally:** TOR, Good Moral, Passport, Visa/Study Permit, Alien Certificate
   - Files accepted: PDF, JPG, PNG, WEBP (max 5 MB each)
   - Uploads go to local `/uploads/` (development)
   - **Each upload is auto-validated** (file format, size, image integrity, PDF structure/text extraction)
   - Status per document: pending → uploaded → approved/rejected
   - Student can only submit their application (click "Submit") once ALL documents are uploaded

2. **Student submits application**
   - `overall_status` changes from `pending` → `submitted`
   - Student can withdraw their submission (returns to `documents` status)

3. **Document Deadline Enforcement**
   - If admin has set a document deadline and it passes:
     - Students who **haven't submitted** see a polite "Document Submission Closed" page
     - Students who **already submitted** continue to access the site normally
     - POST requests to upload/submit are blocked for non-submitted students

### Phase 3: Staff Document Review

1. **Staff reviews applicants** (`staff_review.php`)
   - Default view shows "Pending" status filter
   - Can view each applicant's uploaded documents
   - Per-document actions: Approve, Reject (with reason), Request Resubmission (with reason)
   - Bulk actions: Approve All Selected, Reject All Selected, Approve All Pending
   - Can undo approval (revert to uploaded) if applicant hasn't taken the exam yet

2. **When all documents are approved:**
   - `overall_status` automatically advances to `exam`
   - `documents_approved_at` timestamp is recorded
   - **System auto-assigns the student to an exam slot** (department-matched, earliest available, FCFS)
   - Student receives an in-app notification

### Phase 4: Entrance Exam

1. **Student sees their exam slot** (`take.php`)
   - If no slot assigned yet: "Awaiting Slot Assignment" message
   - If slot is in the future: countdown card with date, time, room
   - If slot is today: password gate — student enters the exam access password

2. **Student takes the exam**
   - Google Forms-style rendering with anti-cheating measures (text selection disabled)
   - Timer display (from scheduled start to end time)
   - Sections rendered in order, each with instructions
   - On submission:
     - Raw score calculated (auto-graded for objective questions)
     - Score → 1-10 rank via percentage (e.g., 70% = rank 7)
     - Rank compared against course-specific tier thresholds
     - Result: **Passed** (rank ≥ passing threshold) or **Failed**
     - `exam_results` row created with score, rank, pass/fail

3. **If passed:** `overall_status` → `interview`, student is auto-assigned to an interview slot
4. **If failed:** Student stays at `exam` status, sees their score and result
   - Staff can suggest an alternative course the student qualifies for

### Phase 5: Interview

1. **Student is auto-assigned an interview slot** (department-matched, fair distribution algorithm)
   - Picks the slot with the lowest booked count → earliest date → earliest time
   - Student sees their interview date, time, desk assignment on the interview page
   - On interview day: student clicks **"I'm Here"** to check in → status changes to `checked_in`

2. **Staff manages the live queue** (`staff_queue.php`)
   - Sees all students for today's session (checked-in, in-progress, completed, no-show)
   - **Call Next**: pulls the next checked-in student (FIFO by check-in time)
   - **Inline Evaluation**: staff marks Pass/Fail with optional notes
   - **Complete Interview**: marks the interview as done

3. **When interview is completed:**
   - Student is automatically set to **"waitlisted"** in admission_results
   - `overall_status` → `released`
   - Student receives a notification

4. **No-shows:**
   - Staff marks students as no-show
   - No-shows can be rescheduled to a future slot (manual or auto-reschedule)

### Phase 6: Results & Admission Decision

1. **Staff reviews results** (`staff_manage.php`)
   - Table view with filters: All, Pending, Accepted, Waitlisted, Rejected, Withdrawn
   - Two action buttons per row: **Approve** or **Reject**
   - Bulk actions: Accept Selected, Waitlist Selected, Reject Selected
   - **Auto-release**: one-click button that auto-decides based on score thresholds + interview result

2. **Auto-release logic** (when enabled):
   - Rank ≥ course threshold AND interview passed → **Accepted**
   - Rank ≥ (threshold - 1) AND interview passed → **Waitlisted**
   - Otherwise → **Rejected**

3. **Course suggestions** (`staff_suggest.php`)
   - For students who failed their chosen course but qualify for another
   - Staff selects an alternative course → student sees the suggestion on their result page

4. **Student views result** (`student_view.php`)
   - Sees: Accepted, Waitlisted, or Rejected
   - If accepted: can confirm enrollment intent
   - Can withdraw their application at any stage (with optional reason)

### Phase 7: Post-Decision

1. **Waitlist auto-promotion:**
   - When an accepted student withdraws, the system auto-promotes the next waitlisted student in the same course
   - Ranked by exam score (highest first), then documents_approved_at (FCFS)
   - Promoted student gets a notification

2. **Withdrawal:**
   - Students can withdraw at any stage before confirming enrollment
   - Cannot withdraw after enrollment is confirmed
   - Triggers waitlist auto-promotion for the vacated spot

---

## 3. What the System Automates

| Automation | Trigger | What Happens |
|-----------|---------|-------------|
| **Document auto-validation** | File upload | OCR-style checks: format, size, image integrity, PDF structure/text extraction. Auto-approves high-confidence documents. |
| **Exam slot auto-assignment** | All docs approved | Assigns student to earliest available exam slot matching their department. Notifies student. |
| **Exam auto-grading** | Student submits exam | Scores objective questions, calculates rank (1-10), determines pass/fail against course threshold. |
| **Interview slot auto-assignment** | Student passes exam (or new slot created) | Fair-distribution algorithm assigns student to least-filled slot matching their department. |
| **Auto-waitlist after interview** | Interview marked completed | Student is automatically set to "waitlisted" status — staff then upgrades to accepted or downgrades to rejected. |
| **Auto-release results** | Staff clicks "Auto Release" button | Batch-decides all pending results based on exam rank + interview evaluation. |
| **Waitlist auto-promotion** | Accepted student withdraws | Promotes highest-ranked waitlisted student in the same course. Sends notification. |
| **In-app notifications** | Each status transition | Students receive notifications for: docs submitted, docs approved, exam slot assigned, interview scheduled, result released, waitlist promotion, withdrawal. |
| **School year derivation** | Admissions window set | Auto-calculates AY from the open date year. |
| **Course cap enforcement** | Registration + acceptance | Blocks registration when course is full. Tracks accepted count vs max slots. |
| **Document deadline enforcement** | Date passes | Blocks document uploads/submissions for students who haven't submitted. Shows a polite "closed" page. |
| **Audit logging** | Every significant action | Records who did what, when, with entity references. Visible in admin audit log. |

### Automation Settings (Toggleable by Admin)

| Setting Key | Default | Effect |
|------------|---------|--------|
| `auto_validate_documents` | `1` (on) | Enable/disable OCR-style document validation |
| `auto_assign_exam_slots` | `1` (on) | Enable/disable automatic exam slot assignment |
| `auto_promote_waitlist` | `1` (on) | Enable/disable automatic waitlist promotion |
| `auto_release_results` | `0` (off) | Enable/disable automatic result release |

---

## 4. Special Situations & Edge Cases

### 4.1 Course is Full at Registration
- The system checks enrollment caps at registration time
- If the course has reached its max accepted students, registration is blocked with an error
- The courses table shows a red "Full" badge

### 4.2 Student Fails the Exam
- Student stays at `exam` status — they cannot proceed to interview
- Staff can view their score and suggest an alternative course they qualify for
- The student sees the suggestion on their result page and can accept/decline

### 4.3 Document Rejection / Resubmission
- When staff rejects a document, the applicant's status reverts to `documents`
- The student sees the rejection reason and can upload a corrected version
- Staff can also request resubmission (less harsh than rejection) with specific instructions
- Student receives a notification about required corrections

### 4.4 Interview No-Show
- Staff marks the student as a no-show on the queue page
- The system can auto-reschedule no-shows to the next available slot
- Staff can also manually reschedule to a specific slot
- Reschedule history is logged in `reschedule_logs`

### 4.5 Admissions Window Closed
- New registrations are blocked — students see the window dates
- Existing applicants can still log in and continue their process
- This is independent of the document deadline

### 4.6 Document Deadline Passed
- Students who haven't submitted their documents see a "Document Submission Closed" page
- Students who already submitted continue normally (exam, interview, results)
- POST requests to upload/submit documents are blocked server-side

### 4.7 Student Withdraws After Acceptance
- The vacated spot triggers **auto-promotion** of the highest-ranked waitlisted student
- The promoted student receives a notification: "You have been promoted from the waitlist"
- The withdrawal is logged with timestamp and optional reason

### 4.8 Staff Undoes a Document Approval
- Staff can revert an approved document back to "uploaded" status
- Only possible if the applicant hasn't taken the exam yet
- If the applicant was auto-advanced to exam status, they're rolled back to "submitted"

### 4.9 Multiple Custom Courses
- Admin can add custom courses beyond the 13 built-in PLP programs
- Custom courses have configurable strand requirements and can be activated/deactivated
- They appear in registration dropdowns and result suggestions

### 4.10 Exam Slot Doesn't Match Department
- The auto-assign algorithm first tries to match the student's department
- If no department-specific slot is available, it falls back to any available slot
- This prevents students from being stuck waiting indefinitely

### 4.11 Session Timeout
- Student sessions: 30 minutes
- Staff sessions: 2 hours
- A warning appears 5 minutes before expiry
- Keepalive endpoint (`/auth/keepalive`) can extend the session

---

## 5. Hard-Hitting Client Questions & Answers

### "What if someone uploads a fake document?"

**How the system handles it:** Every uploaded document goes through automated validation (OCR-style checks — file format, size, image integrity, PDF structure, text extraction). Documents with high confidence scores are auto-approved; uncertain ones are flagged for manual review. Staff can also use an AI validation fallback (Puter AI) for documents the OCR couldn't confidently assess.

**Gap:** The auto-validation checks file validity, not content authenticity. It can catch blank pages, corrupted files, and wrong formats, but it cannot verify that a birth certificate is real or that grades on a Form 138 are genuine. **This ultimately still requires human judgment.** Consider adding a disclaimer that all documents are subject to verification and false documents will result in application revocation.

---

### "What prevents a student from taking the exam for someone else?"

**How the system handles it:** The exam requires an access password issued by staff, and students must be assigned to a specific slot (date + time + room). The exam page is only accessible when logged in as the assigned student, on the assigned date.

**Gap:** There is no identity verification at exam time (no photo matching, no proctoring). A student could share their login credentials. **Recommendation:** The in-person exam room should have physical ID verification by proctors. The system supports this by showing the student's name and details on the exam interface.

---

### "What happens if the server goes down during an exam?"

**How the system handles it:** The exam form submits all answers at once at the end. If the server goes down mid-exam, answers are lost.

**Gap:** There is no auto-save or draft functionality during the exam. If a student's browser crashes or the server goes down, they lose all progress. **Recommendation:** Add periodic AJAX auto-save (every 60 seconds) that stores partial answers server-side.

---

### "Can staff manipulate results to favor certain students?"

**How the system handles it:** Every action is recorded in the audit log (`audit_logs` table) with the acting user's ID, timestamp, IP address, and description. This creates a complete paper trail. Admin can review the audit log at `/admin/audit-log`.

**Gap:** Audit logs are not immutable — an admin with database access could modify them. For stronger accountability, consider making audit logs append-only at the database level, or exporting them to an external system.

---

### "What if two staff members approve the same document at the same time?"

**How the system handles it:** The `UPDATE documents SET status='approved'` query is idempotent — running it twice has the same effect. The auto-advance to exam stage also has a guard (`WHERE overall_status NOT IN ('exam','interview','result')`) preventing double-advancement.

---

### "How do we handle thousands of applicants at once?"

**Current capacity:** The system uses MySQL pagination (25 items per page), indexed queries, and connection pooling. The exam slot system has configurable capacity per room (default 35) and daily caps (default 3,000).

**Potential bottleneck:** The exam submission page processes all questions in a single POST request with no rate limiting. Under heavy load (1,000+ simultaneous submissions), the database could become a bottleneck. **Recommendation:** Add queue-based processing for exam submissions, or at minimum database connection pooling with `PDO::ATTR_PERSISTENT`.

---

### "Can we run admissions for multiple school years simultaneously?"

**How the system handles it:** Each applicant has a `school_year` field, and the system tracks the "current" school year. However, only one exam can be active at a time (`is_active=1`), and the admissions window is a single global date range.

**Gap:** The system does not support multiple concurrent admissions cycles. Starting a new cycle deactivates the previous exam. If you need to run midyear admissions alongside regular admissions, the current architecture doesn't support it without modifications.

---

### "What if we change the tier thresholds after some students have already been graded?"

**How the system handles it:** Exam results store the absolute score and rank at the time of grading. Changing tier thresholds affects future grading but does NOT retroactively change existing results. The `course_passing_scores` table is separate from `exam_results`.

**This is actually correct behavior** — students should be judged by the standards in place when they took the exam. But staff should be aware that changing thresholds mid-cycle creates inconsistency.

---

### "What data can we export?"

**How the system handles it:** The admin dashboard (`admin/dashboard.php`) supports CSV export with filters (date range, status). Exported fields include: name, email, sex, age, barangay, applicant type, course, status, result, dates. The results page (`admin/results`) also supports filtered exports.

**Gap:** There is no PDF report generation (e.g., admission letters, exam results summaries). Only raw CSV export is available.

---

### "How secure is the system?"

**Security measures in place:**
- CSRF tokens on all forms
- Password hashing with bcrypt (cost 12)
- Role-based access control (Auth guards on every route)
- Input sanitization (`htmlspecialchars` everywhere)
- Prepared statements for all DB queries (SQL injection prevention)
- File type validation on uploads (MIME type check, not just extension)
- Session regeneration on login
- hCaptcha on login and registration
- Audit logging with IP addresses
- SSL required for non-localhost database connections
- ✅ **Login rate limiting** — accounts lock for 15 minutes after 5 failed attempts (`login_attempts` table)
- ✅ **Content Security Policy headers** — CSP, X-Content-Type-Options, X-Frame-Options, Referrer-Policy on all pages

**Remaining gaps:**
- ~~No rate limiting on login attempts (brute force risk)~~ → **FIXED**
- ~~No account lockout after failed attempts~~ → **FIXED**
- No two-factor authentication for admin/staff
- ~~No Content Security Policy headers~~ → **FIXED**
- Session tokens stored in default PHP session storage (not encrypted at rest)

---

### "What if a student applies to the wrong course?"

**How the system handles it:** If a student fails the exam for their chosen course, staff can suggest an alternative course that the student's score qualifies for. The suggestion system checks the rank against the alternative course's threshold before allowing the suggestion.

**Gap:** There is no way for a student to change their course *before* taking the exam. Once registered, the course is locked. If a student realizes they chose wrong, they would need to withdraw and re-register (losing their place in the queue).

---

### "Can parents or guardians access the system?"

**Gap:** There is no parent/guardian portal. Only the student can log in and view their application status. Consider adding a read-only parent view or a shareable status link.

---

## 6. Unaddressed Problems & Risks

### 6.1 ~~No Email Verification~~ → ✅ FIXED
Students now receive a verification email with a link upon registration. Login is blocked until the email is verified. This prevents fake email registrations and ensures password recovery and notifications will reach the student.

> **Implementation:** `modules/auth/verify_email.php`, `core/automation.php` (`generate_verify_token`, `send_verification_email`), `modules/auth/register.php` (sends token instead of auto-login), `modules/auth/login.php` (checks `email_verified` column).

### 6.2 ~~No Duplicate Applicant Detection~~ → ✅ FIXED
Registration now checks for existing applicants with the same **first name + last name + birthdate** combination. If a match is found, the student is prompted to use their existing account or contact the admissions office.

> **Implementation:** `modules/auth/register.php` — added duplicate check query before account creation.

### 6.3 ~~No Exam Auto-Save~~ → ✅ FIXED
Exam answers are now auto-saved every 60 seconds via AJAX. If the browser crashes or internet drops, answers are restored from the `exam_drafts` table when the student returns. A subtle "Draft saved" indicator appears in the bottom-right corner.

> **Implementation:** `modules/api/exam_autosave.php` (AJAX endpoint), `modules/exam/take.php` (auto-save JS + draft restore on load), `core/automation.php` (`ensure_exam_drafts_table`).

### 6.4 ~~No Interview Rescheduling by Students~~ → ✅ FIXED
Students can now submit a reschedule request with a reason from their interview page (collapsible "Need to reschedule?" section). Staff are notified and can approve/deny the request.

> **Implementation:** `modules/api/reschedule_request.php`, `modules/interview/student_view.php` (reschedule form), `core/automation.php` (`ensure_reschedule_requests_table`).

### 6.5 ~~No Applicant Type Change~~ → ✅ FIXED
Students can now change their applicant type (Freshman/Transferee/Foreign) from the documents page **before** submitting. A dropdown selector appears above the document list. Changing the type automatically updates the required documents.

> **Implementation:** `modules/documents/student_upload.php` — added `change_type` POST action and type selector UI.

### 6.6 No Mobile-Responsive Exam Interface
The exam uses CSS grid layouts that may not adapt well to mobile screens. If a student attempts the exam on a phone, the experience may be poor. **Risk: Accessibility issues for students without laptops.**

### 6.7 No Backup/Recovery Process
The schema file (`schema.sql`) is destructive — it drops all tables before recreating them. There's no documented backup/restore process. **Risk: Accidental data loss if schema is re-imported on production.**

### 6.8 ~~Results Notification Only In-App~~ → ✅ FIXED (previously)
Email notifications are now sent via Gmail SMTP (PHPMailer) for all stage transitions: registration welcome, document status updates, exam results, interview scheduling, and admission results.

> **Implementation:** `core/helpers.php` (`send_email`, `email_template`), `core/automation.php` (`notify_stage_transition`, `send_registration_email`), `lib/PHPMailer/`.

### 6.9 ~~No Enrollment Confirmation Flow~~ → ✅ FIXED
Accepted students now see a prominent "I Confirm My Enrollment" button on their results page. Once clicked, the `enrollment_intent` column is set to `confirmed` and a success message is shown. Staff can see confirmed vs. unconfirmed students.

> **Implementation:** `modules/results/student_view.php` (confirmation UI), `modules/results/enrollment_intent.php` (`confirm_enrollment` action).

### 6.10 No Accessibility (a11y) Standards
The UI uses custom CSS components without ARIA labels, keyboard navigation support, or screen reader compatibility. **Risk: Non-compliant with accessibility requirements; excludes students with disabilities.**



### 6.11 No Support for Reapplication
If a student is rejected, there's no mechanism for them to reapply in a future admissions cycle. Their email is permanently tied to a user account. **Risk: Rejected students cannot apply again without admin intervention.**

---

## 6.12 Additional Improvements Applied

- **Auto-uppercase inputs**: All text inputs (names, addresses, etc.) are automatically displayed in uppercase via CSS `text-transform: uppercase`. Server-side, name fields are forced to uppercase with `mb_strtoupper()` on registration. Email and password fields are excluded.
- **Email notifications**: PHPMailer + Gmail SMTP integrated for registration, document status, exam results, interview scheduling, admission results, and password reset.
- **Secrets management**: All credentials (hCaptcha, SMTP) moved from hardcoded values to `.env` file loaded at runtime, with `.gitignore` protection.

---

## 7. File-by-File Reference

### Core Infrastructure

| File | Purpose |
|------|---------|
| `config/app.php` | Application constants: paths, roles, documents, courses, departments, strand mappings, tier thresholds |
| `config/db.php` | Database connection (MySQL/MariaDB) with SSL support for cloud DBs |
| `core/bootstrap.php` | Loads all config, session, auth, router, helpers, automation |
| `core/Auth.php` | Login/logout, role checks, route guards, home URL resolution |
| `core/Session.php` | Session management with flash messages and timeout handling |
| `core/Router.php` | URI routing with path parameters (e.g., `/staff/applicants/{id}`) |
| `core/helpers.php` | URL helpers, CSRF, admissions window checks, score/rank calculations, document type resolution |
| `core/automation.php` | Notifications, document auto-validation, exam slot auto-assignment, waitlist promotion, auto-release results, batch interview creation |
| `core/interview_scheduler.php` | Interview slot assignment algorithm, department resolution, bulk assignment, evaluation recording, rescheduling |

### Authentication

| File | Purpose |
|------|---------|
| `modules/auth/login.php` | Login form with hCaptcha, password toggle |
| `modules/auth/register.php` | Student registration with admissions window check, course cap check, barangay validation |
| `modules/auth/logout.php` | Session destruction and redirect |
| `modules/auth/forgot_password.php` | Password reset request (email-based) |
| `modules/auth/reset_password.php` | Password reset form with token validation |
| `modules/auth/keepalive.php` | AJAX session keepalive endpoint |

### Student Modules

| File | Purpose |
|------|---------|
| `modules/documents/student_upload.php` | Document upload/submission, deadline enforcement, file validation, stepper display, interview booking |
| `modules/exam/take.php` | Exam-taking interface: slot gate, password gate, question rendering, auto-grading, result calculation |
| `modules/interview/student_view.php` | Interview status display, check-in button, slot details, desk info |
| `modules/results/student_view.php` | Result display (accepted/waitlisted/rejected), withdrawal form, course suggestion view |
| `modules/results/enrollment_intent.php` | POST handler for student withdrawal with waitlist auto-promotion |
| `modules/settings/student.php` | Student profile settings (name, password change) |

### Staff Modules

| File | Purpose |
|------|---------|
| `modules/auth/staff/dashboard.php` | Staff dashboard with pipeline summary, quick actions (approve all docs, reschedule absent, send reminders) |
| `modules/documents/staff_review.php` | Applicant list with status filters, document review, bulk approve/reject |
| `modules/documents/staff_action.php` | POST handler: approve, reject, unapprove, advance to exam, request resubmission |
| `modules/exam/staff_manage.php` | Exam builder: create/edit exams, add sections/questions, inline editing, exam sets |
| `modules/exam/staff_slots.php` | Exam room slot management: create/edit/delete slots, batch create, applicant assignment |
| `modules/interview/staff_manage.php` | Interview landing page with setup/queue cards and stats |
| `modules/interview/staff_setup.php` | Interview desk and session setup |
| `modules/interview/staff_queue.php` | Live interview queue: call next, evaluate, complete, mark no-show |
| `modules/interview/staff_action.php` | POST handler: mark completed (auto-waitlists), complete with evaluation, mark no-show, delete/close/open slots |
| `modules/interview/staff_absent.php` | Absent/no-show list with reschedule options |
| `modules/interview/staff_call_next.php` | AJAX: pull next checked-in student from queue |
| `modules/interview/staff_slot_view.php` | View roster for a specific interview slot |
| `modules/results/staff_manage.php` | Results table with filters, approve/reject buttons per row |
| `modules/results/staff_action.php` | POST handler: upsert admission result (accepted/waitlisted/rejected) |
| `modules/results/staff_bulk.php` | Bulk set results for selected applicants |
| `modules/results/staff_auto_release.php` | Auto-release all pending results based on score thresholds |
| `modules/results/staff_suggest.php` | Suggest alternative course to a student |

### Admin Modules

| File | Purpose |
|------|---------|
| `modules/auth/admin/dashboard.php` | Admin dashboard: pipeline stats, date range filters, CSV export, charts |
| `modules/settings/admin.php` | System settings: branding (logo, school name, accent color), admin password |
| `modules/settings/admin_school_year.php` | Admissions window (open/close dates), document deadline, new cycle |
| `modules/settings/admin_courses.php` | Course management: tier thresholds (per-row edit), enrollment caps, custom courses, strand reference |
| `modules/settings/admin_users.php` | User management: create staff/admin accounts, activate/deactivate, assign departments |
| `modules/results/admin_export.php` | Admin results export page |
| `modules/audit/log.php` | Audit log viewer (all system actions with user, timestamp, IP, description) |

### API & Misc

| File | Purpose |
|------|---------|
| `modules/api/notifications.php` | AJAX: get/mark-read in-app notifications |
| `modules/api/auto_validate.php` | AJAX: save AI validation results from client-side Puter AI |
| `public/index.php` | Single entry point — all route definitions |
| `database/schema.sql` | Complete database schema with seed data (destructive — drops all tables) |
| `views/layouts/app.php` | Main layout: sidebar, header, stepper, notification bell, theme toggle |
| `views/layouts/auth.php` | Auth page layout (login/register) |
| `public/assets/css/app.css` | All styles: design tokens, components, sidebar, forms, tables, dark mode |
