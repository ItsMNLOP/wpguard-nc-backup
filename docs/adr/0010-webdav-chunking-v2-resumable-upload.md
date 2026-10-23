# ADR 0010: WebDAV chunking v2 for resumable part upload (amends ADR 0004)

**Status:** Accepted
**Date:** 2026-10-22

## Context

ADR 0004 (MVP) deliberately shipped plain WebDAV PUT per bounded part
(default 64 MiB), with two documented Phase 2 gaps: "No resumability of
a single in-flight PUT if interrupted mid-part; the whole part is
retried from the start" and the whole part being read into one PHP
string before the PUT (bounded by part size, not by total backup size,
but still the whole part at once). ADR 0004 explicitly anticipated this
exact follow-up: "chunked upload v2 can be added later as an alternate
code path behind the same adapter interface... without touching
callers." TRN-04 (chunked upload v2), tagged [H] (hardening/Phase 2),
was the last unstarted item carried from that list.

Before designing anything, the real protocol was tested live against
all three verified backends (same practice as the OC-Checksum
investigation, STATUS.md 2026-10-19) rather than assumed from memory or
documentation:

- **Nextcloud 34.0.5** and **ownCloud 10.15**: `MKCOL` on
  `remote.php/dav/uploads/{user}/{upload-id}`, numbered `PUT`s to
  `.../{index}` (6-digit, zero-padded), then `MOVE .../{upload-id}/.file`
  to the real destination with a `Destination` header and
  `OC-Total-Length` — all confirmed working identically on both
  servers. A `PROPFIND` (Depth: 1) on the session collection lists
  which chunk names already exist. A wrong `OC-Total-Length` on `MOVE`
  is rejected (400), a genuine server-side integrity check. The session
  collection is removed automatically by the server once `MOVE`
  succeeds — no explicit cleanup needed on the success path.
- **OpenCloud 7.2.4**: `MKCOL` on the exact same `uploads/{user}/{id}`
  path returns **404** — confirmed live, not assumed. OpenCloud (a Go
  rewrite, OCIS-lineage) does not implement this legacy
  Nextcloud/ownCloud-specific chunking dialect at all; it likely uses
  TUS or its own native API for resumable upload, neither investigated
  here since the plain-PUT fallback already covers it correctly.

## Decision

`Nextcloud_Client::upload_part( $local_path, $relative_path, $upload_id )`
replaces the raw `file_get_contents()` + `put_bytes()` pair
`step_upload_parts()` used to call directly. For a file bigger than one
chunk (`CHUNK_SIZE_BYTES`, 8 MiB) on a server that supports chunking v2,
it uploads via the real MKCOL/PUT/MOVE dance above, reading the local
file in bounded 8 MiB pieces via `fopen`/`fread` (never buffering more
than one chunk). Otherwise — a small file, or a server without
chunking-v2 support — it falls back to the exact single whole-file PUT
ADR 0004 already shipped, unchanged.

**Capability is probed, never assumed from the `backend` setting.** A
throwaway `MKCOL` (cleaned up immediately) decides support, cached per
server URL for a day (`supports_chunked_upload_v2()`), invalidated on
every `Settings::save()`. This is a deliberate departure from
branching on `Settings::get_backend()`: that setting has no consumer
anywhere in the plugin (confirmed by the Phase 3 multi-backend audit,
threat-model T22) specifically because the admin's own backend
selection doesn't reliably predict server capability — probing the
real server directly is both more robust (works against a future
WebDAV server this plugin has never heard of) and keeps T22's own
finding true rather than reopening it: `get_backend()` still has zero
consumers; this feature branches on a live capability probe instead.

**Resumability is achieved by a deterministic `$upload_id`, not a
persisted checkpoint field.** The caller
(`Job_Orchestrator::step_upload_parts()`) derives it from data that
never changes across retries of the same logical upload —
`hash('sha256', site_id . '|' . job_id . '|' . $item['remote'])` — and
passes the *same* value every time it (re-)attempts this exact part.
If the PHP process is killed outright mid-upload (host death, a hard
timeout — no chance to persist anything of its own), the checkpoint on
disk is untouched and the next `advance()` of this still-`running` job
re-selects the same item and re-derives the identical `upload_id`.
`upload_part()` then re-discovers which chunks already landed via a
real `PROPFIND` against the server — never a local counter — and skips
them, finishing only what's left before `MOVE`. This needed **zero new
checkpoint fields**: no schema change, nothing for HIGH-CHECKPOINT-1's
disk-blob-externalization discipline to even apply to. The existing
`sha256`/`size` fallback in `step_upload_parts()` was changed from
`file_get_contents()`+`hash()`/`strlen()` to `hash_file()`/`filesize()`
at the same time — same bounded-memory benefit, no behavior change.

**Proven live, not just argued** (`tests/e2e/run-e2e-chunked-upload.sh`):
a real 20 MiB file round-trips byte-identical through the chunked path
on Nextcloud and ownCloud, and through the plain-PUT fallback on
OpenCloud. The resume claim specifically: a *deliberately wrong* chunk
0 is planted directly on the server before calling `upload_part()` with
the same `upload_id`; the assembled result's first chunk is confirmed
to still be the wrong planted bytes (proving it was never re-uploaded,
not merely that the end result happened to match) while every byte
after it matches the real source file (proving the rest completed
correctly that call). This is the one test shape that actually
distinguishes "genuinely resumed" from "silently re-uploaded
everything and got lucky."

## Consequences

- `ADR 0004`'s `PART_SIZE_BYTES` bound (default 64 MiB) is unchanged —
  this ADR sub-divides an already-bounded part for transport
  resumability, it does not change how large a part is allowed to be.
- An abandoned job's in-progress upload session (chunks PUT, never
  `MOVE`d because the job was cancelled or failed before retrying) is
  **not proactively cleaned up** server-side. Accepted, not fixed, for
  the same reason `ADR 0004`'s own accepted gaps are accepted: bounded
  in size (at most one part's worth of chunks per abandoned job), and a
  real Nextcloud/ownCloud deployment already runs its own background
  stale-upload garbage collection. A future session should verify this
  periodically rather than build dedicated cleanup plumbing now.
- `Nextcloud_Client` stays the only class that knows WebDAV specifics
  (MNT-03) — `Job_Orchestrator` calls one method
  (`upload_part( $local_path, $relative_path, $upload_id )`) and never
  branches on chunking vs. plain PUT itself, matching the adapter
  interface ADR 0004 already anticipated almost exactly
  (`upload_part( $local_path, $remote_path ): Upload_Result`).
- Verification (ADR 0006) is unchanged: the full re-download-and-rehash
  in `step_upload_parts()` still applies to the assembled file after
  `upload_part()` returns, not to individual chunks.
