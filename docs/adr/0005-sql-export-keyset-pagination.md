# ADR 0005: SQL export via keyset pagination, best-effort consistency

**Status:** Accepted (MVP)
**Date:** 2026-10-19

## Context

BKP-01 requires avoiding OFFSET-based gaps/duplicates under concurrent
mutation, bounded memory, and an explicit, honest consistency label (BKP-05).
Shared hosting cannot assume a long-running transaction or `FLUSH TABLES WITH
READ LOCK` privilege.

## Decision

- Export each table's `CREATE TABLE` (via `SHOW CREATE TABLE`, preserving
  charset/collation/engine) followed by data rows fetched in batches ordered
  by primary key using a **keyset cursor** (`WHERE pk > :last_pk ORDER BY pk
  LIMIT :batch_size`), not `LIMIT/OFFSET`. This avoids the classic
  skip-or-duplicate failure mode when rows are inserted/deleted between
  batches.
- Tables with no single-column primary key (rare in a stock WordPress
  install, but possible with third-party plugins) fall back to ordering by
  the full column tuple as a composite keyset, and if no usable unique,
  sequential key exists at all, the table is exported as one atomic
  `SELECT *` batch and flagged in the manifest as `"ordering": "unordered-full-scan"`
  with a size warning if it's large. This fallback is documented, not silent.
- The manifest explicitly records
  `"consistency": "best-effort-live-snapshot"` for every MVP backup. The
  plugin never claims `"consistent"` (a label reserved for a future mechanism
  — e.g., a documented maintenance-mode window — that actually guarantees it).
- Binary/BLOB columns are hex- or base64-encoded in generated `INSERT`
  statements (standard `mysqldump`-compatible hex literal style:
  `0x...`) so the SQL file stays plain text and portable; multibyte/emoji
  text is preserved by exporting in the table's actual charset and emitting
  `SET NAMES`/charset pragmas at the top of the file.

## Consequences

- No cross-table transactional consistency is claimed or attempted (BKP-05).
  A write landing between the `wp_posts` and `wp_postmeta` exports in the same
  run is a known, disclosed limitation.
- Memory stays bounded by batch size regardless of table size (NFR-06).
- Restore must replay the file as plain SQL (`mysql < database.sql`), no
  custom import tooling required (RST-01, no plugin dependency for recovery).
