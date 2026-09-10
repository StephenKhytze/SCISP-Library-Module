# LIBRARY TASK — TEST DATABASE SAFETY

**Task ID:** `LIBRARY_TASK_TEST_SAFETY`
**Repo:** `SCISP-Library-Module` · **Branch:** `lyndon`
**Status:** ✅ **IMPLEMENTED AND VERIFIED** — 2026-09-08
**Document type:** as-built record (was a pre-implementation spec; now updated with verified corrections)

> ⚠ **File-loss note.** This document was recreated on 2026-09-08 after the original was
> deleted from the repository root along with the other `LIBRARY_*.md` specs. It is
> reconstructed from the authoring session and reflects the **implemented** state, which
> was re-verified against the live code before writing. See §11.

---

## 1. Root Cause — CORRECTED

The original analysis identified one cause. Implementation proved there were **two**, and the first fix alone was insufficient.

### 1.1 Cause A — PHPUnit `<env>` does not override an existing environment variable

`vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php:133-149`:

```php
private function handleEnvVariables(VariableCollection $variables): void
{
    foreach ($variables as $variable) {
        $name  = $variable->name();
        $value = $variable->value();
        $force = $variable->force();

        if ($force || getenv($name) === false) {   // ← skipped when the var already exists
            putenv("{$name}={$value}");
        }

        $value = getenv($name);

        if ($force || !isset($_ENV[$name])) {
            $_ENV[$name] = $value;
        }
    }
}
```

`docker-compose.yml` exports `DB_CONNECTION=mysql`, `DB_DATABASE=laravel` into the backend container. `getenv('DB_CONNECTION')` therefore returns `'mysql'`, not `false`, and with no `force` attribute `putenv()` never ran. The phpunit.xml values were silently discarded, `config/database.php:20` resolved `mysql`, and `RefreshDatabase` ran `migrate:fresh` on the live database.

### 1.2 Cause B — `$_SERVER` beats `$_ENV` in Laravel's Dotenv adapter order ⚠ **THE CORRECTION**

**Adding `force="true"` was necessary but NOT sufficient.** After that change alone, a plain `docker exec scisp_backend php artisan test` **still** resolved `connection: mysql, database: laravel`. Verified empirically: `env('DB_CONNECTION')` returned `'mysql'` inside the test process despite `force="true"` being present and parsed.

Three verified facts explain it:

| # | Fact | Evidence |
|---|---|---|
| 1 | PHPUnit's `handleEnvVariables()` writes **only** `putenv()` and `$_ENV`. `$_SERVER` is written by a **separate** method. | `PhpHandler.php:119-122` — `handleServerVariables()` does `$_SERVER[$variable->name()] = $variable->value();` |
| 2 | Laravel reads `env()` through Dotenv, whose **default adapter order puts `ServerConstAdapter` (`$_SERVER`) BEFORE `EnvConstAdapter` (`$_ENV`)**. | `vendor/vlucas/phpdotenv/src/Repository/RepositoryBuilder.php:26-27` |
| 3 | Docker's environment variables land in **`$_SERVER` as well as `$_ENV`**. | `docker exec scisp_backend php -r 'var_dump($_SERVER["DB_CONNECTION"], $_ENV["DB_CONNECTION"]);'` → both `"mysql"` |

So `$_SERVER['DB_CONNECTION'] = 'mysql'` was read first and won, no matter what `<env force="true">` did to `putenv`/`$_ENV`.

**Fix: matching `<server>` entries are required in addition to the forced `<env>` entries.** PHPUnit's `handleServerVariables()` writes `$_SERVER` unconditionally (no `force` check needed), which is what actually defeats the container environment.

### 1.3 Cause C — `.env.testing` cannot help either

`vendor/laravel/framework/src/Illuminate/Support/Env.php` builds the repository with `->immutable()`. An immutable repository will not overwrite a variable already present in the real environment. Laravel *does* correctly load `.env.{APP_ENV}`, but every `DB_*` key in it is discarded.

**`.env.testing` is documentation, not a barrier.** The file exists in the repo with a header saying exactly that.

### 1.4 Contributing factors

