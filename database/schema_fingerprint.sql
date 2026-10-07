-- ============================================================
-- database/schema_fingerprint.sql
--
-- Prints one line per schema object in the public schema, sorted.
-- CI runs it against two throwaway databases and diffs the output:
--
--   A. schema.sql only
--   B. schema.sql, then every file in database/migrations/
--
-- If A and B differ, a migration changes something that schema.sql
-- does not, so a fresh setup would be missing it. Fix schema.sql.
--
-- Run with: psql -X -t -A -f database/schema_fingerprint.sql
--
-- Compared by name, not by column position, so a column added by a
-- migration (always last) matches the same column placed mid-table
-- in schema.sql. The schema_migrations bookkeeping table is skipped.
-- ============================================================
SELECT line
FROM (
    -- Tables, with the Row Level Security flag
    SELECT 'table ' || c.relname
           || ' rls=' || c.relrowsecurity::text AS line
    FROM pg_class c
    WHERE c.relnamespace = 'public'::regnamespace
      AND c.relkind = 'r'
      AND c.relname <> 'schema_migrations'

    UNION ALL

    -- Columns: type, nullability, default, identity
    SELECT 'column ' || c.relname || '.' || a.attname
           || ' ' || format_type(a.atttypid, a.atttypmod)
           || CASE WHEN a.attnotnull THEN ' NOT NULL' ELSE '' END
           || CASE WHEN a.attidentity <> '' THEN ' IDENTITY ' || a.attidentity::text ELSE '' END
           || coalesce(' DEFAULT ' || pg_get_expr(d.adbin, d.adrelid), '')
    FROM pg_attribute a
    JOIN pg_class c ON c.oid = a.attrelid
    LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
    WHERE c.relnamespace = 'public'::regnamespace
      AND c.relkind = 'r'
      AND c.relname <> 'schema_migrations'
      AND a.attnum > 0
      AND NOT a.attisdropped

    UNION ALL

    -- Primary keys, uniques, foreign keys, checks
    SELECT 'constraint ' || c.relname || ' ' || k.conname
           || ' ' || pg_get_constraintdef(k.oid)
    FROM pg_constraint k
    JOIN pg_class c ON c.oid = k.conrelid
    WHERE k.connamespace = 'public'::regnamespace
      AND c.relname <> 'schema_migrations'

    UNION ALL

    -- Indexes
    SELECT 'index ' || i.indexdef
    FROM pg_indexes i
    WHERE i.schemaname = 'public'
      AND i.tablename <> 'schema_migrations'

    UNION ALL

    -- Triggers
    SELECT 'trigger ' || pg_get_triggerdef(t.oid)
    FROM pg_trigger t
    JOIN pg_class c ON c.oid = t.tgrelid
    WHERE c.relnamespace = 'public'::regnamespace
      AND NOT t.tgisinternal
      AND c.relname <> 'schema_migrations'

    UNION ALL

    -- Our own functions (skip the ones the citext extension installs)
    SELECT 'function ' || pg_get_functiondef(p.oid)
    FROM pg_proc p
    WHERE p.pronamespace = 'public'::regnamespace
      AND NOT EXISTS (
          SELECT 1 FROM pg_depend dep
          WHERE dep.objid = p.oid AND dep.deptype = 'e'
      )
) objects
ORDER BY line COLLATE "C";
