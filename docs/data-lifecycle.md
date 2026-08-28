# Thumbnail Manager Data Lifecycle

Thumbnail Manager retains persistent data by design when it is deactivated or its plugin files are deleted. This policy preserves resumable jobs, audit and recovery evidence, settings, and derived media-reference state for a later reinstall.

## Deactivation

Deactivation is non-destructive. It clears only the `yotm_cleanup_jobs` scheduled hook:

- a site deactivation clears the hook for the current site;
- a network deactivation clears the same hook across the sites returned by bounded WordPress Sites API queries and restores the caller's original blog context.

Site-topology changes and individual cron-clear failures are best-effort scheduling concerns. Deactivation does not introduce lifecycle locks or block normal runtime work.

Deactivation does not delete tables, options, transients, jobs, item audit or recovery rows, the media-source index, attachment metadata, uploads, generated thumbnails, `.yotm-regenerate-*` recovery files, or browser-local job tokens.

## Plugin deletion and reinstall

Thumbnail Manager intentionally provides no destructive uninstall cleanup. Deleting the plugin files retains:

- the `yotm_jobs`, `yotm_job_items`, and `yotm_media_sources` tables for every site;
- Thumbnail Manager settings, schema/index state, and the migration-failure transient;
- job history, item audit rows, and recovery evidence;
- all media, attachment metadata, and filesystem recovery state.

The short-lived migration-failure transient may expire naturally. Browser `localStorage` is outside server-side plugin control and may continue to contain job tokens.

After reinstall, retained database state is subject to the existing schema-readiness, expiry, ownership, and authorization rules. Retention does not bypass those checks or guarantee that an expired or unauthorized browser token can resume a job.

This policy intentionally trades database/privacy residue for a small, predictable deletion boundary that cannot destroy recovery evidence. Any future purge feature requires a separate Human-approved Controlled design, preferably as an explicit in-plugin administrative action while the runtime contracts are available.