| # | Factor |
|---|---|
| a | No guard verified which database tests had resolved before `RefreshDatabase` wiped it |
| b | `tests/Pest.php:18` has `->use(RefreshDatabase::class)` commented out, so it looks opt-in — but a single `uses(RefreshDatabase::class)` in any file is enough |
| c | `composer test` runs `@php artisan test`, inheriting the same environment |
| d | Host runs cannot reach MySQL (`DB_HOST=db` is unresolvable from the host), so this only detonated **inside** the container |

---

## 2. Final Safe Design — four layers

The implemented design uses **all four**. Layers 1a and 1b are both required; either alone is defeated.

```
┌─ Layer 1a ── phpunit.xml <env force="true"> ───────────────────────┐
│  Overrides putenv() and $_ENV. Necessary, NOT sufficient on its own.│
└─────────────────────────────────────────────────────────────────────┘
┌─ Layer 1b ── phpunit.xml <server> ─────────────────────────────────┐
│  Overrides $_SERVER, which Dotenv reads FIRST. This is what         │
│  actually defeats docker-compose. ← the correction                  │
└─────────────────────────────────────────────────────────────────────┘
                              │ if either is edited away or reverted
                              ▼
┌─ Layer 2 ── Tests\TestCase::refreshApplication() guard ────────────┐
│  Inspects the RESOLVED Laravel config and hard-aborts BEFORE        │
│  setUpTraits()/RefreshDatabase can run. exit(1).                    │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─ Layer 3 ── TestDatabaseSafetyTest ────────────────────────────────┐
│  Always-on assertions proving isolation holds on every run.         │
└─────────────────────────────────────────────────────────────────────┘
```

**Layer 2 proved its worth during implementation.** While Layer 1b was still missing, a plain `artisan test` hit the guard, which aborted before `RefreshDatabase` ran. Live row counts stayed `11/3/5/1/2/2`. Defense in depth prevented a second wipe.

### 2.1 Test database: SQLite `:memory:` (Q-T1 = full lockout)

`pdo_sqlite` and `sqlite3` are present in the container (inherited from the `php:8.4-fpm` base image — the `Dockerfile` declares only `pdo_mysql`). All 17 migrations run clean on SQLite.

**Q-T1 was decided as option (a), full lockout.** No MySQL parity escape hatch. Consequence, verified: `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=laravel …` is now overridden back to sqlite — `-e` can no longer reach MySQL at all.

⚠ Known limitation, accepted: migration `2026_09_07_110500_add_pending_approval_to_holds_status` skips its `ALTER TABLE … ENUM` on SQLite, so `holds.status` is unconstrained under test. Tests cannot catch enum-violation bugs.

---

## 3. Implemented Changes

### 3.1 `backend/phpunit.xml`

**(a)** `force="true"` added to all 18 `<env>` entries.
**(b)** Four DB vars added and blanked: `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` (plus the pre-existing `DB_URL`), so even a forced switch to `mysql` cannot reach the `db` host.
**(c)** ⚠ **CORRECTION — eight `<server>` entries added**, mirroring the DB vars plus `APP_ENV`:

```xml
<server name="APP_ENV" value="testing"/>
<server name="DB_CONNECTION" value="sqlite"/>
<server name="DB_DATABASE" value=":memory:"/>
<server name="DB_URL" value=""/>
<server name="DB_HOST" value=""/>
<server name="DB_PORT" value=""/>
<server name="DB_USERNAME" value=""/>
<server name="DB_PASSWORD" value=""/>
```

Both blocks carry inline comments explaining why they are required and warning against removal. **Keep `<env>` and `<server>` in sync.**

### 3.2 `backend/tests/TestCase.php`

Added `refreshApplication()` override calling `parent::refreshApplication()` then `guardAgainstProtectedDatabase()`.

**Placement is the critical detail.** `Illuminate\Foundation\Testing\TestCase::setUp()` runs `refreshApplication()` and *then* `setUpTraits()`, and `setUpTraits()` is where `RefreshDatabase` executes `migrate:fresh`. Guarding inside `refreshApplication()` is the only window in which aborting still prevents data loss. A check after `parent::setUp()` would run only after the database was already wiped.

Guard contract:

| Property | Behaviour |
|---|---|
| Trigger | Resolved `database.connections.{default}.database` is in the protected list, or the connection `url` contains a protected name |
| Protected list | `laravel` by default; override via `TEST_PROTECTED_DATABASES` (comma-separated) |
| Always allowed | `sqlite` + `:memory:` short-circuits immediately |
| Action | Loud banner to `STDERR`, then `exit(1)` |
| Data impact | None — aborts before any migration or truncation |

