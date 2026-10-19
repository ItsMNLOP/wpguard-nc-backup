# ADR 0001: Manual app-password entry, no Nextcloud Login Flow v2

**Status:** Accepted (MVP)
**Date:** 2026-10-19

## Context

Nextcloud supports two ways to obtain an app password: Login Flow v2 (OAuth-like
redirect/poll flow) and manual generation by the user in Nextcloud's own
Personal Settings > Security page. The brief (section 15.2) requires the admin
to paste a Nextcloud URL, username, and a dedicated app password directly into
WordPress settings. No OAuth requirement for MVP.

## Decision

The plugin does not implement Login Flow v2. The settings screen collects:
Nextcloud base URL, account username, app password (password-type field,
never echoed back), and destination folder path. The admin generates the app
password themselves in Nextcloud and pastes it in.

## Consequences

- Simpler, fewer moving parts, no redirect/polling state machine, no stored
  Login Flow poll tokens to clean up.
- Admin must already have (or create) a dedicated non-admin Nextcloud account
  and know how to reach Personal Settings > Security > "Create new app
  password". This is documented in the setup guide.
- Login Flow v2 remains a legitimate Phase 2+ UX improvement (reduces copy-paste
  error) and can be added later without changing the stored-credential shape
  (base URL + username + app password stays the same either way).

## Alternatives considered

- Login Flow v2: rejected for MVP only because the brief explicitly scopes it
  out ("No OAuth requirement for MVP") and it adds a stateful redirect flow
  that complicates the admin-only, server-side-only MVP.
