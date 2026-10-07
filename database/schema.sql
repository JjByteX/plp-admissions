-- ============================================================
-- PLP Student Admission & Management System
-- Complete database schema (PostgreSQL / Supabase)
-- Pamantasan ng Lungsod ng Pasig
-- ============================================================
--
-- This is a single schema file. Running it will:
--   1. Drop every table the application uses (data is wiped)
--   2. Recreate every table fresh, with all current columns,
--      including the tables and columns the old PHP code used to
--      create at runtime (login_attempts, exam_drafts,
--      reschedule_requests, exam_reschedule_requests, the email
--      verification columns on users, interview_queue.checkin_code)
--   3. Seed default settings, departments, courses and an admin
--
-- WARNING: This file DROPS existing tables. Only run it on a
-- production database when you intentionally want to reset.
--
-- HOW TO RUN
--   Supabase dashboard: SQL Editor > New query > paste this file > Run.
--   Run it before seed_users.sql.
--
-- BEFORE YOU RUN
--   Set the postgres role timezone to Asia/Manila (plan step 1.8)
--   so created_at defaults and NOW() match the PHP clock.
--
-- NOTES ON THE MYSQL TO POSTGRES MAPPING
--   * TINYINT(1) flags are smallint, so "= 1", "SET x = 1" and
--     SUM(x) in the PHP code keep working.
--   * ENUM columns are text with a CHECK of the same values.
--   * DATETIME is timestamp(0) and TIME is time(0), so values keep
--     whole seconds like MySQL.
--   * users.email and login_attempts.email are citext, so email
--     lookups and the UNIQUE rule stay case-insensitive like the
--     old utf8mb4_unicode_ci collation.
--   * "ON UPDATE CURRENT_TIMESTAMP" is done with triggers.
--   * Index and unique names are unique across the schema in
--     Postgres, so a few were prefixed with their table name.
--   * Row Level Security is on for every table with no policies.
--     The app connects as the postgres role, which bypasses RLS.
--     The public Supabase API keys can read nothing.
--
-- Requires: PostgreSQL 13+ (Supabase)
-- ============================================================

CREATE EXTENSION IF NOT EXISTS citext;

-- ------------------------------------------------------------
-- Drop all existing tables. CASCADE removes dependent foreign
-- keys and triggers, so order does not matter.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS admission_results        CASCADE;
DROP TABLE IF EXISTS applicant_exam_slots     CASCADE;
DROP TABLE IF EXISTS applicants               CASCADE;
DROP TABLE IF EXISTS audit_logs               CASCADE;
DROP TABLE IF EXISTS course_caps              CASCADE;
DROP TABLE IF EXISTS course_departments       CASCADE;
DROP TABLE IF EXISTS course_passing_scores    CASCADE;
DROP TABLE IF EXISTS course_suggestions       CASCADE;
DROP TABLE IF EXISTS custom_courses           CASCADE;
DROP TABLE IF EXISTS department_schedules     CASCADE;
DROP TABLE IF EXISTS departments              CASCADE;
DROP TABLE IF EXISTS document_validations     CASCADE;
DROP TABLE IF EXISTS documents                CASCADE;
DROP TABLE IF EXISTS exam_drafts              CASCADE;
DROP TABLE IF EXISTS exam_reschedule_requests CASCADE;
DROP TABLE IF EXISTS exam_results             CASCADE;
DROP TABLE IF EXISTS exam_sections            CASCADE;
DROP TABLE IF EXISTS exam_slot_schedule       CASCADE;
DROP TABLE IF EXISTS exams                    CASCADE;
DROP TABLE IF EXISTS interview_queue          CASCADE;
DROP TABLE IF EXISTS interview_slots          CASCADE;
DROP TABLE IF EXISTS login_attempts           CASCADE;
DROP TABLE IF EXISTS notifications            CASCADE;
DROP TABLE IF EXISTS password_resets          CASCADE;
DROP TABLE IF EXISTS questions                CASCADE;
DROP TABLE IF EXISTS reschedule_logs          CASCADE;
DROP TABLE IF EXISTS reschedule_requests      CASCADE;
DROP TABLE IF EXISTS school_settings          CASCADE;
DROP TABLE IF EXISTS sessions                 CASCADE;
DROP TABLE IF EXISTS users                    CASCADE;

-- ------------------------------------------------------------
-- Trigger functions that replace MySQL's
-- "ON UPDATE CURRENT_TIMESTAMP".
-- Like MySQL, the column is only bumped when the row really
-- changed and the UPDATE did not set the column itself.
-- ------------------------------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger
LANGUAGE plpgsql
SET search_path = ''
AS $$
BEGIN
    IF NEW.updated_at IS NOT DISTINCT FROM OLD.updated_at
       AND NEW IS DISTINCT FROM OLD THEN
        NEW.updated_at := CURRENT_TIMESTAMP;
    END IF;
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION set_saved_at() RETURNS trigger
LANGUAGE plpgsql
SET search_path = ''
AS $$
BEGIN
    IF NEW.saved_at IS NOT DISTINCT FROM OLD.saved_at
       AND NEW IS DISTINCT FROM OLD THEN
        NEW.saved_at := CURRENT_TIMESTAMP;
    END IF;
    RETURN NEW;
END;
$$;

