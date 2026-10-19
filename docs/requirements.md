# Requirements (distilled)

The authoritative requirements document is the brief delivered to this
project (`CLAUDE_WORDPRESS_NEXTCLOUD_BACKUP_BRIEF.md`, v1.3, in the PKA
`Comms/Team_Inbox/`). This file exists only because section 10 of that
brief lists `/docs/requirements.md` as an expected repository deliverable;
it is a pointer plus an MVP-scope summary, not a restatement of the whole
brief (SSOT: the brief itself, not a copy here).

## MVP scope (Phase 0 + Phase 1 only, per brief section 15)

In scope: manual single-site backup (DB + wp-content + wp-config.php),
versioned manifest with SHA-256 hashes, authenticated HTTPS WebDAV
transfer to a dedicated Nextcloud account, read-back verification,
`COMPLETE.json` completion marker, admin settings UI with Test Connection,
structured redacted logging, WP-CLI driver, manual restore runbook,
end-to-end restore test against a clean disposable WordPress instance.

Out of scope for MVP (brief sections 2, 15.2, Phase 2+): scheduling,
retention/pruning, one-click restore, multisite, chunked-upload v2,
client-side encryption, WordPress.org publication, real cron triggering.

## Requirement-ID cross-reference

Functional requirement IDs (`CFG-*`, `BKP-*`, `TRN-*`, `JOB-*`, `RET-*`,
`OBS-*`, `RST-*`) and nonfunctional IDs (`NFR-*`) referenced throughout
`docs/adr/*.md` and `docs/threat-model.md` are defined in the brief,
section 5–6. `STATUS.md` tracks the MVP release-gate items (brief section
15, "MVP release gate") concretely against this build's actual state.
