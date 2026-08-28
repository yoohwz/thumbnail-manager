# Thumbnail Manager Data Lifecycle

This document defines what Thumbnail Manager retains or removes when the plugin is deactivated or explicitly uninstalled. It does not authorize a release or any media cleanup.

## Persistent artifacts

Each WordPress site owns three Thumbnail Manager tables: `yotm_jobs`, `yotm_job_items`, and `yotm_media_sources`, each under that site's exact table prefix. The plugin also owns the `yotm_disabled_sizes`, `yotm_job_db_version`, `yotm_media_source_index_dirty`, and `yotm_media_reference_index_state` options; the `yotm_job_db_migration_failure` transient; and the `yotm_cleanup_jobs` cron hook.

The temporary `yotm_uninstall_cleanup_intent` option is versioned coordination evidence. It exists only after a complete safe uninstall preflight and remains only when exact cleanup is interrupted.

Browser `localStorage` job tokens are client-local and cannot be enumerated or deleted by server uninstall code.

## Deactivation

Deactivation is non-destructive. It clears only `yotm_cleanup_jobs` in the current site scope, or in bounded batches across all sites during network deactivation. Tables, options, transients, jobs, audit/recovery evidence, browser tokens, attachment metadata, and files are retained.

Reactivation verifies or restores current storage readiness and schedules cleanup again.

## Uninstall policy

Explicit uninstall uses conditional safe purge with retain-on-unsafe behavior:

1. Snapshot the complete site/network scope in bounded keyset pages.
2. Prove exact schema ownership, job quiescence, absence of `processing` items, and resolution of every persisted prune/Force journal.
3. Repeat the full safety proof immediately before the cleanup-intent boundary.
4. Only after both passes succeed, persist exact scope-bound intents and remove the allowlisted database/scheduled artifacts.

Any active job, processing item, unresolved or unprovable journal, database read error, ambiguous/partial schema without a valid interruption intent, changing site scope, or execution bound selects retain-all. WordPress may continue deleting plugin files; uninstall does not use `wp_die`, a fatal error, or a return value as a deletion veto.

Only a structurally valid terminal `done` prune V1 journal with `outcome=delete_reconciled`, or terminal `done` Force V1 journal with `phase=cleanup_complete`, is resolved evidence. Journal inspection covers every item status. Failed, skipped, queued, processing, malformed, unknown, or contradictory recovery evidence retains all.

The reviewed one-shot uninstall limits are 100 sites, site pages of 25, item pages of 250, 10,000 item rows per complete scope pass, and a 10-second read-only preflight budget. Exceeding a bound retains all.

## Exact deletion authority

Cleanup uses only the exact tables, four options, migration transient, cron hook, and temporary intent named above. It never uses a `yotm_*` wildcard. Similar-name data is outside its authority.

Attachment rows/postmeta, originals, generated thumbnails, uploads, and `.yotm-regenerate-*` directories/files are never uninstall cleanup targets. The staging directories are Thumbnail Manager-created recovery artifacts intentionally retained under the media-safety contract.

## Interruption and reinstall

An unsafe preflight changes no persistent data. A successful purge leaves activation to create fresh storage; stale browser tokens then fail job lookup.

MySQL DDL and options across sites are not one cross-site transaction. If infrastructure fails after the approved mutation boundary, the exact cleanup intent remains and a later explicit uninstall after reinstall can resume idempotently. Normal activation remains fail-closed on unexplained or interrupted partial schema and does not recreate tables over it automatically.

When unsafe state was retained, reinstall can inspect or resume unexpired, correctly authorized jobs. Retention does not recreate expired authorization or weaken job user/site ownership.
