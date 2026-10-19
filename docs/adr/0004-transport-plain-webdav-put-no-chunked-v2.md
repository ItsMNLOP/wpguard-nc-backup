# ADR 0004: Plain WebDAV PUT with bounded part size, no chunked upload v2 (MVP)

**Status:** Accepted (MVP)
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
string), then PUT to Nextcloud by streaming the temp file's handle via
`wp_remote_request`'s `stream`/raw-body-from-file support through a thin
transport adapter. The temp file is deleted immediately after a successful
verified upload of that part.

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
- No resumability of a single in-flight PUT if interrupted mid-part; the
  whole part is retried from the start (bounded by the part-size cap, so
  retry cost is bounded too). Full resumability is Phase 2 (chunked v2).
- Verification (ADR 0006) still applies per part.
