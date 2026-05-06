-- ============================================================
-- seed_users.sql
-- Run this ONCE after loading schema.sql to create the admin
-- and one staff account per college.
--
-- ⚠️  Change all passwords after first login.
-- ============================================================

-- Remove any existing seeded accounts first (safe to re-run)
DELETE FROM `users` WHERE `email` IN (
    'admin@plp.edu.ph',
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

-- ── Staff accounts (one per college) ───────────────────────
-- Password for all staff: Staff@123

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
