#!/usr/bin/env bash
# ============================================================
# database/migrate.sh
#
# Applies the SQL files in database/migrations/ to a PostgreSQL
# database, oldest first, skipping the ones already applied.
# Applied files are recorded in the schema_migrations table.
#
# Usage:
#   bash database/migrate.sh             apply pending migrations
#   bash database/migrate.sh --dry-run   list pending migrations only
#
# Connection comes from the standard libpq variables:
#   PGHOST, PGPORT, PGUSER, PGPASSWORD, PGDATABASE, PGSSLMODE
#
# CI runs this against the Supabase dev project after a merge to
# main (.github/workflows/migrate.yml). Do not point it at the
# shared Supabase database from your own machine; use a local
# Postgres or your own Supabase project to try a migration out.
# ============================================================
set -euo pipefail
export LC_ALL=C

DRY_RUN=0
case "${1:-}" in
  "")        ;;
  --dry-run) DRY_RUN=1 ;;
  *)         echo "Usage: bash database/migrate.sh [--dry-run]" >&2; exit 2 ;;
esac

for var in PGHOST PGUSER PGDATABASE; do
  if [ -z "${!var:-}" ]; then
    echo "error: $var is not set" >&2
    exit 2
  fi
done

MIGRATIONS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/migrations"

# -X skips ~/.psqlrc, -w never prompts for a password (fail fast in CI),
# ON_ERROR_STOP makes psql exit non-zero on the first SQL error.
psql_base=(psql -X -w -v ON_ERROR_STOP=1)

# Query helper: bare values, no headers or alignment.
query() { "${psql_base[@]}" -t -A -c "$1"; }

# ------------------------------------------------------------
# Collect and validate the file names first, so a bad name fails
# the run before anything touches the database.
# Format: YYYYMMDDHHMM_short_description.sql
# ------------------------------------------------------------
shopt -s nullglob
files=("$MIGRATIONS_DIR"/*.sql)
shopt -u nullglob

for f in "${files[@]}"; do
  name="$(basename "$f")"
  if ! [[ "$name" =~ ^[0-9]{12}_[a-z0-9_]+\.sql$ ]]; then
    echo "error: bad migration file name: $name" >&2
    echo "       expected YYYYMMDDHHMM_short_description.sql (lowercase, underscores)" >&2
    exit 1
  fi
done

if [ "${#files[@]}" -eq 0 ]; then
  echo "No migration files found. Nothing to do."
  exit 0
fi

# ------------------------------------------------------------
# Bookkeeping table. Row Level Security is on like every other
# table (see schema.sql), so the public Supabase API keys can
# read nothing from it.
# ------------------------------------------------------------
table_exists="$(query "SELECT to_regclass('public.schema_migrations') IS NOT NULL")"

if [ "$table_exists" != "t" ] && [ "$DRY_RUN" -eq 0 ]; then
  "${psql_base[@]}" -q <<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
    filename   text        PRIMARY KEY,
    checksum   text        NOT NULL,
    applied_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE schema_migrations ENABLE ROW LEVEL SECURITY;
SQL
  table_exists="t"
fi

applied=0
skipped=0
pending=0

for f in "${files[@]}"; do
  name="$(basename "$f")"            # safe to inline in SQL: validated above
  sum="$(sha256sum "$f" | cut -d' ' -f1)"

  recorded=""
  if [ "$table_exists" = "t" ]; then
    recorded="$(query "SELECT checksum FROM schema_migrations WHERE filename = '$name'")"
  fi

  if [ -n "$recorded" ]; then
    if [ "$recorded" != "$sum" ]; then
      echo "error: $name was edited after it was applied." >&2
      echo "       Never change a merged migration. Add a new one instead." >&2
      exit 1
    fi
    skipped=$((skipped + 1))
    continue
  fi

  if [ "$DRY_RUN" -eq 1 ]; then
    echo "pending: $name"
    pending=$((pending + 1))
    continue
  fi

  echo "applying: $name"
  # --single-transaction wraps everything below in one BEGIN/COMMIT:
  #   1. a lock timeout, so a migration waiting behind live app queries
  #      fails instead of queueing up and blocking the site
  #   2. the migration file
  #   3. the bookkeeping row
  # If any step fails, all of it rolls back. If two runs race, the
  # second INSERT hits the primary key and rolls back its migration too.
  "${psql_base[@]}" --single-transaction \
    -c "SET LOCAL lock_timeout = '15s'" \
    -f "$f" \
    -c "INSERT INTO schema_migrations (filename, checksum) VALUES ('$name', '$sum')"
  applied=$((applied + 1))
done

if [ "$DRY_RUN" -eq 1 ]; then
  echo "Dry run: $pending pending, $skipped already applied."
else
  echo "Done: $applied applied, $skipped already applied."
fi
