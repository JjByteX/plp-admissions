-- Passport photo is one slot now (photo_1). Drop the second slot's rows
-- that are not approved, so they do not block staff approve-all or advance.
-- Approved photo_2 rows stay (they never block).

DELETE FROM documents
WHERE doc_type = 'photo_2' AND status <> 'approved';
