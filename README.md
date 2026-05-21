# PLP Admissions Management System

A web-based admissions system built with PHP and MySQL, covering the full enrollment flow from application to final result.

---

## Requirements

- **XAMPP** (PHP 8.x + Apache + MySQL)
- A **Gmail account** with an App Password for email sending
- A **hCaptcha** account for the registration form (can be disabled for testing)

---

## Setup

### 1. Place the Project

Put the `plp-admissions/` folder inside your XAMPP `htdocs/` directory:

```
C:/xampp/htdocs/plp-admissions/
```

### 2. Create the Database

1. Open **phpMyAdmin** → create a new database named `plp_admissions`
2. Import `database/schema.sql` to create all tables
3. Import `database/seed_users.sql` to create the default accounts

### 3. Configure Environment

Copy `.env.example` to `.env` and fill in your values:

```env
# hCaptcha — get keys from https://dashboard.hcaptcha.com
HCAPTCHA_SITE_KEY=your_site_key
HCAPTCHA_SECRET_KEY=your_secret_key

# Gmail SMTP — use an App Password, not your real password
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your_email@gmail.com
SMTP_PASS=your_app_password
SMTP_FROM_NAME=PLP Admissions
```

> **hCaptcha tip:** You can disable hCaptcha during testing by commenting it out in the login/register pages.

> **Gmail App Password:** Go to your Google Account → Security → 2-Step Verification → App Passwords → generate one for "Mail".

### 4. Run the Project

Start **Apache** and **MySQL** in XAMPP, then open:

```
http://localhost/plp-admissions/
```

---

## Default Accounts

After importing `seed_users.sql`, the following accounts are available:

### Admin & SSO
| Role | Email | Password |
|------|-------|----------|
| Admin | admin@plp.edu.ph | Admin@123 |
| SSO | sso@plp.edu.ph | SSO@123 |

### Deans
| College | Email | Password |
|---------|-------|----------|
| College of Computer Studies | dean.ccs@plp.edu.ph | Dean@123 |
| College of Nursing | dean.con@plp.edu.ph | Dean@123 |
| College of Business and Accountancy | dean.cba@plp.edu.ph | Dean@123 |
| College of Education | dean.coe@plp.edu.ph | Dean@123 |
| College of Arts and Sciences | dean.cas@plp.edu.ph | Dean@123 |
| College of Engineering | dean.cen@plp.edu.ph | Dean@123 |

### Professors (Staff)
| College | Email | Password |
|---------|-------|----------|
| College of Computer Studies | staff.ccs@plp.edu.ph | Staff@123 |
| College of Nursing | staff.con@plp.edu.ph | Staff@123 |
| College of Business and Accountancy | staff.cba@plp.edu.ph | Staff@123 |
| College of Education | staff.coe@plp.edu.ph | Staff@123 |
| College of Arts and Sciences | staff.cas@plp.edu.ph | Staff@123 |
| College of Engineering | staff.cen@plp.edu.ph | Staff@123 |

### Proctors
| College | Email | Password |
|---------|-------|----------|
| College of Computer Studies | proctor.ccs@plp.edu.ph | Proctor@123 |
| College of Nursing | proctor.con@plp.edu.ph | Proctor@123 |
| College of Business and Accountancy | proctor.cba@plp.edu.ph | Proctor@123 |
| College of Education | proctor.coe@plp.edu.ph | Proctor@123 |
| College of Arts and Sciences | proctor.cas@plp.edu.ph | Proctor@123 |
| College of Engineering | proctor.cen@plp.edu.ph | Proctor@123 |

> Change all passwords after first login.

---

## Roles Overview

| Role | What they do |
|------|-------------|
| **Admin** | Full system access, manages users and school setup |
| **SSO** | Sets up admissions schedule, exam, interview slots, reviews documents, exports results |
| **Dean** | Sets course slots and passing tiers, releases final admission results |
| **Professor** | Conducts interviews and submits pass/reject recommendations |
| **Proctor** | Manages exam rooms and generates exam access codes |
| **Student** | Applies, uploads documents, takes exam, attends interview, views result |

---

## Notes

- `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` default to XAMPP's standard values (`localhost`, `plp_admissions`, `root`, no password). No changes needed unless your setup differs.
- Do **not** commit `.env` to Git — it contains your credentials.
- The `database/` folder contains the schema and seed files only. Do not delete them.
