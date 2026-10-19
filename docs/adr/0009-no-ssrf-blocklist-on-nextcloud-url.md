# ADR 0009: Nextcloud URL validation skips WordPress's private-IP SSRF blocklist

**Status:** Accepted (MVP)
**Date:** 2026-10-19 (discovered during live E2E testing, not in original design)

## Context

`wp_http_validate_url()` is WordPress core's standard helper for validating
a URL before fetching it, and it deliberately rejects any host that
resolves to a private/reserved IP range (127.x, 10.x, 172.16-31.x,
192.168.x) unless that host matches the site's own `home` URL. This exists
to stop a webserver being tricked into attacking its own internal network
(classic SSRF hardening for "fetch this arbitrary URL" features).

This plugin's own motivating scenario — confirmed live during E2E testing,
where the local disposable Nextcloud, reached at a private Docker-bridge
IP under the hood, was rejected by this exact check — is the owner's
self-hosted Nextcloud on a private homelab network (Tomcat/Floof). Using
`wp_http_validate_url()` as originally implemented would make the plugin
reject its own primary intended use case.

## Decision

Settings::save() validates the Nextcloud URL with a narrower, purpose-fit
check (`is_syntactically_valid_destination_url()`): must parse as a URL,
have a non-empty host, carry no embedded userinfo, and contain no control
characters in the host. It does **not** resolve the hostname or reject
private IP ranges.

This is safe here specifically because: (1) only an authenticated
`manage_options` admin can set this value (threat-model T5 — WordPress
already fully trusts its admins); (2) it is a single, admin-chosen,
persistent destination, not a per-request or user-influenced target, so
there's no way for a lower-privileged user or outside request to redirect
a fetch anywhere; (3) NFR-03 explicitly requires supporting private
Nextcloud instances. `wp_http_validate_url()` remains correct and
unmodified for its usual purpose elsewhere in WordPress — this plugin
simply doesn't reuse it for this specific, differently-shaped trust
boundary.

## Consequences

- A malicious *admin* could point the setting at an internal service and
  cause the plugin to PUT/GET/PROPFIND/DELETE requests against it with the
  configured app-password credential — but a malicious admin can already
  do arbitrary things in WordPress (install any plugin, edit any file via
  the theme/plugin editor), so this adds no new capability to that already
  fully-trusted actor. Documented as an accepted residual risk, same as
  threat-model T5.
- `tests/unit/SettingsValidationTest.php` covers the new validator
  directly; the removed `wp_http_validate_url` shim in
  `tests/unit/wp-shim.php` is now unused by Settings but left in place
  since nothing else needs it removed.
