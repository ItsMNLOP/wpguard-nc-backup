# Security policy

**Status: MVP, not yet independently audited.** This plugin has not had
Vex's independent security review (SOP-023 in the PKA knowledge base) and
has not been used on any production site. Do not deploy it to a real
WordPress site before that review happens. See `docs/threat-model.md` for
the self-review performed during build, and `docs/adr/0002-*.md` for a
known, deliberate deviation (credential stored in `wp_options` rather than
a `wp-config.php` constant, with an optional constant-override escape
hatch).

## Reporting a vulnerability

This is a personal/internal project at MVP stage, not yet published. If
you are the project owner: route findings to the project's security
reviewer (Vex) before any public release. There is no public disclosure
process yet because there is no public release yet.

## Known limitations at this stage

- No at-rest encryption of backup archive contents (NFR-04).
- No automated dependency/security-advisory monitoring configured yet
  (there are no dependencies to monitor — MVP ships zero Composer
  packages).
- CI security/static-analysis workflow is written (`.github/workflows/ci.yml`)
  but has not executed in any CI environment yet.