`skipUnlessFortifyHas()` was left untouched.

⚠ `tests/Pest.php` binds this `TestCase` to `->in('Feature')` only. Unit tests are unguarded — acceptable as they have no DB access. **Do not put DB-touching tests in `tests/Unit`.**

### 3.3 `backend/tests/Feature/TestDatabaseSafetyTest.php` (new)

Four tests: three always-on assertions, plus one env-gated guard self-test (§4).

### 3.4 `backend/.env.testing` (new)

Documentation only, with a header stating it is non-authoritative and explaining the immutability reason from §1.3.

### 3.5 Unchanged, deliberately

`docker-compose.yml`, `config/database.php`, `tests/Pest.php`, `composer.json`, `Dockerfile`, all migrations and seeders, all Library business logic.

---

## 4. Negative-Test Verification — REPLACED METHOD

### 4.1 Why the original method no longer works

The original §7.3 negative test was:

```bash
# OBSOLETE — no longer reaches the guard
docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=laravel \
  scisp_backend php artisan test --filter=TestDatabaseSafety
```

Under Q-T1 full lockout, `<env force="true">` **plus** `<server>` override `docker exec -e` as well as docker-compose. That command now resolves to sqlite and simply passes — it can no longer drive the connection to MySQL.

**Weakening `force="true"` to make that command work is forbidden.** The lockout is the stronger property and must be preserved.

### 4.2 Replacement — `GUARD_SELFTEST=1`

The guard is exercised by mutating the **resolved config at runtime** and invoking the real guard method directly. `GUARD_SELFTEST` is deliberately **not** declared in `phpunit.xml`, so `-e` passes through.

```php
test('guard aborts when the resolved database is protected', function () {
    if (getenv('GUARD_SELFTEST') !== '1') {
        $this->markTestSkipped('Set GUARD_SELFTEST=1 to run the guard self-test (it exits the process by design).');
    }

    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'laravel',
    ]);

    $this->guardAgainstProtectedDatabase();   // must exit(1); never returns

    $this->fail('Guard did not abort on a protected database — SAFETY FAILURE.');
});
```

**Safe by construction:** the file uses no `RefreshDatabase`, and the guard aborts before any connection is opened. Nothing can be written even if the guard were broken.

```bash
docker exec -e GUARD_SELFTEST=1 scisp_backend \
  php artisan test --filter="guard aborts"
```

**Verified result:** exit code **2**, `ABORTED` banner on STDERR, **zero** tests reported as passed.

⚠ Capture the exit code correctly — `… | tail` reports `tail`'s status, not the container's. Redirect to a file and read `$?` immediately.

---

## 5. Docker-Safe Test Command

**The plain command is now safe — no flags required:**

```bash
docker exec scisp_backend php artisan test
```

That is the point: the barrier must not depend on anyone remembering a flag.

⚠ Host runs remain broken for an unrelated reason — host PHP is 8.2.12 while `composer.json` requires `^8.3`; `php artisan test` on the host fails with a `ParseError` in `vendor/sebastian/environment`. **Run tests inside the container.** (Audit finding SEC-09, out of scope.)

---

## 6. Verification Results — 2026-09-08

| Step | Result |
|---|---|
| Safety tests, **no** `-e` overrides | ✅ 3 passed, 1 skipped (7 assertions) |
| Resolved connection | ✅ `sqlite` / `:memory:` / `APP_ENV=testing` |
| Guard self-test | ✅ exit **2**, banner present, 0 tests passed |
| Full-lockout proof (`-e` cannot reach MySQL) | ✅ overridden back to sqlite |
| `ReserveAuthorizationTest` (Task 001) | ✅ 15 passed (33 assertions) |
| `MockAuthIdentityTest` (Task IDENTITY) | ✅ 25 passed (60 assertions) |
| Live MySQL counts at **4** checkpoints | ✅ `11 / 3 / 5 / 1 / 2 / 2` unchanged throughout |
| Ownership | ✅ reserve 1 → user 5; sections 1–2 → teacher 5 |
| `library_recovery_scratch` | ✅ retained |

---

## 7. Regression Risks (as-built)

