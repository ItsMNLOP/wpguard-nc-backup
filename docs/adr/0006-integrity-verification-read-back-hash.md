# ADR 0006: Integrity verification by read-back hashing, never ETag-as-checksum

**Status:** Accepted (MVP)
**Date:** 2026-10-19

## Context

TRN-03 forbids treating a Nextcloud ETag as a trustworthy cryptographic hash.
Nextcloud's WebDAV implementation does not guarantee ETag is a content hash
(it can be based on mtime/size on some storage backends), and `OC-Checksum`
support depends on server version/storage backend and is not guaranteed
present. Release blocker: "Do not mark a backup successful based solely on a
positive HTTP upload response."

## Decision

Every uploaded part is verified after upload by:
1. `PROPFIND` the remote object, confirm it exists and `getcontentlength`
   matches the local file's byte size exactly.
2. `GET` the remote object back, streamed to a temp file (never buffered
   fully in memory — `wp_remote_request` with `'stream' => true,
   'filename' => $tmp`), and compute `hash_file('sha256', $tmp)`.
3. Compare against the SHA-256 computed locally when the part was written
   (stored in the in-progress manifest). Mismatch = failed upload for that
   part; the whole job is marked `failed`, nothing is pruned, and the
   previous good backup is untouched (RET-01).
4. Only after every part in the backup passes step 1–3 does the job write
   `COMPLETE.json` (TRN-02) — itself uploaded, then PROPFIND-verified for
   existence, and that write marks the backup `verified` in local job state.
   `COMPLETE.json` is the sole signal recovery tooling trusts; a backup
   folder with manifest+parts but no verified `COMPLETE.json` is treated as
   `uploaded-unverified`/`incomplete`, never surfaced as a success.

If the Nextcloud server+storage combination is later confirmed (via
documented, verified probing, not assumption) to provide a trustworthy
`OC-Checksum` response header with a matching algorithm, that can be added as
a *fast-path optimization layered on top of*, not a replacement for, the
read-back hash — this ADR's rule is "never ETag alone," not "never any server
checksum ever."

## Consequences

- Verification cost: every byte uploaded is also downloaded once. This
  roughly doubles network transfer and adds real end-to-end runtime, which is
  the deliberate, documented cost of NFR-01 ("no success status unless...
  remote verification criteria pass"). Benchmarked and disclosed, not
  hidden.
- Read-back happens over the same verified-TLS connection as upload — no
  weaker transport for the check than for the data.
