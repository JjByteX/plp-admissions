<?php
// ============================================================
// modules/manual/content.php
// The staff user manual, kept as plain markdown text.
// _parse.php turns it into blocks and index.php draws them.
// To change the manual, edit the text below. No other file needs to change.
//
// Supported: ## sections, ### headings, paragraphs, bullet and numbered
// lists (one nested level), pipe tables, **bold**, [text](#section) and
// [text](/page) links.
// ============================================================

return <<<'MD'
# PLP Admissions: User Manual

For staff of Pamantasan ng Lungsod ng Pasig.

Use this manual to find what a page does, what to click, and what to do when something goes wrong.

### Where to start

| Your role | Read |
|---|---|
| Everyone | [What this system is](#1-what-this-system-is), [Getting started](#2-getting-started), [The whole journey](#3-the-whole-journey) |
| SSO | [Documents](#4-documents), [Exam setup](#5-exam-setup), [Interviews](#7-interviews), [School year and courses](#9-school-year-and-courses) |
| Proctor | [Exam day](#6-exam-day) |
| Professor | [Interviews](#7-interviews) |
| Dean | [Interviews](#7-interviews), [Results](#8-results), [School year and courses](#9-school-year-and-courses) |
| Admin | Everything, plus [Users and audit log](#10-users-and-audit-log) |
| Something looks wrong | [When something looks wrong](#11-when-something-looks-wrong) |

---

## 1. What this system is

PLP Admissions takes a person from sign up to an admission result. An applicant goes through five steps:

1. Register.
2. Submit documents.
3. Take the entrance exam.
4. Attend the interview.
5. Get the result. If accepted, confirm enrollment.

Most moves between steps are automatic. Staff only act where a person must decide.

### Who uses it

| Role | What they do |
|---|---|
| Student | An applicant. Registers, uploads documents, takes the exam, attends the interview, sees the result. |
| SSO | Runs the cycle. Sets the school year and courses, reviews documents, builds the exam, sets exam slots and interview sessions, handles reschedules. |
| Proctor | Issues the exam access code in the room. Own college only. |
| Professor | Interviews applicants and marks Pass or Decline. Own college only. |
| Dean | Makes the final decision for own college. Releases results. Sets Max Slots and score tiers for own college. Views interviews, cannot interview. |
| Admin | Can do everything. Also manages users, the audit log and closing admissions. |

You only see the pages for your role. A page that is not yours will not open.

---

## 2. Getting started

### Signing in

1. Open the system and go to the sign in page.
2. Type your email and password.
3. Press **Sign in**. You land on your own home page.

Where you land:

| Role | Home page |
|---|---|
| Admin, SSO, Dean | Dashboard |
| Professor | Interview Queue |
| Proctor | Exam Slots |

There is no public sign up for staff. The Admin creates staff accounts.

### Forgot your password

1. On the sign in page, press **Forgot password?**
2. Type your email. A 6 digit code is sent.
3. Type the code and choose a new password (at least 8 characters).

Things to know:

- The code lasts 15 minutes.
- You can ask for a new code after 60 seconds.
- After 5 wrong tries the code stops working. Ask for a new one.

### Finding your way around

- **Sidebar (left):** your pages. A **red dot** means setup is missing. An **amber dot** means work is waiting.
- **Profile menu (bottom left, your name):**
  - **Settings:** change your password and switch the look.
  - **User manual:** this page.
  - **Dark Mode:** switch light and dark.
  - **Log out.**
- **Contents (this page):** the list on the left jumps to a section. On a phone, use **Jump to**.

### Sessions

- Staff stay signed in for 2 hours. A warning shows 5 minutes before the end.
- Students stay signed in for 30 minutes.
- If you are signed out while working, log in again and repeat the last action.

### Changing your password

1. Open the profile menu and press **Settings**.
2. Type your current password, the new one, and the new one again.
3. Tick **Show passwords** if you want to check your typing.
4. Press **Update Password**.

---

## 3. The whole journey

The steps for one applicant, in order.

### Step 1: Register

The applicant fills in the form. The system checks:

- The admissions window must be open.
- The course must still have slots.
- Freshmen must choose a strand that the course accepts.
- Freshmen must say if they are in Grade 12 or a Senior High School graduate.
- The phone number must be 11 digits, like 09123456789.
- The email and the name plus birthdate must be new.

Edge cases:

- **"Admissions are currently closed."** Registration is shut. Only the Admin or SSO can change the dates (see [School year and courses](#9-school-year-and-courses)).
- **"This course has reached its enrollment cap."** The applicant must pick another course.
- **"An applicant with the same name and birthdate already exists."** The person already has an account. Use it, or help them reset the password.

### Step 2: Documents

The applicant uploads each required document, then presses **Submit application**.

- Allowed files: PDF, JPG, PNG, WebP. Maximum 4 MB each.
- Required papers depend on the applicant type (freshman, transferee, foreign) and on the ticks they chose (married, guardian, Grade 12, SHS graduate).
- After the document deadline, uploads stop. Applicants who already submitted can still be reviewed.
- An applicant can withdraw a submission to fix a file, until staff approve everything.

Staff review the files. See [Documents](#4-documents).

### Step 3: Entrance exam

When every required document is approved, the system:

- Moves the applicant to the exam stage.
- Assigns an exam slot (date, time, room).
- Sends a notice.

If no slot is open, the applicant waits. They are assigned as soon as a matching slot is created.

On exam day the applicant enters the access code from the proctor. See [Exam day](#6-exam-day).

### Step 4: Interview

If the applicant passes the exam, the system assigns an interview session. The applicant is checked in automatically. They see the date, time and place on their interview page.

If the applicant fails the exam, no interview is given. The applicant appears in Results as **Recommended: Reject**.

If no session is open for the college, the applicant waits. They are assigned as soon as a session is created.

### Step 5: Result

The Professor marks the interview **Pass** or **Decline**. This is a recommendation. The Dean decides and releases the result. The applicant then sees **Accepted** or **Declined**.

### Step 6: Confirm enrollment

An accepted applicant presses **Confirm enrollment**. The system sends them to the Enrollment system. Enrollment makes their account, books a visit date and emails them.

If the send fails, it is retried automatically. Staff can also press **Send to Enrollment** in Results. See [Results](#8-results).

### Withdrawing

An applicant can withdraw before confirming enrollment. A withdrawn applicant is skipped everywhere and shows as **Withdrawn** in Results.

### What the student sees

| Stage | What they see |
|---|---|
| Documents | The list of papers, each with a status |
| Exam | Their slot, then the access code screen on exam day |
| Interview | Date, time, place, and a reschedule request button |
| Result | Accepted or Declined, and **Confirm enrollment** if accepted |

Document statuses: **Pending**, **Uploaded**, **Under Review**, **Approved**, **Declined** (needs a new file).

---

## 4. Documents

Who: SSO and Admin.

Open **Documents** in the sidebar. The list shows each applicant and where their papers stand.

### Reviewing one applicant

1. Open **Documents** and click the applicant.
2. Open each file.
3. Press **Approve**, or **Request resubmission**.

Request resubmission needs a reason. The student gets a notice with your reason and must upload a new file. If they had submitted, they go back to the documents stage.

### Approve All

- Press **Approve All** to approve every uploaded file for that applicant at once.
- It only works when every required paper is uploaded. If something is missing or declined, the button is greyed out. Review those one by one.
- A message confirms it. Press **Undo** to reverse it, or **Back to applicants** to go to the next one.

### Approve many applicants

1. On the list, tick the applicants.
2. Press **Approve Docs**.

Applicants with missing papers are skipped. The message tells you how many.

### Flagged files (AI sorting)

When AI sorting is on, each upload is checked against the slot it was put in. A wrong match shows a flag. For each flagged file choose:

- **Confirm category** if the file is in the right slot.
- **Move** to another empty slot if it is in the wrong one.

### Edge cases

- **All papers approved:** the applicant moves to the exam on its own. Nothing else to click.
- **Undo an approval:** only works before the applicant reaches the exam stage and before any exam result exists. Otherwise: "Cannot undo."
- **Approved file in the wrong slot:** undo the approval first, then move it.
- **Student removes a tick for an approved paper:** the system refuses.
- **Student picked the wrong applicant type:** they can change it before submitting. Required papers update.
- **Past the deadline:** students cannot upload. You can still review.

---

## 5. Exam setup

Who: SSO and Admin.

You need three things before applicants reach the exam: an active exam, exam slots, and (on the day) an access code.

### Build the exam

Open **Exam**.

1. Press **New Exam**. Add a description. Choose whether to shuffle questions and choices.
2. Add **sections**. Every question must belong to a section.
3. Add questions. Multiple choice needs at least 2 choices and a marked correct answer.
4. Check the question count on the exam card.

Rules:

- Only one exam is active. Creating a new exam makes it the active one. Use **Activate** to switch back.
- The title is made from the school year. You do not type it.
- Default time is 90 minutes. The real time is the slot start to close time.

Edge cases:

- **Results already exist:** questions, choices, points and answer keys are locked, so recorded scores stay right. You can fix wording only. To change the content, make a new exam.
- **Students are taking the exam now:** deleting is blocked. Editing asks you to confirm, because it changes their scores right away.
- **No active exam:** students see "No active entrance exam has been set up yet."

### Exam slots

Open **Exam**, then **Exam Slots**.

A slot is one room at one date and time.

1. Press **Add slot**.
2. Fill in date, room, college, capacity, start and close time.
3. Save.

Rules:

- Capacity is 1 to 500. The default per room is 35 and the daily limit is 3000. Both can be changed on the page.
- Close time must be after start time.
- Use **Batch create** to make many slots at once.
- A slot with applicants cannot be deleted. Unassign them first.
- New slots pick up waiting applicants automatically.

### Exam reschedules

Open **Exam Reschedules**. A student asks to move their exam.

- **Approve:** the student moves to a new slot. If none is open, it refuses. Create a slot first, then approve again.
- **Deny:** the student keeps the slot.

---

## 6. Exam day

Who: Proctor (own college), SSO, Admin.

### Giving the access code

1. Open **Exam Slots** and click your room.
2. Press **Generate code**. An 8 character code shows.
3. Say the code to the room.
4. Students type it in to start.

The code lasts 5 minutes.

- **Late student or expired code:** press **Extend** to restart the 5 minutes, or **Generate code** for a new one.
- **"No code set yet":** generate one first.
- You can only make codes for rooms in your own college.

### What students experience

- Before the exam date they see a waiting screen with their slot.
- Answers save by themselves every few seconds.
- If the internet drops, the exam keeps going and saves when it returns.
- When time ends, the exam submits from the last saved answers.
- A normal submit is accepted for a short time after close.

### Edge cases

- **Student says the code is wrong:** it likely expired. Extend or generate.
- **Student has no saved answers and the room closed:** the system shows "contact the admissions office." The applicant is not scored zero. Staff handle it.
- **Student opens the exam twice:** the second submit is ignored and they go to their page.
- **Proctor sees a read-only page** apart from the code buttons. Only SSO and Admin edit slots.

---

## 7. Interviews

### Setting up sessions (SSO and Admin)

Open **Interviews**, then **Interview Setup**, then choose a college.

1. Press **Add Session**.
2. Fill in:
   - **Assigned Interviewer:** a Professor of that college, or an Admin.
   - **Location label:** like "Room 201".
   - **Date:** today or later.
   - **Start and end time:** end after start.
   - **Capacity:** default 30.
3. Save.

Deans never appear in the interviewer list. Only Professors interview.

How applicants get a session:

- The system picks the open session with the fewest booked, then the earliest date and time.
- If there is none, the applicant waits and is assigned when a session is created.

### Interviewing (Professor)

Open **Interview Queue**. You see applicants for your college and sessions.

1. Open the applicant. Read the details.
2. Interview them.
3. Press **Evaluation**. Add notes. Choose **Pass** or **Decline**.
4. Confirm.

Things to know:

- Pass or Decline is a recommendation. The Dean decides.
- A finished row only shows **View**.
- The room and notes at the top only show for an open session that has not passed.
- If the session ends and the applicant never came, they are marked absent automatically.

### Dean view

The Dean sees the queue for their college to follow progress. There is no Evaluation button.

### Absent students and reschedules (SSO and Admin)

Open **Interview Reschedules** in the sidebar. It has two tabs.

- **Absent Students tab:** tick students and press **Reschedule**. They get a new session and a notice.
- **Requests tab:** students ask to move their interview. **Approve** gives a new session. **Deny** keeps the old one.

### Cancel a whole session (SSO and Admin)

Use this for typhoons or room problems.

1. On the Interview Reschedules page, press **Cancel a slot (bulk move)**.
2. Choose the session to cancel and the replacement session.
3. Confirm.

Everyone in the old session moves to the new one. Each student gets a notice and an email.

Edge cases:

- **Replacement is full:** it cannot take everyone. Pick a bigger session or make one.
- **Student already done:** they keep their result.

---

## 8. Results

Who: Dean (own college) and Admin (all colleges). SSO does not see this page.

Open **Results**. Each applicant is in one group:

| Group | Meaning |
|---|---|
| Awaiting interview | Exam done, interview not yet evaluated. No action yet. |
| Recommended: Accept | Exam passed and Professor marked Pass |
| Recommended: Reject | Exam failed or Professor marked Decline |
| Released | The result is final and the student can see it |
| Withdrawn | The applicant left. No action. |

### Releasing a result

1. Find the applicant.
2. Press **Accept** or **Reject**.
3. If your choice goes against the recommendation, a box asks for a written reason. Type it and confirm.

The reason is saved and recorded in the audit log.

To release many at once, tick the rows and use the bulk button.

### Max Slots

Each course has a Max Slots number.

- When a course is full, **Accept is blocked** with "Slots are full."
- In a bulk release, extra accepts are skipped and the message tells you how many.
- To take more, raise Max Slots in **Courses and Strands**.
- Registration also stops for a full course.

### Sending to Enrollment

After the student confirms enrollment, the system sends them automatically. The status shows in the row:

| Badge | Meaning |
|---|---|
| Sent to Enrollment | Their Enrollment account exists |
| Student and number | The Registrar received their documents |
| Enrolled | The COR was issued |
| **Send to Enrollment** button | The send has not worked yet |

If the button shows, hover it to read the error. Press it to try again. Sending twice is safe. It refreshes, never duplicates.

### Admin only

- **Edit** on a released row changes the result. A reason is required and recorded.
- **Close Admissions** rejects every applicant who has not been released. Use it only when the cycle is over.

### Edge cases

- **Dean sees an empty page:** the Dean account has no college. Ask the Admin.
- **Dean cannot act on an applicant:** that applicant is in another college.
- **Applicant withdrew after release:** the row shows Withdrawn.
- **Accepted applicant has not confirmed:** nothing is sent until they confirm.

---

## 9. School year and courses

### School year

Who: SSO and Admin. Open **School Year**.

Set three dates:

- **Admissions opens**
- **Admissions closes**
- **Document deadline**

Rules:

- Close must be after open.
- The deadline must be after open and on or before close.
- The school year is worked out from the open date.
- A red dot on the sidebar means a date is missing.

**Start new cycle:** type "Start new cycle" to confirm. It switches off the active exam. Old data is kept.

### Courses and strands

Who: SSO and Admin (full control). Dean (own college only: Max Slots and tiers).

Open **Courses and Strands**.

- **Add, edit, delete a course.** Set its strands and its college.
- **Max Slots:** how many applicants can be accepted this year. A red dot means a course has none set.
- **Tiers:** the exam score ranges. By default 7 to 10 is High, 4 to 6 is Average (both pass), and 1 to 3 is Low (fails). Each course can change where passing starts.

Edge cases:

- **A course with no college** is skipped by exam slots, interview sessions and the Dean's view. Always set one.
- **Strand not listed for a course:** freshmen with that strand cannot register for it.

---

## 10. Users and audit log

Who: Admin only.

### Users

Open **Users**.

- **Create:** name, email, role, password (at least 8 characters). Dean and Proctor need a college.
- **Change college:** edit the user.
- **Deactivate or activate:** an inactive user cannot log in.
- **Reset password:** type a new password (at least 8 characters) and share it safely.
- **Delete:** removes a staff account.

You cannot deactivate or delete your own account. Student accounts cannot be deleted here.

### Audit log

Open **Audit Log**. Every important action is listed with who did it and when. Search it to find out who changed a result, an exam, or a user.

---

## 11. When something looks wrong

| What you see | Likely reason | What to do |
|---|---|---|
| Applicant stuck at documents | A paper is missing or declined | Open them. Request resubmission or wait for the student. |
| Approve All is greyed out | Papers are missing | Review one by one |
| Applicant waiting for an exam slot | No open slot for their college | Add a slot. They are assigned at once. |
| Applicant waiting for an interview | No open session for their college | Add a session in Interview Setup |
| Student says the exam code is wrong | The 5 minutes ran out | Extend or generate a new code |
| Student missed the interview | Marked absent | Reschedule from the Absent page |
| Professor sees no applicants | No session assigned, or wrong college | Check the session interviewer and college |
| Interviewer missing from the list | Not a Professor of that college, or inactive | Check the user in Users. Deans never show. |
| Cannot accept an applicant | Course is full | Raise Max Slots in Courses and Strands |
| Send to Enrollment failed | Enrollment is down or rejected the data | Hover for the error. Try again later. |
| A page says you do not have permission | The page is not for your role | Use the pages in your sidebar |
| A staff member cannot log in | Account is inactive or password changed | Admin: check Users, reset the password |
| A Dean sees nothing | The Dean has no college | Admin: set the college in Users |
| Student cannot register | Window closed, course full, or duplicate | See [The whole journey](#3-the-whole-journey) |
MD;
