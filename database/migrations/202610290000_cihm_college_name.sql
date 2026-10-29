-- Rename the College of Hospitality Management to the College of
-- International Hospitality Management (CIHM), carry the new name into every
-- table that stores the college name as text, and add the CIHM Dean,
-- Professor and Proctor accounts so the college has people to assign.
-- Safe to run twice.

BEGIN;

UPDATE departments SET name = 'College of International Hospitality Management' WHERE code = 'HM';

UPDATE users               SET department = 'College of International Hospitality Management' WHERE department = 'College of Hospitality Management';
UPDATE exam_slot_schedule  SET department = 'College of International Hospitality Management' WHERE department = 'College of Hospitality Management';
UPDATE interview_slots     SET department = 'College of International Hospitality Management' WHERE department = 'College of Hospitality Management';

-- Default passwords: Dean@123 / Staff@123 / Proctor@123. Change after first login.
INSERT INTO users (name, first_name, last_name, email, password_hash, role, department, email_verified)
VALUES
    ('CIHM Dean',    'CIHM', 'Dean',    'dean.cihm@plp.edu.ph',    '$2b$12$Y8.9C.EsgGjOUAtWZa5D.eDZkJ3slub7J4.9qyMRamskaMRwtS/lK',    'dean',    'College of International Hospitality Management', 1),
    ('CIHM Staff',   'CIHM', 'Staff',   'staff.cihm@plp.edu.ph',   '$2b$12$cc4Lhsf4526jZ2Tn6TGuAOykOKIAvlHkEQc3PMqHRIDDooSV5II.a',   'staff',   'College of International Hospitality Management', 1),
    ('CIHM Proctor', 'CIHM', 'Proctor', 'proctor.cihm@plp.edu.ph', '$2b$12$.lGP7mVx9dWuyPBh.G9Hz.ZBMvmdaIRO02reU6F3W0uIsxY9ARz3O', 'proctor', 'College of International Hospitality Management', 1)
ON CONFLICT (email) DO NOTHING;

COMMIT;
