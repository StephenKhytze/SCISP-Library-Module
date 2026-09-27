# Sample Library Catalog — transfer through Git

This folder exists so a classmate can add sample books through the **existing
Library Admin UI**, and hand the catalog over through Git.

It is **not an application feature**. There is no Import button, no Export button,
no new API route, no new screen and no schema change. Everything here is a
developer utility plus two data files.

```
sample-data/library/
├── covers/              cover images for the books below
├── library_books.sql    the catalog tables
└── README.md            this file
```

The export helper lives at [`scripts/export-library-sample-data.sh`](../../scripts/export-library-sample-data.sh).

---

## What is in the SQL file

Exactly four tables, and nothing else:

| Table | What it holds |
|---|---|
| `library_categories` | the category vocabulary |
| `books` | titles, ISBN, edition, publisher, year, shelf, cover path |
| `book_copies` | physical copies and their `ABC-LIB-######` accession numbers |
| `library_counters` | the accession-number counter, so new copies continue the sequence |

## What is deliberately NOT in it

**No `users`. No `transactions`. No `holds`. No `renewal_requests`. No `fines`.
No `course_sections`, `course_section_students` or `course_reserves`.
No `library_settings`. No authentication data of any kind.**

The export script refuses to write the file if any of those table names appears
in an `INSERT` or `CREATE TABLE`, so this cannot regress quietly.

> Never put a `.env`, a full database dump, account data, passwords or real
> circulation records in this folder.

---

## Foreign keys — why some values are blanked

Three of the exported columns point at tables that are **not** exported, and one
column describes state that only makes sense alongside them. The export blanks
all four, with `UPDATE` statements at the end of the SQL file:

| Column | Points at | Why it is blanked |
|---|---|---|
| `library_categories.created_by` | `users` | keeps user ids out of shared sample data |
| `books.archived_by` | `users` | same |
| `book_copies.reserve_id` | `course_reserves` | the reserve is not exported, so the allocation would dangle |
| `book_copies.availability_status` | `transactions` / `holds` | a copy cannot be `checked_out` or `on_hold` when no loan or hold exists here — it would report wrong availability. `lost` and `damaged` are kept, because those describe the copy itself |

The alternative would have been to export the private tables those columns point
at. That is not acceptable for a file shared through Git, so the references are
detached instead.

**The `CREATE TABLE` statements still declare those foreign keys.** That is fine:
`mysqldump` wraps the file in `FOREIGN_KEY_CHECKS=0`, so the file imports into a
completely empty database. *(Verified: imported into an empty database, exit 0.)*
For normal use you will be importing into a database that already has the SCISP
schema anyway.

---

## ⚠ IMPORT SAFETY — read this first

**The SQL file contains real primary keys (`book_id`, `copy_id`) and real
`ABC-LIB-######` accession numbers.**

Do **not** import it into a populated database if those ids or accession numbers
might already exist. You will hit duplicate-key errors, or worse, silently mix
two catalogs.

Import it into one of these only:

- a **clean / demo database**, **or**
- a database where you are **deliberately replacing an existing sample catalog**,
  after taking a backup.

There is deliberately **no automatic reset script**. Dropping or truncating your
database is a decision you make yourself, with a backup in hand.

Also note `library_counters` carries the accession sequence. Importing it sets
the counter to the sample catalog's high-water mark, which is what you want when
replacing a catalog and what you do **not** want when merging into one.

---

## CLASSMATE WORKFLOW — producing the sample data

1. `git pull`
2. Start the SCISP Library as usual (`docker compose up -d`).
3. Open the app and use an **Admin** account.
4. Manually add roughly **10 sample books** through **Add Title & Copies**.
   Use made-up or public-domain titles — no real personal data.
5. Upload sample covers with **Add Cover** on each title.
6. Run the export from the repository root:

   ```bash
   ./scripts/export-library-sample-data.sh
   ```

7. Inspect what it produced:

   ```bash
   git status sample-data/library
   head -40 sample-data/library/library_books.sql
   ls sample-data/library/covers
   ```

   Confirm you see only the four catalog tables, and no user or loan data.

8. Commit and push:

   ```bash
   git add sample-data/library
   git commit -m "Add sample library catalog and covers"
   git push
   ```

The export is read-only: it changes nothing in your database, and it **copies**
the runtime covers rather than moving them. Your local Library keeps working.

---

## OWNER WORKFLOW — receiving the sample data

1. `git pull`

2. **Back up your current development database first.** This is the step people
   skip and regret:

   ```bash
   docker exec scisp_db mysqldump -uroot -p"$DB_PASSWORD" --no-tablespaces laravel > backup_before_sample_import.sql
   ```

   Keep that file **outside** the repository.

3. Import into a clean or demo database — see the safety section above:

   ```bash
   docker exec -i scisp_db mysql -uroot -p"$DB_PASSWORD" <target_database> \
     < sample-data/library/library_books.sql
   ```

   Replace `<target_database>` with the demo database you intend to overwrite.
   If it is a fresh database, run the migrations there first so the rest of the
   Library schema exists.

4. Copy the covers into the runtime location:

   ```bash
   cp sample-data/library/covers/* backend/storage/app/public/library/covers/
   ```

   Filenames must be preserved exactly — `books.cover_image_path` stores them.

5. Make sure the public symlink exists:

   ```bash
   docker exec scisp_backend php artisan storage:link
   ```

6. Start the system and open the Library.

7. Verify: the sample books appear in **Catalog Search**, each copy shows an
   `ABC-LIB-######` accession number under **Manage Copies**, and the uploaded
   covers render instead of the placeholder.

> If covers show as placeholders, check that `APP_URL` in `backend/.env` includes
> the port the backend is actually served on (e.g. `http://localhost:8000`).
> `Storage::url()` builds cover URLs from it, and a value without the port
> produces links to port 80 where nothing is listening.

---

## Credentials

The export script reads `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` from
`backend/.env` and passes the password to MySQL through `MYSQL_PWD`, so it never
appears in this documentation, in the script, in the committed output or in the
process list.

Override the container names if yours differ:

```bash
SCISP_DB_CONTAINER=my_db SCISP_BACKEND_CONTAINER=my_backend ./scripts/export-library-sample-data.sh
```
