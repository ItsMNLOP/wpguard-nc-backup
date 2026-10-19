# Architecture (MVP)

## Module boundaries (MNT-01)

Each class below is instantiable and testable without bootstrapping
WordPress, except where it must call a WP function — those calls are
isolated behind a small adapter so unit tests can inject a fake.

```
includes/
  class-settings.php        Settings storage/validation. Wraps wp_options
                             reads/writes + constant-override check (ADR 0002/0007).
                             No WP-function calls outside get_option/update_option/
                             defined()/constant() — easy to fake in tests.
  class-admin.php            Settings page render + AJAX action handlers
                             (capability+nonce checks live here, ADR/threat-model T1/T2).
  class-nextcloud-client.php WebDAV transport adapter: PUT/GET/PROPFIND/DELETE,
                             retry/backoff, TLS args from Settings. The only
                             class that knows WebDAV URL shapes (MNT-03).
  class-job-orchestrator.php Job state machine: create/advance/get-status.
                             Persists checkpoints to a custom table
                             (wp_wpguard_nc_jobs) - see ADR below in this file.
  class-inventory.php        Enumerate files under ABSPATH per inclusion/
                             exclusion rules; symlink policy (never follow
                             outside site root).
  class-db-exporter.php      Keyset-paginated SQL export (ADR 0005).
  class-archive-writer.php   ZipArchive wrapper enforcing path-safety
                             (no '..', no absolute paths) at write time.
  class-manifest.php         Build/parse/validate manifest.json + COMPLETE.json
                             (docs/backup-format.md). Never receives credentials.
  class-logger.php           Structured log entries + Logger::redact().
  class-ca-bundle.php        Validate an admin-supplied CA bundle path (ADR 0007).
  interfaces/
    interface-transport.php  Upload_Result upload_part($local_path,$remote_path);
                              exists()/propfind()/download_to_file(); so a future
                              chunked-v2 adapter can implement the same contract.
```

## Job state storage: custom table, not options

**Decision (recorded here, not a separate ADR file since it's a single
clear call with no real alternative debate):** job state lives in a small
custom table `{$prefix}wpguard_nc_jobs` (job_id, status, step, checkpoint
JSON, created_at, updated_at), not `wp_options`. Rationale: a job's
checkpoint JSON can grow (file lists, part hashes) and churns on every
step; options table autoload/serialization overhead and the lack of an
indexed-by-job-id lookup make a dedicated table a better fit, and WordPress
explicitly supports custom tables via `dbDelta()` for exactly this shape of
data. The table is created/upgraded in the plugin's activation hook via
`dbDelta()`, versioned via a stored `wpguard_nc_db_schema_version` option
(MNT-05 migration hook point for later).

## Request flow — manual backup

1. Admin clicks "Back Up Now" on the settings page.
2. JS posts `wpguard_nc_start_backup` AJAX action (nonce + capability
   checked server-side) -> `Job_Orchestrator::create_job()` inserts a job
   row, status `queued`, returns `job_id`.
3. JS immediately posts `wpguard_nc_advance_job` with that `job_id`
   (nonce+capability checked again — every step, not just job creation).
   Each call processes one bounded unit (one DB batch, one file enumerated,
   one part uploaded+verified) and returns updated status/progress; JS
   loops calling advance until status is terminal (`verified` or `failed`).
4. A `wp_schedule_single_event` fallback also calls `advance_job` a few
   seconds out and periodically thereafter (while the job is non-terminal),
   so a closed browser tab doesn't strand the job (ADR 0003).
5. On terminal state, the job row's final status and any sanitized error
   is what the admin page and `docs/operations.md`-described log reflect.

WP-CLI (`wp wpguard-nc-backup run`) calls the same `create_job`/`advance`
functions synchronously in a loop until terminal, for deterministic
scripting/testing — no AJAX, no cron, no timing dependency.

## Why no Composer dependencies in MVP

Every MVP requirement (WebDAV client, ZIP writer, SQL export) is reachable
with WordPress core APIs (`wp_remote_request`, `ZipArchive`, `$wpdb`) and
hand-written, unit-tested code small enough to review in full. MNT-12
("use Composer only where it improves maintainability... prefer standard
supported WordPress/PHP APIs when sufficient") is read here as: for MVP,
sufficient. PHPCS/PHPStan/PHPUnit are **dev-only** tooling, never shipped in
the release ZIP (enforced by the packaging script, `docker/scripts/package.ps1`,
which copies only `plugin/wpguard-nc-backup/` contents).
