-- ============================================================
-- seed_users.sql
-- Run this ONCE after loading schema.sql to create the admin,
-- SSO (Student Success Office), one Dean per college, and one
-- Professor (legacy 'staff' role) per college.
--
-- ⚠️  Change all passwords after first login.
--
-- Default passwords:
--   admin@plp.edu.ph         → Admin@123
--   sso@plp.edu.ph           → SSO@123
--   dean.<dept>@plp.edu.ph   → Dean@123
--   staff.<dept>@plp.edu.ph  → Staff@123
-- ============================================================

-- Remove any existing seeded accounts first (safe to re-run)
DELETE FROM `users` WHERE `email` IN (
    'admin@plp.edu.ph',
    'sso@plp.edu.ph',
    'dean.ccs@plp.edu.ph',
    'dean.con@plp.edu.ph',
    'dean.cba@plp.edu.ph',
    'dean.coe@plp.edu.ph',
    'dean.cas@plp.edu.ph',
    'dean.cen@plp.edu.ph',
    'staff.ccs@plp.edu.ph',
    'staff.con@plp.edu.ph',
    'staff.cba@plp.edu.ph',
    'staff.coe@plp.edu.ph',
    'staff.cas@plp.edu.ph',
    'staff.cen@plp.edu.ph'
);

-- ── Admin ──────────────────────────────────────────────────
-- Email:    admin@plp.edu.ph
-- Password: Admin@123
INSERT INTO `users`
    (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `department`)
VALUES
    ('System Administrator', 'System', 'Administrator',
     'admin@plp.edu.ph',
     '$2b$12$G5BY/5px8Ud.VP3ttTgr9e2xBrRJJtep7OUqNLfCB6NDzmheORx6u',
     'admin', '');

-- ── SSO (Student Success Office) ───────────────────────────
-- One front-desk operations account. Owns documents, exam content,
-- exam/interview scheduling, and results release.
-- Email:    sso@plp.edu.ph
-- Password: SSO@123
INSERT INTO `users`
    (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `department`)
VALUES
    ('Student Success Office', 'SSO', 'Office',
     'sso@plp.edu.ph',
     '$2b$12$59ZEdYeB10B3KAmHTH9cUuDJ9V6PfCo/PfU1jEnAHVAPlsdaO8ltW',
     'sso', '');

-- ── Deans (one per college) ────────────────────────────────
-- Each Dean is scoped to their own department. They edit courses,
-- tier thresholds, and review results for their college only.
-- Password for all deans: Dean@123

-- College of Computer Studies
INSERT INTO `users`
    (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `department`)
VALUES
    ('CCS Dean', 'CCS', 'Dean',
     'dean.ccs@plp.edu.ph',
     '$2b$12$9xMBBWuiCofCrQedFHUOj.vGT561TYODD43nWqT38b3XsA1exUbuq',
     'dean', 'College of Computer Studies');

-- College of Nursing
INSERT INTO `users`
    (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `department`)
VALUES
    ('CON Dean', 'CON', 'Dean',
     'dean.con@plp.edu.ph',
     '$2b$12$1zHOxbukAe4.oHU8.L0pE.w7HCfvsx2iXUUjlJtVQOiXScvrocgAu',
     'dean', 'College of Nursing');

-- College of Business and Accountancy
INSERT INTO `users`
    (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `department`)
VALUES
    ('CBA Dean', 'CBA', 'Dean',
     'dean.cba@plp.edu.ph',
     '$2b$12$7o0q7iS..6oqaG2FrY/Gi./Ln0ccHeOQrGil6JOM/khw3WE02lJJy',
     'dean', 'College of Business and Accountancy');

-- College of Education
INSERT INTO `users`
    (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `department`)
VALUES
    ('COE Dean', 'COE', 'Dean',
     'dean.coe@plp.edu.ph',
     '$2b$12$hMmoPLISbwQv4CqyEOqe3OrBQ/0Kv9UO9Bs8pNQcXgrdQms1M9pXi',
     'dean', 'College of Education');

-- College of Arts and Sciences
INSERT INTO `users`
    (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `department`)
VALUES
    ('CAS Dean', 'CAS', 'Dean',
     'dean.cas@plp.edu.ph',
     '$2b$12$ONf.PKuA367lVIM0fAwCjO98EIb5CCbFggKWChbPbgecC4SGG.3gy',
     'dean', 'College of Arts and Sciences');

-- College of Engineering
INSERT INTO `users`
    (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `department`)
VALUES
    ('CEN Dean', 'CEN', 'Dean',
     'dean.cen@plp.edu.ph',
     '$2b$12$zziXN8OOGOv.vm2PaCGpO.CF247JYvoigABbEVBwJi/Q1LkKe5Dc2',
     'dean', 'College of Engineering');

-- ── Professors (legacy 'staff' role, one per college) ──────
-- These are faculty who proctor exams and conduct interviews.
-- The DB enum value stays 'staff' for compatibility; the UI shows
-- "Professor" as the role label.
-- Password for all professors: Staff@123

-- College of Computer Studies
INSERT INTO `users`
    (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `department`)
VALUES
    ('CCS Staff', 'CCS', 'Staff',
     'staff.ccs@plp.edu.ph',
     '$2b$12$j.9DuKYAu77U5KFTpaDGo.f5suAmIS9RnAT9Hj7pciqrw4NkGcUr6',
     'staff', 'College of Computer Studies');

-- College of Nursing
INSERT INTO `users`
    (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `department`)
VALUES
    ('CON Staff', 'CON', 'Staff',
     'staff.con@plp.edu.ph',
     '$2b$12$sGWl6gY0pVOhSuoIVgiLgO02T6c19/XKK/gPhPoJi6GqK.YadXOR2',
     'staff', 'College of Nursing');

-- College of Business and Accountancy
INSERT INTO `users`
    (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `department`)
VALUES
    ('CBA Staff', 'CBA', 'Staff',
     'staff.cba@plp.edu.ph',
     '$2b$12$2uGPeztTdkmzcwyiUsLwLOPrD.rTTdFYFSbMnqS5IqjMse/yYrbOq',
     'staff', 'College of Business and Accountancy');

-- College of Education
INSERT INTO `users`
    (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `department`)
VALUES
    ('COE Staff', 'COE', 'Staff',
     'staff.coe@plp.edu.ph',
     '$2b$12$ePjqEI6yl36V1X.9E/SR5Op7FHxW9R.BZhPeWSfZAHcdhnhNJ9g0a',
     'staff', 'College of Education');

-- College of Arts and Sciences
INSERT INTO `users`
    (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `department`)
VALUES
    ('CAS Staff', 'CAS', 'Staff',
     'staff.cas@plp.edu.ph',
     '$2b$12$VXyJuhQMBxWzketJGy5tTeWkl7QRpBrM2xXlRQHwhhN2rexTDycMa',
     'staff', 'College of Arts and Sciences');

-- College of Engineering
INSERT INTO `users`
    (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `department`)
VALUES
    ('CEN Staff', 'CEN', 'Staff',
     'staff.cen@plp.edu.ph',
     '$2b$12$euGKeofGDb/1XMIinGyopOzQ7NqxJI0N.LR3dALq18BgGYOcYkvZG',
     'staff', 'College of Engineering');
