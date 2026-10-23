# ADR 0002: Credentials stored via WordPress Settings API (wp_options), not wp-config.php constant

**Status:** Accepted (MVP)
**Date:** 2026-10-19

## Context

A common WordPress-plugin convention stores a credential like this as a
`wp-config.php` constant, never in `wp_options` — a file-based secret is
harder to exfiltrate purely through a database compromise. The project
brief (section 15.2, which carries amendment-level precedence for this
build) requires the admin to enter the URL, username, and app password
through the WordPress admin settings screen, with no file-edit step, and
explicitly describes WordPress-compatible server-side settings as the MVP
secret storage mechanism.

These two approaches conflict. A file-constant credential cannot be entered,
tested, or rotated from an admin screen without either templating
`wp-config.php` (fragile, often not web-writable on shared hosting, and risky
to parse/rewrite safely) or falling back to storing it in the database anyway
and only reading an optional override constant.

## Decision

Follow the brief for MVP: store the credential in a dedicated `wp_options`
row (not autoloaded), gated behind `manage_options` capability and nonce
checks on every read/write path. Support an **optional** override: if the
constant `WPTHISTLE_BACKUP_APP_PASSWORD` (and `WPTHISTLE_BACKUP_CA_BUNDLE` for the test CA,
see ADR 0007) is defined in `wp-config.php`, it takes precedence over the
stored option and the settings field is shown as locked/disabled with a note
explaining why. This gives operators who want the file-based-secret
convention a supported path without making it mandatory for MVP.

## Consequences

- MVP ships with brief-compliant, admin-screen-only credential entry.
- The `wp_options` value is still "secret in the database," which is weaker
  than a file-based secret under a compromised-database threat model. This is
  documented in `docs/threat-model.md` and in the admin UI help text as a
  known limitation (NFR-04 acknowledges this limit explicitly).
- The constant-override escape hatch satisfies the file-based-secret
  convention described above, without blocking MVP delivery.
- **Deviation logged for security review:** this is a documented, deliberate
  departure from the "wp-config constant only" convention, made because the
  task's own instructions (brief section 15, explicit highest precedence)
  override it for this build. Flagged during the project's security audit;
  see `docs/threat-model.md` and `STATUS.md`.
