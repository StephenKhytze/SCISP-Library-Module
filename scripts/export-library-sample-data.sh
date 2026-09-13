#!/usr/bin/env bash
#
# Export the SAMPLE BOOK CATALOG for sharing through Git.
#
# This is a developer utility, not an application feature. It adds no routes,
# no UI and no schema. It reads the running database and writes two things:
#
#   sample-data/library/library_books.sql   the catalog tables
#   sample-data/library/covers/             only the covers those books use
#
# It exports FOUR tables and nothing else:
#
#   library_categories · books · book_copies · library_counters
#
# It deliberately never touches users, transactions, holds, fines, renewals,
# course sections/reserves, library_settings or anything authentication-related.
#
# The export is READ-ONLY. Nothing in the running database is modified, and the
# runtime cover files are copied, never moved or deleted.
#
# Usage (from anywhere in the repo, Git Bash on Windows or any POSIX shell):
#
#   ./scripts/export-library-sample-data.sh
#
set -euo pipefail

# ---------------------------------------------------------------- locations
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$REPO_ROOT/backend/.env"
OUT_DIR="$REPO_ROOT/sample-data/library"
OUT_SQL="$OUT_DIR/library_books.sql"
COVER_OUT="$OUT_DIR/covers"

# The four tables this script is allowed to export. Adding a table here without
# thinking is how private data leaks into a public sample file — don't.
TABLES=(library_categories books book_copies library_counters)

# Columns that point OUT of the exported set. They are blanked in the export so
# the file imports standalone and carries no user ids. See README "Foreign keys".
declare -a NULL_OUT=(
  "UPDATE book_copies SET reserve_id = NULL;|book_copies.reserve_id -> course_reserves"
  "UPDATE books SET archived_by = NULL;|books.archived_by -> users"
  "UPDATE library_categories SET created_by = NULL;|library_categories.created_by -> users"
  # A copy cannot be out on loan or held for someone when no transactions and no
  # holds are exported — it would report wrong availability. 'lost' and 'damaged'
  # are kept: those describe the copy itself, not a borrower.
  "UPDATE book_copies SET availability_status = 'available' WHERE availability_status IN ('checked_out','on_hold');|book_copies.availability_status -> transactions/holds"
)

say() { printf '%s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

# ------------------------------------------------------------------- config
# Credentials come from backend/.env so nothing is hardcoded here or in the
# committed documentation. The password is never printed.
[ -f "$ENV_FILE" ] || die "backend/.env not found at $ENV_FILE"

env_value() {
  # Last non-commented assignment wins, quotes and CRLF stripped.
  sed -n "s/^${1}=//p" "$ENV_FILE" | tail -n 1 | tr -d '\r' | sed 's/^"//; s/"$//; s/^'"'"'//; s/'"'"'$//'
}

DB_NAME="$(env_value DB_DATABASE)"
DB_USER="$(env_value DB_USERNAME)"
DB_PASS="$(env_value DB_PASSWORD)"

[ -n "$DB_NAME" ] || die "DB_DATABASE is empty in backend/.env"
[ -n "$DB_USER" ] || die "DB_USERNAME is empty in backend/.env"

# ---------------------------------------------------------------- container
# Prefer the compose container name; fall back to whatever mysql container runs.
DB_CONTAINER="${SCISP_DB_CONTAINER:-}"
if [ -z "$DB_CONTAINER" ]; then
  if docker ps --format '{{.Names}}' | grep -qx 'scisp_db'; then
    DB_CONTAINER=scisp_db
  else
    DB_CONTAINER="$(docker ps --filter ancestor=mysql --format '{{.Names}}' | head -n 1)"
  fi
fi
[ -n "$DB_CONTAINER" ] || die "No running MySQL container found. Start Docker, or set SCISP_DB_CONTAINER."

BACKEND_CONTAINER="${SCISP_BACKEND_CONTAINER:-scisp_backend}"

say "Database container : $DB_CONTAINER"
say "Database           : $DB_NAME"
say "Tables             : ${TABLES[*]}"
say ""

# mysql/mysqldump read the password from the environment so it never appears in
# the process list or in this file's output.
mysql_q() {
  docker exec -e MYSQL_PWD="$DB_PASS" -i "$DB_CONTAINER" \
    mysql -u"$DB_USER" -N -B "$DB_NAME" -e "$1" 2>/dev/null
}

# --------------------------------------------------------------- pre-checks
for t in "${TABLES[@]}"; do
  found="$(mysql_q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='$t';")"
  [ "$found" = "1" ] || die "Table '$t' does not exist in '$DB_NAME'. Run migrations first."
