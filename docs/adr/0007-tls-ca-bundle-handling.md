# ADR 0007: TLS verification always on; optional admin-supplied CA bundle path

**Status:** Accepted (MVP)
**Date:** 2026-10-19

## Context

CFG-03: HTTPS required by default, no silent downgrade, `sslverify => false`
is forbidden outright. The project's own motivating scenario (owner's
self-hosted Nextcloud, possibly behind a private/internal CA) means the
*real* deployment target may not always present a certificate chaining to a
public root. The plugin must never weaken default verification to cope with
that; it must instead let a verified admin supply the private root
explicitly.

## Decision

- Default: `wp_remote_request` is called with WordPress's normal TLS
  verification behavior untouched (`sslverify` left at its WordPress default,
  which is `true`), using the system/PHP CA bundle. No code path ever sets
  `sslverify => false`.
- Optional advanced setting: "CA bundle path" — an **absolute filesystem path
  on the WordPress server** (not a URL, not an upload-and-store-as-blob) to a
  PEM file containing one or more trusted CA certificates. If set, the
  plugin validates the path exists, is readable, and parses as PEM-encoded
  certificate(s) at save time (capability+nonce gated, admin-only), and passes
  it as `args['sslcertificates']` on every Nextcloud request instead of the
  default bundle. If unset (the common case — public Nextcloud instance,
  Let's Encrypt or similar), default verification against the system bundle
  applies, unchanged.
- This setting can also be supplied via the `WPTHISTLE_BACKUP_CA_BUNDLE` constant
  (see ADR 0002), taking precedence over the stored option, for operators who
  don't want even a filesystem path sitting in the database.
- The admin UI documents, next to this field, that pointing it at a CA you do
  not control defeats the purpose of verification, and that this field is for
  a *private* CA you operate, not a replacement for a valid public
  certificate.

## Consequences

- Local integration tests exercise exactly this path: a disposable CA is
  generated for the test Nextcloud container, and the test harness sets
  `WPTHISTLE_BACKUP_CA_BUNDLE` in the test WordPress container's `wp-config.php` —
  the same code path a self-hosted-with-private-CA admin would use in
  production. No test-only TLS bypass exists anywhere in the codebase.
- A misconfigured CA bundle path fails closed (request errors out, reported
  as a distinct "TLS/certificate error" status per TRN-05), not falling back
  to no verification.
