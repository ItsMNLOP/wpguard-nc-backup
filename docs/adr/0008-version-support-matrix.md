# ADR 0008: Supported version matrix (MVP)

**Status:** Accepted (MVP)
**Date:** 2026-10-19

## Decision

| Component | Minimum supported | Recommended / tested | Source |
|---|---|---|---|
| WordPress | 6.4 (first version with reliably current `wp_remote_request` streaming + current Settings API patterns in wide shared-host deployment) | 7.1 (current stable) | wordpress.org core post, Jan 2026: min *supported* PHP stays 7.4, min *recommended* PHP 8.3 — WordPress core version floor chosen conservatively below current stable, tested value is current stable |
| PHP | 8.1 (oldest version still receiving any upstream security support at time of writing; brief requires choosing from current guidance, not a dead branch) | 8.3 (WordPress's own recommended minimum) | php.net supported branches; WordPress Hosting Handbook recommends 8.4 for new production, we test against WP's own stated recommended-minimum (8.3) plus the floor (8.1) |
| MySQL/MariaDB | MariaDB 10.11 (LTS, in support) | MariaDB 11.4 (LTS) | MariaDB release/EOL tracking: 10.11 supported to 2028-02, 11.4 to 2029-05 |
| Nextcloud | 30 (previous major, still within one-year maintenance window at time of writing per Nextcloud's "each major supported ~1 year" policy) | 34 (current stable at time of writing) | Nextcloud server docs release schedule page + third-party EOL tracker, cross-checked |
| Required PHP extensions | `mysqli` or `pdo_mysql`, `curl` (WP HTTP API's preferred transport), `mbstring`, `zip` (ZipArchive) or documented fallback, `openssl` | same | NFR-05 |

**Tested vs expected, per MNT-10/MNT-08:**
- **Tested** (this MVP, in the local disposable stack, recorded with exact
  versions and commands in `STATUS.md`): WordPress 7.1, PHP 8.3, MariaDB
  11.4, Nextcloud 34 — one concrete combination, run end-to-end including
  restore.
- **Expected, not yet tested:** the full matrix above. CI workflow
  (`.github/workflows/ci.yml`) is written to exercise PHP 8.1 and 8.3 against
  WordPress 6.4 and 7.1 on unit/static-analysis jobs (no live container
  dependency), but this CI has **not executed** in this session (no CI
  runner connected) — reported honestly as not-yet-run, per MNT-08/the
  brief's prohibition on claiming untested compatibility.

## Rationale

The brief requires "currently maintained, widely deployed" versions chosen
from live official guidance, not invented from training-data memory. All
version numbers above come from web searches performed on 2026-10-19 (after
this agent's January 2026 knowledge cutoff) and were cross-checked against at
least one official/primary-ish source where available; MariaDB and Nextcloud
exact point releases were further confirmed to exist as pullable container
images before being pinned in `docker/docker-compose.yml`.
