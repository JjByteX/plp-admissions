-- The ID is one slot now (valid_id_1): one file showing the front and the back.
-- Drop the second slot's rows that are not approved, so they do not block
-- staff approve-all or advance. Approved valid_id_2 rows stay (they never block).

DELETE FROM documents
WHERE doc_type = 'valid_id_2' AND status <> 'approved';