-- ============================================================
-- 1. Core: users
-- ============================================================
CREATE TABLE users (
    id                           integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    name                         varchar(120) NOT NULL,
    first_name                   varchar(100) NOT NULL DEFAULT '',
    middle_name                  varchar(100) NOT NULL DEFAULT '',
    last_name                    varchar(100) NOT NULL DEFAULT '',
    suffix                       varchar(20)  NOT NULL DEFAULT '',
    birthdate                    date         DEFAULT NULL,
    sex                          text         DEFAULT NULL CHECK (sex IN ('M','F')),
    address                      varchar(255) NOT NULL DEFAULT '',
    phone                        varchar(20)  NOT NULL DEFAULT '',
    email                        citext       NOT NULL,
    password_hash                varchar(255) NOT NULL,
    role                         text         NOT NULL DEFAULT 'student'
                                 CHECK (role IN ('student','staff','proctor','sso','dean','admin')),
    department                   varchar(120) NOT NULL DEFAULT '',
    is_active                    smallint     NOT NULL DEFAULT 1,
    email_verified               smallint     NOT NULL DEFAULT 0,
    email_verify_token           varchar(64)  DEFAULT NULL,
    email_verify_code            varchar(8)   DEFAULT NULL,
    email_verify_code_expires_at timestamp(0) DEFAULT NULL,
    email_verify_attempts        smallint     NOT NULL DEFAULT 0,
    email_verify_last_sent_at    timestamp(0) DEFAULT NULL,
    desk_label                   varchar(120) NOT NULL DEFAULT '',
    desk_notes                   text         DEFAULT NULL,
    created_at                   timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                   timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_email UNIQUE (email)
);
CREATE INDEX idx_users_department ON users (department);
CREATE TRIGGER trg_users_updated_at BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

COMMENT ON COLUMN users.department IS 'College/department name (see departments.name)';

-- ============================================================
-- 2. Departments  +  course to department mapping
-- ============================================================
CREATE TABLE departments (
    id         integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code       varchar(20)  NOT NULL,
    name       varchar(120) NOT NULL,
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_dept_code UNIQUE (code),
    CONSTRAINT uq_dept_name UNIQUE (name)
);
CREATE TRIGGER trg_departments_updated_at BEFORE UPDATE ON departments
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

COMMENT ON COLUMN departments.code IS 'Short code, e.g. CCS, CON';
COMMENT ON COLUMN departments.name IS 'Official college name';

CREATE TABLE course_departments (
    id            integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    course_name   varchar(200) NOT NULL,
    department_id integer      NOT NULL,
    created_at    timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_cd_course UNIQUE (course_name),
    CONSTRAINT fk_cd_department
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
);
CREATE INDEX idx_cd_department ON course_departments (department_id);
CREATE TRIGGER trg_course_departments_updated_at BEFORE UPDATE ON course_departments
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE department_schedules (
    id                integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    department_id     integer      NOT NULL,
    day_of_week       smallint     NOT NULL,
    start_time        time(0)      NOT NULL DEFAULT '09:00:00',
    end_time          time(0)      NOT NULL DEFAULT '16:00:00',
    slot_minutes      integer      NOT NULL DEFAULT 30,
    capacity_per_slot integer      NOT NULL DEFAULT 1,
    is_active         smallint     NOT NULL DEFAULT 1,
    created_at        timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_ds_dept_dow_start UNIQUE (department_id, day_of_week, start_time),
    CONSTRAINT fk_ds_department
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON UPDATE CASCADE ON DELETE CASCADE
);
CREATE INDEX idx_ds_department ON department_schedules (department_id);
CREATE TRIGGER trg_department_schedules_updated_at BEFORE UPDATE ON department_schedules
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

COMMENT ON COLUMN department_schedules.day_of_week IS '0=Sun..6=Sat';

