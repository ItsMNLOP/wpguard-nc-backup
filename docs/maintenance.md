# Maintenance (MNT-15)

## Release cadence

No public release has happened yet (MVP, local-test only). Once/if public
release happens, intended cadence: patch releases as needed, review the
version matrix (ADR 0008) monthly.

## Upstream compatibility monitoring

`.github/workflows/ci.yml` includes a weekly scheduled run (cron) intended
to catch upstream WordPress/PHP/Nextcloud breakage even when plugin code
hasn't changed — **not yet exercised**, no CI runner connected this
session. Until CI is connected, this must be done manually: periodically
re-run `docker/scripts/bootstrap.ps1` + `run-e2e.ps1` against updated image
tags.

## Dependency updates

None to monitor yet — MVP ships zero Composer dependencies
(`docs/architecture.md` explains why). If Phase 2+ adds any, wire up
Dependabot/Renovate at that point, not before (MNT-13).

## Backup-format compatibility

Any change to `docs/backup-format.md`'s schema requires a `format_version`
bump and an entry here describing what changed and how old archives remain
readable. Nothing to record yet — only `format_version: 1` has ever
existed.

## Manifest migrations

None yet (first version). When they're needed: a migration must be able to
read every prior `format_version` the plugin ever produced, per MNT-04.

## Restore regression gates

`tests/e2e/run-e2e.sh` is the regression gate: any change must still pass
a full backup -> download -> restore -> content-assertion cycle before
merge, per MNT-16/MNT-19.

## Deprecation policy

Not written yet — nothing has shipped publicly to deprecate. Will be
added before any `format_version` bump or public 1.0.

## Credential rotation

Admin replaces the app password field (blank = unchanged, non-blank =
replace) without resetting destination, job history, or existing backups
— implemented in `class-settings.php`'s save validation and tested
implicitly by the admin UI's "leave blank to keep current value" behavior.
Formal rotation test (save new password mid-job-history, confirm old
backups still listed/accessible) is a Phase 2 MNT-18 item, not yet
automated.

## Emergency fixes

None issued yet (0.1.0 is the first version).
