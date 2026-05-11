# PLP Admissions System

Pamantasan ng Lungsod ng Pasig — a web-based student admissions system that handles the full pipeline: registration, document submission, entrance exam, interview scheduling, and results release.

**Tech Stack:** PHP (no framework), MySQL/MariaDB, vanilla CSS/JS, XAMPP

---

## Table of Contents

1. [Requirements](#requirements)
2. [Installation & Setup](#installation--setup)
3. [Environment Configuration](#environment-configuration)
4. [User Roles & Default Accounts](#user-roles--default-accounts)
5. [Admission Process (Step by Step)](#admission-process-step-by-step)
6. [What Each Role Does](#what-each-role-does)
7. [Automation Features](#automation-features)
8. [Frequently Asked Questions](#frequently-asked-questions)

---

## Requirements

- **XAMPP** (or any local server with PHP 8.0+ and MySQL/MariaDB)
- **phpMyAdmin** (included with XAMPP) or any MySQL client
- A web browser (Chrome, Firefox, Edge, etc.)

---

## Installation & Setup

### Step 1: Download and Place the Project

Copy the entire `plp-admissions` folder into your XAMPP web root:

```
C:\xampp\htdocs\plp-admissions\
```

Your folder structure should look like this:

```
htdocs/
└── plp-admissions/
    ├── config/
    ├── core/
    ├── database/
    ├── modules/
    ├── public/
    ├── views/
    ├── .env
    └── ...
```

### Step 2: Create the Database

1. Open **phpMyAdmin** (go to `http://localhost/phpmyadmin` in your browser)
2. Click **"New"** on the left sidebar
3. Enter the database name: `plp_admissions`
4. Set the collation to `utf8mb4_general_ci`
5. Click **"Create"**

### Step 3: Import the Database Schema

This creates all the tables the system needs.

**Option A — Using phpMyAdmin:**
1. Select the `plp_admissions` database
2. Click the **"Import"** tab
3. Click **"Choose File"** and select `database/schema.sql`
4. Click **"Import"** at the bottom

**Option B — Using the command line:**
```
mysql -u root -p plp_admissions < database/schema.sql
```

> **Warning:** `schema.sql` drops all existing tables before recreating them. Back up your data first if you already have records.

### Step 4: Seed the Default User Accounts

This creates the admin, SSO, dean, professor, and proctor accounts so you can log in immediately.

**Option A — Using phpMyAdmin:**
1. Select the `plp_admissions` database
2. Click the **"Import"** tab
3. Click **"Choose File"** and select `database/seed_users.sql`
4. Click **"Import"** at the bottom

**Option B — Using the command line:**
```
mysql -u root -p plp_admissions < database/seed_users.sql
```

### Step 5: Configure Environment Variables (Optional)

1. Copy `.env.example` to `.env` in the project root
2. Fill in your values:

```env
# hCaptcha (get keys from https://dashboard.hcaptcha.com)
HCAPTCHA_SITE_KEY=your_site_key_here
HCAPTCHA_SECRET_KEY=your_secret_key_here

# Email notifications (Gmail SMTP — use an App Password)
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your_email@gmail.com
SMTP_PASS=your_app_password
SMTP_FROM_NAME=PLP Admissions
```

> **Note:** The system works without these. hCaptcha will be disabled on the registration page, and email notifications won't be sent. Everything else functions normally.

### Step 6: Open the System

Go to: **`http://localhost/plp-admissions/public/`**

You should see the login page. Use any of the default accounts listed below to log in.

---

## User Roles & Default Accounts

The system has **6 roles**. Each role has different permissions and sees a different set of pages.

### Admin

| | |
|---|---|
| **Email** | `admin@plp.edu.ph` |
| **Password** | `Admin@123` |
| **What they do** | Full access to everything. Manages users, school year settings, courses, branding, and audit logs. Can do everything that SSO and other roles can do. |

### SSO (Student Success Office)

| | |
|---|---|
| **Email** | `sso@plp.edu.ph` |
| **Password** | `SSO@123` |
| **What they do** | The main operations role. Reviews student documents, builds the entrance exam, creates exam room slots and interview sessions, and releases admission results. |

### Dean (One per College)

Each dean can only see applicants and data for their own college/department.

| College | Email | Password |
|---------|-------|----------|
| College of Computer Studies | `dean.ccs@plp.edu.ph` | `Dean@123` |
| College of Nursing | `dean.con@plp.edu.ph` | `Dean@123` |
| College of Business and Accountancy | `dean.cba@plp.edu.ph` | `Dean@123` |
| College of Education | `dean.coe@plp.edu.ph` | `Dean@123` |
| College of Arts and Sciences | `dean.cas@plp.edu.ph` | `Dean@123` |
| College of Engineering | `dean.cen@plp.edu.ph` | `Dean@123` |

**What they do:** Read-only oversight of their college. Can view the dashboard, manage courses and tier thresholds for their department, view interviews, and review results.

### Professor (One per College)

Each professor can only operate within their own college/department.

| College | Email | Password |
|---------|-------|----------|
| College of Computer Studies | `staff.ccs@plp.edu.ph` | `Staff@123` |
| College of Nursing | `staff.con@plp.edu.ph` | `Staff@123` |
| College of Business and Accountancy | `staff.cba@plp.edu.ph` | `Staff@123` |
| College of Education | `staff.coe@plp.edu.ph` | `Staff@123` |
| College of Arts and Sciences | `staff.cas@plp.edu.ph` | `Staff@123` |
| College of Engineering | `staff.cen@plp.edu.ph` | `Staff@123` |

**What they do:** Conduct student interviews. They see the interview queue, call the next student, evaluate them (pass/fail), and complete the interview process.

### Proctor (One per College)

Each proctor can only operate within their own college/department.

| College | Email | Password |
|---------|-------|----------|
| College of Computer Studies | `proctor.ccs@plp.edu.ph` | `Proctor@123` |
| College of Nursing | `proctor.con@plp.edu.ph` | `Proctor@123` |
| College of Business and Accountancy | `proctor.cba@plp.edu.ph` | `Proctor@123` |
| College of Education | `proctor.coe@plp.edu.ph` | `Proctor@123` |
| College of Arts and Sciences | `proctor.cas@plp.edu.ph` | `Proctor@123` |
| College of Engineering | `proctor.cen@plp.edu.ph` | `Proctor@123` |

**What they do:** Manage exam-day room operations. They view exam room slots, generate and extend access codes for students, and oversee the exam room. They can only generate codes for rooms in their own department.

### Student

| | |
|---|---|
| **How to create** | Register at the login page during the admissions window |
| **What they do** | Register, upload documents, take the entrance exam, check in for interviews, view results, and confirm enrollment. |

> **Important:** Change all default passwords after first login. These are for initial setup only.

---

## Admission Process (Step by Step)

The admission process has 7 phases. Here is exactly what happens at each stage and who is involved.

### Phase 0: System Setup (Admin & SSO — Before Admissions Open)

Before any students can register, the admin and SSO need to set up the system.

**Admin does:**
1. **Set the Admissions Window** — Go to **School Year** in the sidebar. Set the open date, close date, and optional document submission deadline. The school year is automatically calculated from the open date.
2. **Configure Courses & Tiers** — Go to **Courses & Strands**. Set passing score thresholds (High/Average/Low tiers) for each course. Set enrollment caps (maximum accepted students per course). Map which SHS strands can apply to which courses.
3. **Create Staff Accounts** — Go to **Users**. Create accounts for SSO, Deans, Professors, and Proctors. Assign each person to their department/college.
4. **Configure Branding** (optional) — Go to **Settings**. Set the school name, accent color, and upload a logo.

**SSO does:**
5. **Build the Entrance Exam** — Go to **Exam** in the sidebar. Create an exam with a title. Add sections with different question types: multiple choice, checkboxes, dropdown, short answer, paragraph, and linear scale. You can enable answer/question shuffling per section.
6. **Create Exam Room Slots** — Go to **Exam Slots** (via the Exam page). Create time slots with: date, time, room label, department, and capacity. You can batch-create multiple rooms at once.
7. **Set Up Interview Sessions** — Go to **Interviews → Setup**. Create interview sessions with: date, time window, capacity, assigned interviewer, and location.

---

### Phase 1: Student Registration

1. Student visits the system and clicks **"Register"**
2. Fills in personal information: name, birthdate, sex, address, phone, email, and password
3. Selects their applicant type: **Freshman**, **Transferee**, or **Foreign**
4. Selects the course they want to apply for (freshmen see courses filtered by their SHS strand)
5. Completes hCaptcha verification (if configured)
6. System creates their account — their status is now **"Pending"**

> **Note:** Registration is only available during the admissions window. If the window is closed, students see a message with the dates.

---

### Phase 2: Document Submission (Student)

1. Student logs in and sees their **Documents** page
2. Uploads required documents (PDF, JPG, PNG, or WEBP — max 5 MB each):
   - **All applicants:** Government ID, PSA Birth Certificate, Passport Photos, Parent ID, Proof of Income, Guardianship Affidavit
   - **Freshmen also:** Form 138 or Form 137
   - **Transferees also:** Transcript of Records, Good Moral Certificate
   - **Foreign also:** TOR, Good Moral, Passport, Visa/Study Permit, Alien Certificate
3. Each upload is automatically validated (file format, size, integrity check)
4. Once all documents are uploaded, the student clicks **"Submit Application"**
5. Status changes to **"Submitted"**

---

### Phase 3: Document Review (SSO / Admin)

1. SSO or Admin goes to **Documents** in the sidebar
2. Reviews each applicant's uploaded documents
3. For each document, they can: **Approve**, **Reject** (with reason), or **Request Resubmission** (with instructions)
4. Bulk actions are available: Approve All Selected, Reject All Selected, Approve All Pending
5. **When all documents are approved:**
   - Status automatically advances to **"Exam"**
   - The system auto-assigns the student to an exam room slot (matched by department, earliest available, first-come-first-served)
   - Student receives a notification

> **If a document is rejected:** The student's status goes back to "Documents" and they can upload a corrected version.

---

### Phase 4: Entrance Exam

**Before exam day — SSO/Admin:**
- Ensure exam room slots are created with correct dates, times, and capacity

**On exam day — Proctor:**
1. Proctor logs in and goes to **Exam Slots**
2. Finds their assigned room and clicks **"Generate Code"** to create an 8-character access code
3. Announces the code to the room — codes are valid for **5 minutes**
4. Can click **"Extend +5m"** to extend the code if students need more time to enter it
5. Can regenerate a fresh code at any time

**On exam day — Student:**
1. Student logs in and sees their exam assignment (date, time, room)
2. When the exam time arrives, enters the access code the proctor announced
3. Takes the exam (Google Forms-style interface with anti-cheating measures)
4. On submission:
   - System auto-grades objective questions
   - Calculates a score and ranks it on a 1–10 scale
   - Compares rank against the course's passing threshold
   - Result: **Passed** or **Failed**

**After the exam:**
- **If passed:** Status advances to **"Interview"** — student is auto-assigned to an interview slot
- **If failed:** Student stays at "Exam" status and sees their score. SSO/Admin can suggest an alternative course the student may qualify for

---

### Phase 5: Interview

**Before interview day — SSO/Admin:**
- Ensure interview sessions are created and assigned to professors

**On interview day — Student:**
1. Student sees their interview date, time, and location on the **Interview** page
2. On the day of the interview, clicks **"I'm Here"** to check in
3. Waits to be called

**On interview day — Professor:**
1. Professor logs in and goes to **Interview Queue**
2. Sees all students for today's session: checked-in, in-progress, completed, no-show
3. Clicks **"Call Next"** to pull the next checked-in student (first-come, first-served by check-in time)
4. Conducts the interview
5. Marks the student as **Pass** or **Fail** with optional notes
6. Clicks **"Complete"** to finish the interview
7. Student is automatically set to **"Waitlisted"** status

**No-shows:**
- Professor marks absent students as no-show
- No-shows can be rescheduled to a future slot (automatically or manually)

---

### Phase 6: Results & Admission Decision (SSO / Dean / Admin)

1. Go to **Results** in the sidebar
2. See all applicants with filters: All, Pending, Accepted, Waitlisted, Rejected, Withdrawn
3. For each applicant, choose: **Accept** or **Reject**
4. Bulk actions: Accept Selected, Waitlist Selected, Reject Selected
5. **Auto-Release** button: one-click batch decision based on exam scores + interview results:
   - Exam rank ≥ course threshold AND interview passed → **Accepted**
   - Exam rank ≥ (threshold − 1) AND interview passed → **Waitlisted**
   - Otherwise → **Rejected**
6. Can suggest alternative courses for students who failed their chosen course but qualify for another

**Student sees their result:**
- **Accepted** — can confirm enrollment intent
- **Waitlisted** — waiting for a spot to open up
- **Rejected** — may see a suggested alternative course
- Students can withdraw at any stage before confirming enrollment

---

### Phase 7: Post-Decision

**Waitlist Auto-Promotion:**
- When an accepted student withdraws, the system automatically promotes the next waitlisted student in the same course
- Priority: highest exam score first, then earliest document approval date
- The promoted student receives a notification

**Withdrawal:**
- Students can withdraw at any stage before confirming enrollment
- Cannot withdraw after enrollment is confirmed
- Triggers waitlist auto-promotion for the vacated spot

---

## What Each Role Does

### Admin
| Page | What They Can Do |
|------|-----------------|
| Dashboard | View admission pipeline stats, document status breakdown, idle applicant alerts |
| School Year | Set admissions window dates and document deadline |
| Courses & Strands | Configure courses, tier thresholds, enrollment caps, SHS strand mappings |
| Documents | Review and approve/reject student documents |
| Exam | Build the entrance exam (questions, sections, shuffling) |
| Exam Slots | Create and manage exam room slots |
| Interviews | Set up interview sessions, manage the live queue |
| Results | Review, accept/reject applicants, auto-release results |
| Users | Create and manage all staff/proctor/dean/SSO accounts |
| Audit Log | View a log of all actions taken in the system |
| Settings | Change school name, accent color, logo, and admin password |

### SSO (Student Success Office)
| Page | What They Can Do |
|------|-----------------|
| Dashboard | View admission pipeline stats for all departments |
| School Year | Set admissions window dates and document deadline |
| Courses & Strands | Configure courses, tier thresholds, enrollment caps |
| Documents | Review and approve/reject student documents |
| Exam | Build the entrance exam |
| Exam Slots | Create and manage exam room slots for all departments |
| Interviews | Set up interview sessions |
| Results | Review, accept/reject applicants, auto-release results |
| Settings | Change personal password |

### Dean
| Page | What They Can Do |
|------|-----------------|
| Dashboard | View admission pipeline stats for their college only |
| Courses & Strands | Configure courses and tier thresholds for their college |
| Interviews | View interview queue (read-only) |
| Results | View results for their college, suggest alternative courses |
| Settings | Change personal password |

### Professor
| Page | What They Can Do |
|------|-----------------|
| Dashboard | View admission stats for their department |
| Interview Queue | Call next student, evaluate (pass/fail), complete interviews, mark no-shows |
| Settings | Change personal password |

### Proctor
| Page | What They Can Do |
|------|-----------------|
| Dashboard | View admission stats for their department |
| Exam Slots | View exam room slots, generate/extend access codes for their department's rooms |
| Settings | Change personal password |

### Student
| Page | What They Can Do |
|------|-----------------|
| Documents | Upload required documents, submit application |
| Exam | View exam assignment, enter access code, take the exam |
| Interview | View interview assignment, check in on interview day |
| Results | View admission result, confirm enrollment, withdraw application |
| Settings | Change personal password |

---

## Automation Features

The system automates several parts of the admissions process:

| Feature | What Triggers It | What Happens |
|---------|-----------------|--------------|
| Document auto-validation | Student uploads a file | System checks file format, size, and integrity automatically |
| Exam slot auto-assignment | All documents approved | Student is assigned to the earliest available exam room in their department |
| Exam auto-grading | Student submits exam | Objective questions are scored, rank calculated, pass/fail determined |
| Interview slot auto-assignment | Student passes exam | Student is assigned to the least-filled interview slot in their department |
| Auto-waitlist after interview | Interview completed | Student is automatically set to "waitlisted" status |
| Auto-release results | SSO/Admin clicks "Auto Release" | All pending results are batch-decided based on scores + interview |
| Waitlist auto-promotion | Accepted student withdraws | Next highest-ranked waitlisted student in the same course is promoted |
| Notifications | Every status change | Students get in-app notifications at each step |
| Course cap enforcement | Registration + acceptance | Blocks registration/acceptance when a course is full |
| Document deadline enforcement | Deadline date passes | Blocks uploads for students who haven't submitted yet |
| Audit logging | Every significant action | Records who did what, when, for accountability |

**Toggleable Settings (Admin can turn these on/off):**

| Setting | Default | What It Controls |
|---------|---------|-----------------|
| Auto-validate documents | On | Automatic file validation on upload |
| Auto-assign exam slots | On | Automatic exam room assignment after doc approval |
| Auto-promote waitlist | On | Automatic promotion when an accepted student withdraws |
| Auto-release results | Off | Automatic result release (usually done manually) |

---

## Frequently Asked Questions

**Q: I can't log in with the default accounts.**
A: Make sure you ran `seed_users.sql` after `schema.sql`. The schema file creates tables but does not create user accounts.

**Q: Students can't register — it says admissions are closed.**
A: An admin needs to set the admissions window first. Log in as admin, go to **School Year**, and set the open and close dates.

**Q: The exam page says "No exam available."**
A: SSO or Admin needs to create an exam first. Go to **Exam** and create one with at least one section and one question.

**Q: Students aren't being assigned to exam slots automatically.**
A: Check that (1) exam room slots exist for the student's department, (2) the slots have available capacity, and (3) the auto-assign setting is turned on.

**Q: How do I reset a student's exam or interview?**
A: This is done through the admin panel by updating the student's status manually in the database.

**Q: The system works without `.env` — is that okay?**
A: Yes. Without `.env`, hCaptcha is disabled on registration and email notifications won't be sent, but everything else works normally.

---

## Notes

- The database defaults to `localhost` / `root` / no password (standard XAMPP). Override with environment variables (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`) if your setup is different.
- The auto-assignment and auto-reschedule logic lives in `core/automation.php` and `core/interview_scheduler.php`.
- All `/staff/interviews/...` URLs work normally. `/staff/interviews/desks` is an alias for `/staff/interviews/setup` for backward compatibility.
- File uploads go to the local `uploads/` directory. In production, configure a proper storage path.