| # | Risk | Status |
|---|---|---|
| R1 | Blanked `DB_HOST`/etc. break a future MySQL test run | Accepted under Q-T1 full lockout |
| R2 | `force`+`<server>` override `docker exec -e` too | **Confirmed and intended.** Documented in §4.1 |
| R3 | `exit(1)` in a test process is unusual | Correct CI behaviour; banner is explicit |
| R4 | Guard cost per test boot | Negligible — two `config()` reads |
| R5 | `refreshApplication()` override vs future Laravel change | Stable protected API; covered by regression suites |
| R6 | Unit tests unguarded | Accepted — no DB access |
| R7 | SQLite skips the holds-enum migration | Known, documented in §2.1 |
| R8 | `<env>` and `<server>` drift out of sync | **New risk from the correction.** Inline comments warn; keep both blocks aligned |

---

## 8. Rollback

```bash
cd backend
git checkout -- phpunit.xml tests/TestCase.php    # both are otherwise unmodified
rm -f tests/Feature/TestDatabaseSafetyTest.php .env.testing
git status --short
```

⚠ `git checkout --` is safe for **these two files only**. Never run it against `routes/api.php`, `ReserveController.php`, `CourseSectionController.php`, `MockAuthMiddleware.php`, or anything under `tests/Feature/Library/` — those hold uncommitted work with no git history to recover from.

Rolling back restores the **unsafe** behaviour. Do not run tests inside the container afterwards.

---

## 9. Acceptance Criteria — final status

| ID | Criterion | Status |
|---|---|---|
| AC-1 | All `<env>` carry `force="true"` | ✅ |
| AC-2 | `DB_URL/HOST/PORT/USERNAME/PASSWORD` blanked | ✅ |
| AC-2b | ⚠ **added** — matching `<server>` entries present | ✅ |
| AC-3 | Plain `artisan test` resolves sqlite `:memory:` | ✅ |
| AC-4 | Guard in `refreshApplication()` after `parent::` | ✅ |
| AC-5 | Guard aborts non-zero on protected DB | ✅ exit 2 |
| AC-6 | Guard allows `sqlite` + `:memory:` | ✅ |
| AC-7 | `TEST_PROTECTED_DATABASES` override works | ✅ |
| AC-8 | Safety test passes with no `-e` | ✅ |
| AC-9 | Negative test proves the guard fires | ✅ **via `GUARD_SELFTEST=1` (§4.2), not the obsolete `-e` method** |
| AC-10 | Live counts unchanged | ✅ 4 checkpoints |
| AC-11 | Task 001 15/15 | ✅ |
| AC-12 | Task IDENTITY 25/25 | ✅ |
| AC-13 | `docker-compose.yml` unmodified | ✅ |
| AC-14 | `config/database.php` unmodified | ✅ |
| AC-15 | No Library logic modified | ✅ |
| AC-16 | No `migrate:fresh`/seeder against MySQL | ✅ |
| AC-17 | `library_recovery_scratch` retained | ✅ |

---

## 10. Lessons for Future Tasks

1. **Verify the whole read path, not just the write path.** The original analysis correctly identified `force`, but stopped before asking *which superglobal Laravel actually reads first*. `<env>` and `$_ENV` were the obvious answer and the wrong one.
2. **Defense in depth is not ceremony.** Layer 2 caught a live failure of Layer 1 during this very implementation.
3. **Prove a guard fires.** A safety mechanism that has never been observed rejecting anything is an assumption.
4. **Never run `php artisan test` in a container that exports production-ish DB env** without confirming the resolved connection first.

---

## 11. Document Provenance

Recreated 2026-09-08 after the original file was deleted from the repository root, together with `LIBRARY_AUDIT.md`, `LIBRARY_PRODUCT_RULES.md`, `LIBRARY_TASK_001.md`, `LIBRARY_TASK_002.md` and `LIBRARY_TASK_IDENTITY.md`. The deletions did not go to the Recycle Bin and the files were never committed, so no copy was recoverable from disk or git.

This version was reconstructed from the authoring session **and re-verified against the live implemented code** (`phpunit.xml`, `tests/TestCase.php`, `tests/Feature/TestDatabaseSafetyTest.php`, `.env.testing`) before writing, so §3 and §6 describe what is actually in the tree — not recollection alone.
