# ADR 0003: Admin-AJAX step-driven job runner, no Action Scheduler dependency

**Status:** Accepted (MVP)
**Date:** 2026-10-19

## Context

A common WordPress-plugin convention favors WP_Background_Process or
Action Scheduler for non-blocking jobs. The brief's MVP scope is manual, on-demand, single-admin
backups only; no scheduling (JOB-02/04/05 are explicitly deferred to Phase 2).
Action Scheduler is a ~15 MB third-party library; bundling it only to drive a
synchronous, admin-initiated, single-user action is disproportionate for
MVP and the brief favors "native WordPress facilities... compared against
vetted libraries" rather than defaulting to a popular dependency.

## Decision

The MVP job runner is a persistent state machine (options-backed job record,
one row per job, keyed by job ID) advanced by repeated `admin-ajax.php`
requests issued from the admin page's JS while the operator has the page
open, **plus** a WP-Cron single-event fallback (`wp_schedule_single_event`,
fired a few seconds out) that advances the job even if the browser tab is
closed, so an interrupted browser session does not strand a job mid-run.
Each step processes one bounded unit of work (e.g., one batch of DB rows, one
file, one archive part upload) and persists a checkpoint before returning,
so the job survives PHP process death between steps. This satisfies JOB-01
and JOB-03 without Action Scheduler.

The same step-advance function is invoked directly by WP-CLI
(`wp wpguard-nc-backup run`, used by the E2E harness) for deterministic,
synchronous test execution without relying on AJAX/cron timing.

## Consequences

- No new Composer dependency for MVP.
- Job progress depends on either an open admin tab or WP-Cron firing, which
  on a very low-traffic site can stall; this is the same documented
  WP-Cron limitation as JOB-02, disclosed in `docs/operations.md`.
- If Phase 2 adds real scheduling, Action Scheduler or a real system-cron
  trigger can replace the stepping mechanism behind the same orchestrator
  interface (`Job_Orchestrator::advance( $job_id )`) without changing the
  state machine's checkpoint format.

## Alternatives considered

- WP_Background_Process (Delayed_DB_Updates pattern): adds its own polling
  loopback-request mechanism with its own reliability caveats on
  loopback-blocked hosts; no clear advantage over direct AJAX stepping for a
  foreground, admin-initiated MVP action.
- Action Scheduler: deferred to Phase 2 if/when real unattended scheduling is
  built, where its persistence and retry semantics earn their weight.
