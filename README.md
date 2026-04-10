# PLP Admissions System

A web-based admissions management system for **Pamantasan ng Lungsod ng Pasig (PLP)**, built with PHP and MySQL. It handles the end-to-end admissions workflow — from student registration and document submission, to entrance exams, interviews, and results release.

---

## Tech Stack

- **Backend:** PHP (vanilla, no framework)
- **Database:** MySQL / MariaDB
- **Frontend:** HTML, CSS, JavaScript
- **Server:** Apache via XAMPP

---

## Requirements

- [XAMPP](https://www.apachefriends.org/) (Apache + MySQL)
- PHP 8.0+
- A modern web browser

---

## Local Setup

### 1. Clone the Repository

```bash
git clone https://github.com/JjByteX/plp-admissions.git
```

Then copy the `plp-admissions` folder into your XAMPP web root:

```
C:\xampp\htdocs\plp-admissions\
```

Your directory should look like:

```
C:\xampp\htdocs\plp-admissions\
├── config\
├── core\
├── database\
├── modules\
├── public\
└── views\
```

---

### 2. Start XAMPP

Open the XAMPP Control Panel and start both:
- **Apache**
- **MySQL**

Both should show green before continuing.

---

### 3. Create the Database

Open the XAMPP **Shell** and run:

```bash
mysql -u root -p
```

Press **Enter** when prompted for a password (blank by default).

Then run:

```sql
CREATE DATABASE plp_admissions CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE plp_admissions;
source C:/xampp/htdocs/plp-admissions/database/schema.sql
```

You should see several `Query OK` messages. The schema will also create a default admin account (`admin@plp.edu.ph`).

---

### 4. Set the Admin Password

> ⚠️ The schema seeds the admin account with a **placeholder password hash**. You must update it before logging in.

Still inside the MariaDB shell, run:

```sql
UPDATE users
SET password_hash = '$2y$12$57QF.xJzIm..jLPxlA2TO.QqENIdI1HKzNFYiMA.zkossK5YwvfQC'
WHERE email = 'admin@plp.edu.ph';
```

Then exit:

```sql
exit
```

---

### 5. Open the System

Go to:

```
http://localhost/plp-admissions/public/
```

Log in with the admin account:

| Field    | Value              |
|----------|--------------------|
| Email    | admin@plp.edu.ph   |
| Password | Admin@123          |

> ⚠️ Change the admin password immediately after your first login.

---

## Project Structure

```
plp-admissions/
├── config/         # App and database configuration
├── core/           # Router, Auth, Session, helpers
├── database/       # schema.sql (tables + seed data)
├── modules/        # Feature modules (auth, exam, interview, documents, results, settings)
├── public/         # Entry point (index.php), assets, uploads
└── views/          # Layouts and partials
```

---

## Roles

| Role    | Description                                              |
|---------|----------------------------------------------------------|
| Admin   | Full system access — settings, users, exports, results   |
| Staff   | Manages documents, exam slots, interviews, and results   |
| Student | Registers, uploads requirements, takes exam, views results |

---

## TODO

### Huenda — Dashboard
- [ ] Remove all placeholder/AI-generated elements (quick actions, filler widgets)
- [ ] Redesign dashboard from scratch with focus on clarity and minimalism
- [ ] Show only the most important metrics (e.g. exam passed/failed counts, applicant pipeline)
- [ ] Draw a wireframe layout (hand-drawn or digital) before implementation
- [ ] Include an export button for key reports
- [ ] Reference modern dashboard designs from Dribbble for inspiration

### Cabilles — Interviews
- [ ] Add an **"I'm Here"** check-in button for students when their interview time is ready
- [ ] Auto-assign a queue number upon check-in
- [ ] Support multiple staff conducting interviews simultaneously, each managing their own queue
- [ ] Display desk/location instructions so students know where to go
- [ ] Allow interviewers to record evaluation notes or assessments per student within the system

### Chavez — Applicants
- [ ] Enhance filter option beside the search bar for sorting/refining results. Change filter status because it is redundant since the tabs already filters it. Instead, use filter by course, type, date applied etc.

### Bassig — Exam Manager
- [ ] Remove answer mode in each question as sections already describes it
- [ ] Allow section deleting with confirmation
- [ ] Show edit section only on empty sections


---

## Contributing

This is a capstone/academic project.
