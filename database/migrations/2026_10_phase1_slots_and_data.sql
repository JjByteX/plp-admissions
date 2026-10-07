-- Phase 1: official document slots + AI columns. One migration, safe to run twice.
-- Apply to the shared dev Supabase once, then to live at release.
-- NEVER run schema.sql on a shared or live database (it drops every table).

BEGIN;

-- 1. Conditional document ticks
ALTER TABLE applicants
    ADD COLUMN IF NOT EXISTS doc_flags jsonb NOT NULL DEFAULT '{}'::jsonb;

-- 2. AI review columns
ALTER TABLE document_validations
    ADD COLUMN IF NOT EXISTS review_result text DEFAULT NULL
        CHECK (review_result IN ('approved','corrected'));
ALTER TABLE document_validations
    ADD COLUMN IF NOT EXISTS reviewed_by integer DEFAULT NULL
        REFERENCES users (id) ON DELETE SET NULL;
ALTER TABLE document_validations
    ADD COLUMN IF NOT EXISTS reviewed_at timestamp(0) NULL DEFAULT NULL;

-- 3. AI threshold seed
INSERT INTO school_settings (setting_key, setting_value)
VALUES ('ai_confidence_threshold', '80')
ON CONFLICT (setting_key) DO NOTHING;

-- 4. Remove rows for slots that no longer exist
DELETE FROM documents
WHERE doc_type IN ('applicant_id','parent_id','proof_of_income','passport_photos','good_moral');

-- 5. Add pending rows for the new always-required slots
--    (conditional slots stay off until the applicant ticks them)
INSERT INTO documents (applicant_id, doc_type, status)
SELECT a.id, s.doc_type, 'pending'
FROM applicants a
CROSS JOIN (VALUES ('psa_birth_cert'),('valid_id_1'),('valid_id_2'),
                   ('barangay_cert'),('photo_1'),('photo_2')) AS s(doc_type)
WHERE NOT EXISTS (
    SELECT 1 FROM documents d WHERE d.applicant_id = a.id AND d.doc_type = s.doc_type
);

-- 6. TOR for transferees and foreign applicants
INSERT INTO documents (applicant_id, doc_type, status)
SELECT a.id, 'tor', 'pending'
FROM applicants a
WHERE a.applicant_type IN ('transferee','foreign')
  AND NOT EXISTS (
    SELECT 1 FROM documents d WHERE d.applicant_id = a.id AND d.doc_type = 'tor'
);

-- 7. Foreign extras
INSERT INTO documents (applicant_id, doc_type, status)
SELECT a.id, s.doc_type, 'pending'
FROM applicants a
CROSS JOIN (VALUES ('passport'),('visa_permit'),('alien_cert')) AS s(doc_type)
WHERE a.applicant_type = 'foreign'
  AND NOT EXISTS (
    SELECT 1 FROM documents d WHERE d.applicant_id = a.id AND d.doc_type = s.doc_type
);

COMMIT;