-- ============================================================
-- 3. Applicants + documents
-- ============================================================
CREATE TABLE applicants (
    id                    integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    user_id               integer      NOT NULL,
    applicant_type        text         NOT NULL
                          CHECK (applicant_type IN ('freshman','transferee','foreign')),
    course_applied        varchar(120) NOT NULL,
    shs_strand            varchar(60)  DEFAULT NULL,
    overall_status        text         NOT NULL DEFAULT 'pending'
                          CHECK (overall_status IN ('pending','documents','submitted','exam','interview','released','withdrawn')),
    school_year           varchar(9)   NOT NULL,
    created_at            timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    withdrawn_at          timestamp(0) NULL DEFAULT NULL,
    withdrawn_reason      text         NULL DEFAULT NULL,
    documents_approved_at timestamp(0) NULL DEFAULT NULL,
    doc_flags             jsonb        NOT NULL DEFAULT '{}'::jsonb,
    CONSTRAINT fk_applicants_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE INDEX idx_applicants_user_id        ON applicants (user_id);
CREATE INDEX idx_applicants_school_year    ON applicants (school_year);
CREATE INDEX idx_applicants_overall_status ON applicants (overall_status);
CREATE INDEX idx_applicants_withdrawn_at   ON applicants (withdrawn_at);
CREATE INDEX idx_docs_approved_at          ON applicants (documents_approved_at);
CREATE TRIGGER trg_applicants_updated_at BEFORE UPDATE ON applicants
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

COMMENT ON COLUMN applicants.shs_strand IS 'SHS strand key (freshmen only)';
COMMENT ON COLUMN applicants.overall_status IS 'Current stage; withdrawn = applicant voluntarily withdrew';
COMMENT ON COLUMN applicants.school_year IS 'e.g. 2024-2025';
COMMENT ON COLUMN applicants.withdrawn_at IS 'Timestamp of withdrawal submission';
COMMENT ON COLUMN applicants.withdrawn_reason IS 'Optional reason given by applicant for withdrawal';
COMMENT ON COLUMN applicants.documents_approved_at IS 'Set when last required document is approved; used for FCFS exam-slot allocation';
COMMENT ON COLUMN applicants.doc_flags IS 'Conditional document ticks, e.g. {"married":true,"guardian":false,"grade12":true,"shs_grad":false}';

CREATE TABLE documents (
    id            integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    applicant_id  integer      NOT NULL,
    doc_type      varchar(80)  NOT NULL,
    file_path     varchar(500) DEFAULT NULL,
    status        text         NOT NULL DEFAULT 'pending'
                  CHECK (status IN ('pending','uploaded','under_review','approved','rejected')),
    staff_remarks text         DEFAULT NULL,
    reviewed_by   integer      DEFAULT NULL,
    updated_at    timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_documents_applicant
        FOREIGN KEY (applicant_id) REFERENCES applicants (id) ON DELETE CASCADE,
    CONSTRAINT fk_documents_reviewer
        FOREIGN KEY (reviewed_by)  REFERENCES users      (id) ON DELETE SET NULL
);
CREATE INDEX idx_documents_applicant_id ON documents (applicant_id);
CREATE INDEX idx_documents_status       ON documents (status);
CREATE INDEX idx_documents_reviewed_by  ON documents (reviewed_by);
CREATE TRIGGER trg_documents_updated_at BEFORE UPDATE ON documents
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

COMMENT ON COLUMN documents.doc_type IS 'slug key e.g. psa_birth_cert';

-- ============================================================
-- 4. Exams: definition, sections, questions, results, schedule
-- ============================================================
-- exams describes only the content of the entrance exam:
-- title, sections, questions, passing score. The schedule, duration,
-- and access code all live on each room slot (exam_slot_schedule).
CREATE TABLE exams (
    id                integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    title             varchar(160) NOT NULL,
    description       text         DEFAULT NULL,
    passing_score     smallint     DEFAULT NULL,
    shuffle_questions smallint     NOT NULL DEFAULT 0,
    shuffle_choices   smallint     NOT NULL DEFAULT 0,
    is_active         smallint     NOT NULL DEFAULT 0,
    created_at        timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE exam_sections (
    id            integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    exam_id       integer      NOT NULL,
    title         varchar(255) NOT NULL,
    description   text         DEFAULT NULL,
    question_type varchar(50)  NOT NULL DEFAULT 'multiple_choice',
    sort_order    integer      NOT NULL DEFAULT 0,
    CONSTRAINT fk_sections_exam
        FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE
);
CREATE INDEX idx_exam_sections_exam_id ON exam_sections (exam_id);

CREATE TABLE questions (
    id              integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    exam_id         integer      NOT NULL,
    question_text   text         NOT NULL,
    question_type   text         NOT NULL DEFAULT 'multiple_choice'
                    CHECK (question_type IN ('multiple_choice','checkboxes','short_answer','paragraph','linear_scale','dropdown')),
    description     text         DEFAULT NULL,
    points          smallint     NOT NULL DEFAULT 1,
    is_required     smallint     NOT NULL DEFAULT 1,
    choices         text         DEFAULT NULL,
    correct_index   smallint     DEFAULT NULL,
    correct_answer  text         DEFAULT NULL,
    scale_min       smallint     NOT NULL DEFAULT 1,
    scale_max       smallint     NOT NULL DEFAULT 5,
    scale_min_label varchar(80)  DEFAULT NULL,
    scale_max_label varchar(80)  DEFAULT NULL,
    sort_order      smallint     NOT NULL DEFAULT 0,
    section_id      integer      DEFAULT NULL,
    CONSTRAINT fk_questions_exam
        FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE
);
CREATE INDEX idx_questions_exam_id ON questions (exam_id);

COMMENT ON COLUMN questions.choices IS 'JSON array of choice strings';
COMMENT ON COLUMN questions.correct_index IS '0-based index into choices';

CREATE TABLE exam_results (
    id           integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    applicant_id integer      NOT NULL,
    exam_id      integer      NOT NULL,
    score        smallint     NOT NULL DEFAULT 0,
    total_items  smallint     NOT NULL DEFAULT 0,
    rank_score   smallint     NOT NULL DEFAULT 0,
    passed       smallint     NOT NULL DEFAULT 0,
    answers      text         DEFAULT NULL,
    submitted_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_examresults_applicant
        FOREIGN KEY (applicant_id) REFERENCES applicants (id) ON DELETE CASCADE,
    CONSTRAINT fk_examresults_exam
        FOREIGN KEY (exam_id)      REFERENCES exams      (id) ON DELETE CASCADE
);
CREATE INDEX idx_exam_results_applicant_id ON exam_results (applicant_id);
CREATE INDEX idx_exam_results_exam_id      ON exam_results (exam_id);

COMMENT ON COLUMN exam_results.rank_score IS '1-10 ranking based on percentage';
COMMENT ON COLUMN exam_results.passed IS '1=passed threshold for applied course';
COMMENT ON COLUMN exam_results.answers IS 'JSON array of chosen indices per question';

-- Exam-day room scheduling (auto-assigns 35 applicants per room per slot).
--
-- Each row = one physical room running one session at a specific date/time.
-- Owns its own open/close window so each room can run on its own clock,
-- and its own access code so each room's proctor can generate / regenerate
-- independently without affecting other rooms. The exam content table
-- (exams) no longer carries duration: the slot's slot_time and end_time
-- together define the exam window. Late cutoff is the global
-- school-setting exam_late_cutoff_minutes (default 15).
CREATE TABLE exam_slot_schedule (
    id                 integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    exam_id            integer      DEFAULT NULL,
    exam_date          date         NOT NULL,
    slot_time          time(0)      NOT NULL DEFAULT '08:00:00',
    end_time           time(0)      NOT NULL DEFAULT '09:30:00',
    room_label         varchar(80)  NOT NULL DEFAULT '',
    department         varchar(120) NOT NULL DEFAULT '',
    capacity           smallint     NOT NULL DEFAULT 35,
    filled             smallint     NOT NULL DEFAULT 0,
    access_password    varchar(64)  DEFAULT NULL,
    password_issued_at timestamp(0) DEFAULT NULL,
    school_year        varchar(9)   NOT NULL,
    created_by         integer      NOT NULL,
    created_at         timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ess_exam    FOREIGN KEY (exam_id)    REFERENCES exams (id) ON DELETE SET NULL,
    CONSTRAINT fk_ess_creator FOREIGN KEY (created_by) REFERENCES users (id)
);
CREATE INDEX idx_ess_date ON exam_slot_schedule (exam_date);
CREATE INDEX idx_ess_year ON exam_slot_schedule (school_year);

COMMENT ON COLUMN exam_slot_schedule.exam_id IS 'FK to exams; NULL = any active exam';
COMMENT ON COLUMN exam_slot_schedule.slot_time IS 'When this slot opens (room start time)';
COMMENT ON COLUMN exam_slot_schedule.end_time IS 'When this slot closes; duration = end_time - slot_time';
COMMENT ON COLUMN exam_slot_schedule.department IS 'College/department this slot is for';
COMMENT ON COLUMN exam_slot_schedule.access_password IS 'Per-room access code; valid 5 min after password_issued_at';
COMMENT ON COLUMN exam_slot_schedule.password_issued_at IS 'When the room proctor last generated the code';

CREATE TABLE applicant_exam_slots (
    id           integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    applicant_id integer      NOT NULL,
    slot_id      integer      NOT NULL,
    assigned_at  timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_aes_applicant UNIQUE (applicant_id),
    CONSTRAINT fk_aes_applicant FOREIGN KEY (applicant_id) REFERENCES applicants         (id) ON DELETE CASCADE,
    CONSTRAINT fk_aes_slot      FOREIGN KEY (slot_id)      REFERENCES exam_slot_schedule (id) ON DELETE CASCADE
);
CREATE INDEX idx_aes_slot ON applicant_exam_slots (slot_id);

-- ============================================================
-- 5. Interview sessions (merged: time slot + assigned interviewer
--    + location label/notes; replaces the old interview_desks table)
-- ============================================================
CREATE TABLE interview_slots (
    id             integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    slot_date      date         NOT NULL,
    slot_time      time(0)      DEFAULT NULL,
    end_time       time(0)      DEFAULT NULL,
    capacity       smallint     NOT NULL DEFAULT 30,
    department     varchar(120) NOT NULL DEFAULT '',
    status         text         NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
    created_by     integer      NOT NULL,
    assigned_to    integer      DEFAULT NULL,
    location_label varchar(120) NOT NULL DEFAULT '',
    location_notes text         DEFAULT NULL,
    created_at     timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_slots_creator
        FOREIGN KEY (created_by)  REFERENCES users (id),
    CONSTRAINT fk_slots_assigned
        FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL
);
CREATE INDEX idx_slot_date          ON interview_slots (slot_date);
CREATE INDEX idx_slots_created_by   ON interview_slots (created_by);
CREATE INDEX idx_slots_assigned_to  ON interview_slots (assigned_to);
CREATE INDEX idx_slots_department   ON interview_slots (department);

COMMENT ON COLUMN interview_slots.department IS 'College this session serves (see departments.name)';
COMMENT ON COLUMN interview_slots.assigned_to IS 'Staff member running this session';
COMMENT ON COLUMN interview_slots.location_label IS 'Visible location/desk label, e.g. "Room 201"';
COMMENT ON COLUMN interview_slots.location_notes IS 'Directions for the student, e.g. "2nd floor, turn left"';

CREATE TABLE interview_queue (
    id                integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    slot_id           integer      NOT NULL,
    applicant_id      integer      NOT NULL,
    queue_number      integer      DEFAULT NULL,
    status            text         NOT NULL DEFAULT 'scheduled'
                      CHECK (status IN ('scheduled','checked_in','in_progress','completed','no_show')),
    checkin_code      varchar(20)  DEFAULT NULL,
    checked_in_at     timestamp(0) DEFAULT NULL,
    interview_notes   text         DEFAULT NULL,
    attendance_status text         NULL DEFAULT NULL
                      CHECK (attendance_status IN ('present','absent')),
    evaluation_result text         NULL DEFAULT NULL
                      CHECK (evaluation_result IN ('pass','reject')),
    interview_status  text         NOT NULL DEFAULT 'pending'
                      CHECK (interview_status IN ('pending','completed','absent','rescheduled')),
    evaluated_by      integer      NULL DEFAULT NULL,
    evaluated_at      timestamp(0) NULL DEFAULT NULL,
    created_at        timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_applicant_active UNIQUE (applicant_id),
    CONSTRAINT uq_checkin_code     UNIQUE (checkin_code),
    CONSTRAINT fk_iq_slot      FOREIGN KEY (slot_id)      REFERENCES interview_slots (id) ON DELETE CASCADE,
    CONSTRAINT fk_iq_applicant FOREIGN KEY (applicant_id) REFERENCES applicants       (id) ON DELETE CASCADE
);
CREATE INDEX idx_iq_applicant        ON interview_queue (applicant_id);
CREATE INDEX idx_iq_slot             ON interview_queue (slot_id);
CREATE INDEX idx_iq_interview_status ON interview_queue (interview_status);
CREATE INDEX idx_iq_attendance       ON interview_queue (attendance_status);

COMMENT ON COLUMN interview_queue.attendance_status IS 'Filled in by staff at interview time';
COMMENT ON COLUMN interview_queue.evaluation_result IS 'Only meaningful when attendance_status = present';
COMMENT ON COLUMN interview_queue.interview_status IS 'End-to-end lifecycle state';

CREATE TABLE reschedule_logs (
    id             integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    applicant_id   integer      NOT NULL,
    from_slot_id   integer      NULL,
    to_slot_id     integer      NULL,
    from_slot_date date         NULL,
    from_slot_time time(0)      NULL,
    reason         varchar(255) NOT NULL DEFAULT 'absent',
    rescheduled_by integer      NULL,
    rescheduled_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rl_applicant
        FOREIGN KEY (applicant_id) REFERENCES applicants      (id) ON DELETE CASCADE,
    CONSTRAINT fk_rl_from_slot
        FOREIGN KEY (from_slot_id) REFERENCES interview_slots (id) ON DELETE SET NULL,
    CONSTRAINT fk_rl_to_slot
        FOREIGN KEY (to_slot_id)   REFERENCES interview_slots (id) ON DELETE SET NULL
);
CREATE INDEX idx_rl_applicant ON reschedule_logs (applicant_id);
CREATE INDEX idx_rl_from_slot ON reschedule_logs (from_slot_id);
CREATE INDEX idx_rl_to_slot   ON reschedule_logs (to_slot_id);

-- ============================================================
-- 6. Admission results, intent, course suggestions
-- ============================================================
CREATE TABLE admission_results (
    id                     integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    applicant_id           integer      NOT NULL,
    result                 text         NOT NULL CHECK (result IN ('accepted','waitlisted','rejected')),
    enrollment_intent      text         DEFAULT NULL CHECK (enrollment_intent IN ('confirmed','declined')),
    intent_deadline        date         DEFAULT NULL,
    intent_submitted_at    timestamp(0) DEFAULT NULL,
    promoted_from_waitlist smallint     NOT NULL DEFAULT 0,
    remarks                text         DEFAULT NULL,
    released_by            integer      NOT NULL,
    released_at            timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_applicant_result UNIQUE (applicant_id),
    CONSTRAINT fk_results_applicant  FOREIGN KEY (applicant_id) REFERENCES applicants (id) ON DELETE CASCADE,
    CONSTRAINT fk_results_releasedby FOREIGN KEY (released_by)  REFERENCES users      (id)
);
CREATE INDEX idx_admission_results_result      ON admission_results (result);
CREATE INDEX idx_admission_results_released_by ON admission_results (released_by);
CREATE INDEX idx_enrollment_intent             ON admission_results (enrollment_intent);

COMMENT ON COLUMN admission_results.enrollment_intent IS 'Student-submitted intent after acceptance';
COMMENT ON COLUMN admission_results.intent_deadline IS 'Optional date by which the student must respond';
COMMENT ON COLUMN admission_results.intent_submitted_at IS 'When the student submitted their enrollment intent';
COMMENT ON COLUMN admission_results.promoted_from_waitlist IS '1 = this row was auto-promoted from waitlist after a decline';

CREATE TABLE course_suggestions (
    id               integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    applicant_id     integer      NOT NULL,
    original_course  varchar(200) NOT NULL,
    suggested_course varchar(200) NOT NULL,
    suggested_by     integer      NOT NULL,
    note             text         DEFAULT NULL,
    status           text         NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending','accepted','declined')),
    created_at       timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_cs_applicant UNIQUE (applicant_id),
    CONSTRAINT fk_cs_applicant FOREIGN KEY (applicant_id) REFERENCES applicants (id) ON DELETE CASCADE,
    CONSTRAINT fk_cs_staff     FOREIGN KEY (suggested_by) REFERENCES users      (id)
);
CREATE INDEX idx_cs_status ON course_suggestions (status);
CREATE TRIGGER trg_course_suggestions_updated_at BEFORE UPDATE ON course_suggestions
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ============================================================
-- 7. Per-course capacity caps and pass-thresholds
-- ============================================================
CREATE TABLE course_caps (
    id          integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    course_name varchar(200) NOT NULL,
    school_year varchar(9)   NOT NULL,
    max_slots   integer      DEFAULT NULL,
    updated_at  timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_cc_course_year UNIQUE (course_name, school_year)
);
CREATE TRIGGER trg_course_caps_updated_at BEFORE UPDATE ON course_caps
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

COMMENT ON COLUMN course_caps.max_slots IS 'NULL = unlimited';

CREATE TABLE course_passing_scores (
    id          integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    course_name varchar(200) NOT NULL,
    pass_from   smallint     NOT NULL DEFAULT 4,
    high_from   smallint     NOT NULL DEFAULT 7,
    avg_from    smallint     NOT NULL DEFAULT 4,
    confirmed   smallint     NOT NULL DEFAULT 0,
    updated_at  timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_cps_course UNIQUE (course_name)
);
CREATE TRIGGER trg_course_passing_scores_updated_at BEFORE UPDATE ON course_passing_scores
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

COMMENT ON COLUMN course_passing_scores.pass_from IS 'Minimum rank to pass (1-10)';
COMMENT ON COLUMN course_passing_scores.high_from IS 'Minimum rank for "high" tier qualification (1-10)';
COMMENT ON COLUMN course_passing_scores.avg_from IS 'Minimum rank for "average" tier qualification (1-10)';

CREATE TABLE custom_courses (
    id          integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    course_name varchar(200) NOT NULL,
    strands     text         NOT NULL DEFAULT '[]',
    pass_from   smallint     NOT NULL DEFAULT 4,
    is_active   smallint     NOT NULL DEFAULT 1,
    created_by  integer      DEFAULT NULL,
    created_at  timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_cc_name UNIQUE (course_name),
    CONSTRAINT fk_cc_user
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
);
CREATE INDEX idx_cc_active ON custom_courses (is_active);
CREATE TRIGGER trg_custom_courses_updated_at BEFORE UPDATE ON custom_courses
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

COMMENT ON COLUMN custom_courses.strands IS 'JSON array of accepted SHS strand keys, e.g. ["STEM","ABM"]';
COMMENT ON COLUMN custom_courses.pass_from IS 'Minimum rank score (1-10) required to pass this course';

-- ============================================================
-- 8. Settings, audit, sessions, password resets
-- ============================================================
CREATE TABLE school_settings (
    id            integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    setting_key   varchar(80)  NOT NULL,
    setting_value text         DEFAULT NULL,
    updated_at    timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_setting_key UNIQUE (setting_key)
);
CREATE TRIGGER trg_school_settings_updated_at BEFORE UPDATE ON school_settings
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE audit_logs (
    id          bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    user_id     integer      DEFAULT NULL,
    user_name   varchar(150) NOT NULL DEFAULT '',
    user_role   varchar(20)  NOT NULL DEFAULT '',
    action      varchar(80)  NOT NULL,
    description text         DEFAULT NULL,
    entity_type varchar(60)  DEFAULT NULL,
    entity_id   integer      DEFAULT NULL,
    ip_address  varchar(45)  DEFAULT NULL,
    created_at  timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_audit_logs_user_id    ON audit_logs (user_id);
CREATE INDEX idx_audit_logs_action     ON audit_logs (action);
CREATE INDEX idx_audit_logs_created_at ON audit_logs (created_at);

CREATE TABLE password_resets (
    id         integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    user_id    integer      NOT NULL,
    token      varchar(64)  NOT NULL,
    expires_at timestamp(0) NOT NULL,
    used       smallint     NOT NULL DEFAULT 0,
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_resets_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE INDEX idx_password_resets_token   ON password_resets (token);
CREATE INDEX idx_password_resets_user_id ON password_resets (user_id);

-- DB-backed session store (used in serverless environments).
CREATE TABLE sessions (
    id            varchar(128) PRIMARY KEY,
    payload       text         NOT NULL,
    last_activity bigint       NOT NULL
);
CREATE INDEX idx_sessions_last_activity ON sessions (last_activity);

-- ============================================================
-- 8b. Notifications (in-app)
-- ============================================================
CREATE TABLE notifications (
    id         bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    user_id    integer      NOT NULL,
    type       varchar(80)  NOT NULL,
    title      varchar(255) NOT NULL,
    message    text         DEFAULT NULL,
    link       varchar(500) DEFAULT NULL,
    is_read    smallint     NOT NULL DEFAULT 0,
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE INDEX idx_notif_user    ON notifications (user_id, is_read);
CREATE INDEX idx_notif_created ON notifications (created_at);

COMMENT ON COLUMN notifications.type IS 'e.g. docs_approved, exam_slot_assigned, result_released';
COMMENT ON COLUMN notifications.link IS 'URL path to navigate to when clicked';

-- ============================================================
-- 8c. Document validation logs (OCR / AI results)
-- ============================================================
CREATE TABLE document_validations (
    id              bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    document_id     integer      NOT NULL,
    validation_type text         NOT NULL DEFAULT 'file_check'
                    CHECK (validation_type IN ('ocr','ai','file_check')),
    status          text         NOT NULL CHECK (status IN ('passed','failed','uncertain')),
    confidence      numeric(5,2) DEFAULT NULL,
    details         text         DEFAULT NULL,
    validated_at    timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    review_result   text         DEFAULT NULL
                    CHECK (review_result IN ('approved','corrected')),
    reviewed_by     integer      DEFAULT NULL,
    reviewed_at     timestamp(0) NULL DEFAULT NULL,
    CONSTRAINT fk_dv_document
        FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
    CONSTRAINT fk_dv_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
);
CREATE INDEX idx_dv_document ON document_validations (document_id);

COMMENT ON COLUMN document_validations.confidence IS 'Confidence score 0-100';
COMMENT ON COLUMN document_validations.details IS 'JSON details of validation result';
COMMENT ON COLUMN document_validations.review_result IS 'Staff review of an AI flag: approved = category OK, corrected = type changed';

-- ============================================================
-- 8d. Tables the old PHP code created at runtime
--     (moved here so the app no longer alters the schema)
-- ============================================================

-- Login rate limiting
CREATE TABLE login_attempts (
    id           bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    email        citext       NOT NULL,
    ip_address   varchar(45)  NOT NULL,
    attempted_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_la_email ON login_attempts (email, attempted_at);

-- Exam auto-save drafts
CREATE TABLE exam_drafts (
    id           bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    applicant_id integer      NOT NULL,
    exam_id      integer      NOT NULL,
    answers      text         NOT NULL,
    saved_at     timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_draft UNIQUE (applicant_id, exam_id)
);
CREATE TRIGGER trg_exam_drafts_saved_at BEFORE UPDATE ON exam_drafts
    FOR EACH ROW EXECUTE FUNCTION set_saved_at();

-- Interview reschedule requests
CREATE TABLE reschedule_requests (
    id           bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    applicant_id integer      NOT NULL,
    queue_id     bigint       NOT NULL,
    reason       text         NOT NULL,
    status       text         NOT NULL DEFAULT 'pending'
                 CHECK (status IN ('pending','approved','denied')),
    reviewed_by  integer      DEFAULT NULL,
    reviewed_at  timestamp(0) DEFAULT NULL,
    deny_reason  text         DEFAULT NULL,
    created_at   timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_rr_applicant ON reschedule_requests (applicant_id);

-- Exam reschedule requests
CREATE TABLE exam_reschedule_requests (
    id           bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    applicant_id integer      NOT NULL,
    slot_id      integer      NOT NULL,
    reason       text         NOT NULL,
    status       text         NOT NULL DEFAULT 'pending'
                 CHECK (status IN ('pending','approved','denied')),
    reviewed_by  integer      DEFAULT NULL,
    reviewed_at  timestamp(0) DEFAULT NULL,
    deny_reason  text         DEFAULT NULL,
    created_at   timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_err_applicant ON exam_reschedule_requests (applicant_id);

-- ============================================================
-- 8e. Row Level Security: on for every table, no policies.
--     The app uses the postgres role (bypasses RLS). The public
--     Supabase API keys get no rows.
-- ============================================================
ALTER TABLE admission_results        ENABLE ROW LEVEL SECURITY;
ALTER TABLE applicant_exam_slots     ENABLE ROW LEVEL SECURITY;
ALTER TABLE applicants               ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs               ENABLE ROW LEVEL SECURITY;
ALTER TABLE course_caps              ENABLE ROW LEVEL SECURITY;
ALTER TABLE course_departments       ENABLE ROW LEVEL SECURITY;
ALTER TABLE course_passing_scores    ENABLE ROW LEVEL SECURITY;
ALTER TABLE course_suggestions       ENABLE ROW LEVEL SECURITY;
ALTER TABLE custom_courses           ENABLE ROW LEVEL SECURITY;
ALTER TABLE department_schedules     ENABLE ROW LEVEL SECURITY;
ALTER TABLE departments              ENABLE ROW LEVEL SECURITY;
ALTER TABLE document_validations     ENABLE ROW LEVEL SECURITY;
ALTER TABLE documents                ENABLE ROW LEVEL SECURITY;
ALTER TABLE exam_drafts              ENABLE ROW LEVEL SECURITY;
ALTER TABLE exam_reschedule_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE exam_results             ENABLE ROW LEVEL SECURITY;
ALTER TABLE exam_sections            ENABLE ROW LEVEL SECURITY;
ALTER TABLE exam_slot_schedule       ENABLE ROW LEVEL SECURITY;
ALTER TABLE exams                    ENABLE ROW LEVEL SECURITY;
ALTER TABLE interview_queue          ENABLE ROW LEVEL SECURITY;
ALTER TABLE interview_slots          ENABLE ROW LEVEL SECURITY;
ALTER TABLE login_attempts           ENABLE ROW LEVEL SECURITY;
ALTER TABLE notifications            ENABLE ROW LEVEL SECURITY;
ALTER TABLE password_resets          ENABLE ROW LEVEL SECURITY;
ALTER TABLE questions                ENABLE ROW LEVEL SECURITY;
ALTER TABLE reschedule_logs          ENABLE ROW LEVEL SECURITY;
ALTER TABLE reschedule_requests      ENABLE ROW LEVEL SECURITY;
ALTER TABLE school_settings          ENABLE ROW LEVEL SECURITY;
ALTER TABLE sessions                 ENABLE ROW LEVEL SECURITY;
ALTER TABLE users                    ENABLE ROW LEVEL SECURITY;

-- ============================================================
-- 9. Seed data
-- ============================================================

-- 9a. Default school settings
INSERT INTO school_settings (setting_key, setting_value) VALUES
    ('school_name',           'Pamantasan ng Lungsod ng Pasig'),
    ('school_logo',           ''),
    ('accent_color',          '#2d6a4f'),
    ('current_school_year',   '2026-2027'),
    ('admissions_open',       ''),
    ('admissions_close',      ''),
    ('document_deadline',     ''),

    ('system_version',          '1.0.0'),
    ('exam_default_duration',   '90'),
    ('exam_room_capacity',      '35'),
    ('exam_daily_cap',          '3000'),
    ('exam_late_cutoff_minutes','15'),

    ('auto_validate_documents', '1'),
    ('ai_confidence_threshold', '80'),
    ('auto_assign_exam_slots',  '1'),
    ('auto_promote_waitlist',   '1'),
    ('auto_reschedule_noshows', '1'),
    ('auto_release_results',    '0'),
    ('idle_applicant_days',     '7'),
    ('doc_reminder_days',       '3'),
    ('acceptance_deadline_days','7');

-- 9b. Default admin account
-- Email:    admin@plp.edu.ph
-- Password: Admin@PLP2024  (CHANGE IMMEDIATELY AFTER FIRST LOGIN)
-- email_verified = 1 because the old app marked all seeded users as
-- verified the first time it added that column.
INSERT INTO users (name, first_name, last_name, email, password_hash, role, email_verified) VALUES
    ('System Administrator', 'System', 'Administrator', 'admin@plp.edu.ph',
     '$2y$12$Z.nk6UtugYH1P4RsIwqEjuLpRoYFxNoIOV9cIiSa8VfU6j2lbuKES',
     'admin', 1);

-- 9c. Departments (one row per college)
INSERT INTO departments (code, name) VALUES
    ('CCS', 'College of Computer Studies'),
    ('CON', 'College of Nursing'),
    ('CBA', 'College of Business and Accountancy'),
    ('COE', 'College of Education'),
    ('CAS', 'College of Arts and Sciences'),
    ('CEN', 'College of Engineering');

-- 9d. Course to department mapping (covers all courses in PLP_COURSES)
INSERT INTO course_departments (course_name, department_id) VALUES
    ('BS Information Technology (BSIT)',                                       (SELECT id FROM departments WHERE code = 'CCS')),
    ('BS Computer Science (BSCS)',                                             (SELECT id FROM departments WHERE code = 'CCS')),
    ('BS Nursing (BSN)',                                                       (SELECT id FROM departments WHERE code = 'CON')),
    ('BS Accountancy (BSA)',                                                   (SELECT id FROM departments WHERE code = 'CBA')),
    ('BS Business Administration major in Marketing Management (BSBA)',        (SELECT id FROM departments WHERE code = 'CBA')),
    ('BS Entrepreneurship (BSENT)',                                            (SELECT id FROM departments WHERE code = 'CBA')),
    ('BS Hospitality Management (BSHM)',                                       (SELECT id FROM departments WHERE code = 'CBA')),
    ('Bachelor of Elementary Education (BEED)',                                (SELECT id FROM departments WHERE code = 'COE')),
    ('Bachelor of Secondary Education Major in English (BSED-ENG)',            (SELECT id FROM departments WHERE code = 'COE')),
    ('Bachelor of Secondary Education Major in Filipino (BSED-FIL)',           (SELECT id FROM departments WHERE code = 'COE')),
    ('Bachelor of Secondary Education Major in Mathematics (BSED-MATH)',       (SELECT id FROM departments WHERE code = 'COE')),
    ('AB Psychology (AB Psych)',                                               (SELECT id FROM departments WHERE code = 'CAS')),
    ('BS Electronics Engineering (BSECE)',                                     (SELECT id FROM departments WHERE code = 'CEN'));

-- 9e. Department schedules: Mon-Fri 09:00-16:00, 30-min slots, capacity 1
INSERT INTO department_schedules
    (department_id, day_of_week, start_time, end_time, slot_minutes, capacity_per_slot)
SELECT d.id, v.dow, TIME '09:00:00', TIME '16:00:00', 30, 1
FROM departments d,
     (SELECT 1 AS dow UNION ALL SELECT 2 UNION ALL SELECT 3
      UNION ALL SELECT 4 UNION ALL SELECT 5) v;

-- 9f. Default per-course passing scores (BSIT confirmed; rest are tentative)
INSERT INTO course_passing_scores (course_name, pass_from, high_from, avg_from, confirmed) VALUES
    ('BS Accountancy (BSA)',                                              4, 7, 4, 0),
    ('BS Business Administration major in Marketing Management (BSBA)',  4, 7, 4, 0),
    ('BS Entrepreneurship (BSENT)',                                       4, 7, 4, 0),
    ('BS Hospitality Management (BSHM)',                                  4, 7, 4, 0),
    ('Bachelor of Elementary Education (BEED)',                           4, 7, 4, 0),
    ('Bachelor of Secondary Education Major in English (BSED-ENG)',      4, 7, 4, 0),
    ('Bachelor of Secondary Education Major in Filipino (BSED-FIL)',     4, 7, 4, 0),
    ('Bachelor of Secondary Education Major in Mathematics (BSED-MATH)', 4, 7, 4, 0),
    ('AB Psychology (AB Psych)',                                          4, 7, 4, 0),
    ('BS Computer Science (BSCS)',                                        4, 7, 4, 0),
    ('BS Information Technology (BSIT)',                                  4, 7, 4, 1),
    ('BS Electronics Engineering (BSECE)',                                4, 7, 4, 0),
    ('BS Nursing (BSN)',                                                   4, 7, 4, 0);

-- ============================================================
-- Done. Database is ready to use.
-- ============================================================
