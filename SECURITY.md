# Security policy

**Status: MVP, independently security-reviewed feature by feature.** Each
shipped feature has gone through an independent security audit before
release — findings fixed and re-verified before the feature is marked
shippable; see `STATUS.md` for the audit history per feature and
`docs/threat-model.md` for the running threat model. That process does
not substitute for your own review before production use on infrastructure
you depend on. See `docs/adr/0002-*.md` for a known, deliberate deviation
(credential stored in `wp_options` rather than a `wp-config.php` constant,
with an optional constant-override escape hatch).

## Reporting a vulnerability

This is a personal/internal project at MVP stage, not yet published. There
is no public disclosure process yet because there is no public release
yet.

## Known limitations at this stage

- No at-rest encryption of backup archive contents (NFR-04).
- No automated dependency/security-advisory monitoring configured yet
  (there are no dependencies to monitor — MVP ships zero Composer
  packages).
- CI security/static-analysis workflow (`.github/workflows/ci.yml`) is
  connected and executing on every push to the real GitHub repo (confirmed
  2026-10-21). It does not yet run the `multi-backend` compose profile
  (ownCloud/OpenCloud) — that matrix is exercised manually.