done

mkdir -p "$COVER_OUT"

# ------------------------------------------------------------------- export
say "Exporting SQL ..."
docker exec -e MYSQL_PWD="$DB_PASS" "$DB_CONTAINER" \
  mysqldump -u"$DB_USER" \
    --no-tablespaces \
    --skip-comments \
    --complete-insert \
    --single-transaction \
    "$DB_NAME" "${TABLES[@]}" 2>/dev/null > "$OUT_SQL.tmp" \
  || die "mysqldump failed"

{
  cat <<'HEADER'
-- ---------------------------------------------------------------------------
-- SCISP Library — SAMPLE BOOK CATALOG
--
-- Contains ONLY: library_categories, books, book_copies, library_counters.
--
-- Contains NO users, NO transactions, NO holds, NO fines, NO renewals,
-- NO course sections or reserves, NO settings, NO authentication data.
--
-- Generated by scripts/export-library-sample-data.sh — do not edit by hand.
--
-- !! IMPORT SAFETY !!
-- This file carries real primary keys (book_id, copy_id) and ABC-LIB accession
-- numbers. Importing it into a database that already has books WILL collide.
-- Import into a clean/demo database, or back up first and replace the existing
-- sample catalog deliberately. See sample-data/library/README.md.
-- ---------------------------------------------------------------------------

HEADER
  cat "$OUT_SQL.tmp"
  cat <<'FOOTER'

-- ---------------------------------------------------------------------------
-- Detach state that points outside this export.
--
-- These columns reference tables that are intentionally NOT exported (users,
-- course_reserves, transactions, holds). Blanking them keeps the file importable
-- on its own and keeps user ids out of shared sample data. A sample catalog
-- carries no course-reserve allocation, no archive attribution, and no copy
-- that is out on loan or held for a borrower who does not exist here.
-- ---------------------------------------------------------------------------
FOOTER
  for entry in "${NULL_OUT[@]}"; do
    printf '%s\n' "${entry%%|*}"
  done
} > "$OUT_SQL"

rm -f "$OUT_SQL.tmp"

# ----------------------------------------------------------------- verify
say "Verifying export ..."

# A private table name appearing anywhere in the file is a hard failure.
FORBIDDEN='users|transactions|holds|renewal_requests|fines|course_sections|course_section_students|course_reserves|library_settings|password'
if grep -nEi "(INSERT INTO|CREATE TABLE)[^;]*\`($FORBIDDEN)\`" "$OUT_SQL"; then
  die "Export contains a forbidden table. File NOT trusted — inspect $OUT_SQL"
fi

for t in "${TABLES[@]}"; do
  n="$(mysql_q "SELECT COUNT(*) FROM \`$t\`;")"
  say "  $t: $n row(s)"
done

# ------------------------------------------------------------------ covers
say ""
say "Copying referenced covers ..."

COVER_SRC="$REPO_ROOT/backend/storage/app/public/library/covers"
copied=0
missing=0

# Only covers actually referenced by an exported book. Filenames are preserved
# exactly, because books.cover_image_path stores the name.
while IFS= read -r path; do
  [ -n "$path" ] || continue
  file="$(basename "$path")"
  if [ -f "$COVER_SRC/$file" ]; then
    cp -p "$COVER_SRC/$file" "$COVER_OUT/$file"
    say "  + $file"
    copied=$((copied + 1))
  else
    say "  ! referenced but MISSING on disk: $file"
    missing=$((missing + 1))
  fi
done < <(mysql_q "SELECT cover_image_path FROM books WHERE cover_image_path IS NOT NULL AND cover_image_path <> '';")

say ""
say "SQL    : $OUT_SQL"
say "Covers : $COVER_OUT ($copied copied, $missing missing)"
[ "$missing" -eq 0 ] || say "WARNING: $missing referenced cover file(s) were not on disk."
say ""
say "Nothing in the database was modified. Runtime covers were copied, not moved."
say "Review the files, then: git add sample-data/library && git commit"
