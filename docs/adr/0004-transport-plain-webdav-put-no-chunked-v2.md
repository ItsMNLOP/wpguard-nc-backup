# ADR 0004: Plain WebDAV PUT with bounded part size, no chunked upload v2 (MVP)

**Status:** Accepted (MVP) — **amended by ADR 0010** (2026-10-22), which
adds real resumable WebDAV chunking v2 for parts bigger than one chunk,
on servers that support it (Nextcloud, ownCloud; OpenCloud falls back
to this ADR's plain-PUT path, confirmed live that it doesn't support
chunking v2 at all). `PART_SIZE_BYTES` and everything else below is
otherwise unchanged.
**Date:** 2026-10-19

## Context

TRN-04 (chunked upload v2) is tagged [H] (hardening/Phase 2), not MVP. The
WordPress HTTP API (`wp_remote_request`) passes the request body as a single
in-memory string for a plain PUT; it does not expose a low-level streaming
upload primitive. Loading a large archive fully into PHP memory to PUT it
violates NFR-06.

## Decision

For MVP, the archive/manifest/SQL-export writer splits output into **bounded,
configurably-sized parts** (default 64 MiB, admin-configurable within a safe
range) as separate files (`files.part-NNNN.zip`, `database.sql.gz` is one
part unless it itself exceeds the bound, in which case it is also split).
Each part is written to a local temp file first (never built fully in a PHP
string during generation — the archive/SQL writers append to the file
incrementally). **Correction made during implementation:** the WordPress
HTTP API (`wp_remote_request`) has no true streaming-upload primitive — its
`stream`/`filename` options only apply to downloading a *response* body to a
file (used for read-back verification, ADR 0006), not to sending a request
body from a file without buffering it. Uploading therefore does read the
whole part's bytes into a PHP string (`file_get_contents()`) immediately
before the PUT call. This is still bounded — by the configurable part-size
cap (default 64 MiB), not by total backup size — which is what NFR-06
actually requires ("bounded memory," not "zero additional memory"). A true
streaming PUT (manual `curl_init()` + `CURLOPT_PUT` + `CURLOPT_INFILE`,
bypassing `wp_remote_request`) is noted as a Phase 2 hardening candidate if
smaller part sizes ever prove insufficient on very memory-constrained hosts.
The temp file is deleted immediately after a successful verified upload of
that part.

This keeps peak memory bounded by the part size, not by total backup size,
and keeps the transport adapter (`Nextcloud_Client`) as the single place that
knows about WebDAV specifics, per MNT-03, so chunked upload v2 can be added
later as an alternate code path behind the same adapter interface
(`upload_part( $local_path, $remote_path ): Upload_Result`) without touching
callers.

## Consequences

- Large single files (e.g., a media file bigger than the part bound) are
  still split at the archive layer, not the transport layer, in MVP. This is
  simpler but means a single huge file forces smaller part sizes for the rest
  of that backup. Documented as a known MVP limitation.
- ~~No resumability of a single in-flight PUT if interrupted mid-part; the
  whole part is retried from the start (bounded by the part-size cap, so
  retry cost is bounded too). Full resumability is Phase 2 (chunked v2).~~
  **Resolved by ADR 0010** (2026-10-22) on servers that support WebDAV
  chunking v2 (confirmed live: Nextcloud, ownCloud); OpenCloud still has
  this exact limitation, since it doesn't support chunking v2 at all.
- Verification (ADR 0006) still applies per part.
