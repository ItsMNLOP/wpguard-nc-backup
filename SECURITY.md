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
- CI security/static-analysis workflow is written (`.github/workflows/ci.yml`)
  but has not executed in any CI environment yet.
