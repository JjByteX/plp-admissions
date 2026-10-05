# PLP Admissions Management System

A web-based admissions system built with PHP and PostgreSQL (Supabase), covering the full enrollment flow from application to final result.

---

## Local Setup (Windows)

**You need:** PHP 8.2+ in your PATH (XAMPP's PHP works) and the `.env` file from the team lead.

1. Clone the repo.
2. Drop the **`.env`** file the team lead sent you into the project folder, next to `setup.bat`.
3. Double-click **`setup.bat`** (or run `.\setup.ps1`). It enables the PostgreSQL extensions in your `php.ini` and checks that your `.env` is there. Safe to re-run.
4. Start the app:
   ```bash
   php -S localhost:8000
   ```
5. Open **http://localhost:8000/public/** and log in with a [default account](#default-accounts). Press `Ctrl+C` to stop.

> If you change `php.ini` or `.env`, restart the server.
>
> Not on Windows? Enable `pdo_pgsql` and `pgsql` in your `php.ini` yourself. The `.env` step is the same.

### About the `.env` file

This repo is public, so the database credentials are **never committed**. `.env` is gitignored and shared privately (direct message, not a public channel).

- Never paste its contents into an issue, a pull request, or a chat that isn't private.
- It holds **development-project credentials only**, with fake data. Never put production keys in it.
- The real deployment uses a new Supabase project, with its keys set in Vercel's environment variables only. Never reuse the dev keys.
- If it leaks, tell the team lead. The database password and service key get rotated in Supabase and a new `.env` is sent out.

### Database

The development database is shared and already loaded. You don't need to set anything up.

- `database/schema.sql` **drops and recreates every table**. Only run it for a fresh database or an agreed reset, never casually.
- `database/seed_users.sql` creates the default accounts. Run it once after the schema.
- Everyone shares the same test data, so use fake email addresses for test applicants. If SMTP is configured, real emails are sent.

### Optional `.env` values

| Setting | When you need it |
|---------|------------------|
| `SMTP_*` (Gmail App Password) | Email sending, such as student verification codes. Without it, emails are skipped and logged, and you can still log in with the seeded accounts. |
| `SUPABASE_URL`, `SUPABASE_SERVICE_KEY` | Document uploads and branding images (Supabase Storage). |
| `HCAPTCHA_*` | Not needed. hCaptcha is off (`HCAPTCHA_ENABLED` is `false` in `config/app.php`). |

---

## Troubleshooting

| What you see | Fix |
|--------------|-----|
| "PHP extension pdo_pgsql is not enabled" | Run `setup.bat`, then restart the server. |
| "Database connection error" | Check the terminal. `could not find driver` means run `setup.bat`. A password or host error means re-check `DB_*` in `.env`. |
| `setup.bat` can't edit `php.ini` | Right-click it and choose Run as administrator. |
| `favicon.ico` 404 in the terminal | Harmless. |

---

## Default Accounts

Created by `seed_users.sql`. Change all passwords after first login.

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

---

## Roles

| Role | What they do |
|------|--------------|
| **Admin** | Full system access, manages users and school setup |
| **SSO** | Sets up the admissions schedule, exam and interview slots, reviews documents, exports results |
| **Dean** | Sets course slots and passing tiers, releases final admission results |
| **Professor** | Conducts interviews and submits pass/reject recommendations |
| **Proctor** | Manages exam rooms and generates exam access codes |
| **Student** | Applies, uploads documents, takes the exam, attends the interview, views the result |

---

## CI

GitHub Actions runs on every pull request: PHP lint, SonarQube, a secret scan, a schema check, and an app smoke test. CI uses its own throwaway Postgres and never touches the shared Supabase database.
