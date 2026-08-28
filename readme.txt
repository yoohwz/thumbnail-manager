=== Thumbnail Manager ===
Contributors: yoohw
Tags: thumbnails, regenerate thumbnails, media library, image sizes, cleanup
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Regenerate WordPress thumbnails, control image sizes, and safely clean generated files with resumable, review-first jobs.

== Description ==

[Product page](https://yoohw.com/product/thumbnail-manager/) | [Documentation](https://docs.yoohw.com/category/thumbnail-manager/) | [Support](https://workspace.yoohw.com/)

Thumbnail Manager helps regenerate WordPress image sizes, prevent future thumbnail bloat, and review generated files before cleanup. Use it after changing themes or WooCommerce image settings, migrating a site, or finding obsolete sizes in a long-running Media Library.

Large operations run as persistent, cursor-based jobs. They continue in bounded batches, survive a page reload or network interruption, and can be limited to selected upload years or months.

= Thumbnail control and regeneration =

* Review every registered image size and decide which sizes WordPress should generate for future uploads.
* Generate only missing sizes or force-regenerate selected image attachments.
* Process all images, selected attachments, the current year, or selected upload folders.
* Review conservative keep recommendations with explicit evidence and confidence based on protected size policies, attachment metadata, and common content references.
* Resume or cancel persistent jobs and review recent job results and per-item errors.

= Review-first cleanup =

Prune Files separates discovery from deletion:

1. Choose the image sizes to keep.
2. Scan all uploads or selected year and month folders.
3. Review the immutable, hashed manifest and estimated storage total.
4. Explicitly approve the reviewed manifest.
5. Delete only those approved generated files in resumable batches.

Original full-size uploads are protected. Candidate paths are validated inside the WordPress uploads directory, and attachment metadata is updated when a generated size is removed.

Metadata-backed legacy sizes can be included in the review. Disk-only files that match a `-WIDTHxHEIGHT` pattern but cannot be mapped reliably to attachment metadata are reported and skipped by default.

= Scope =

Thumbnail Manager controls image-size generation, regeneration, and cleanup. It does not compress images, convert them to WebP or AVIF, configure a CDN, offload media, or unregister a size from the theme or plugin that originally registered it.

Recommendations are an informed starting point, not proof that a size is used or unused. Missing scan evidence leaves the current size setting unchanged. Verify the sizes used by the active theme, plugins, WooCommerce templates, and custom code before disabling generation or approving deletion.

== Installation ==

1. Install the plugin through the WordPress Plugins screen, or upload it to `/wp-content/plugins/thumbnail-manager/`.
2. Activate Thumbnail Manager.
3. Go to **Tools > Thumbnail Manager**.
4. Run Recommendations and review the result for your theme and plugins.
5. Configure future Thumbnail Sizes before regenerating or cleaning files.
6. Back up the uploads directory before approving cleanup on a production site.

== Frequently Asked Questions ==

= Can I regenerate thumbnails after changing image sizes? =

Yes. **Only Missing** creates absent sub-sizes. **Force Regenerate** rebuilds the selected attachments more comprehensively from an available original image source.

= Can it clean unused generated thumbnails? =

Yes. Prune Files first builds a manifest for review. Nothing is deleted until the scan finishes and an administrator explicitly approves that manifest.

= Will it delete original images? =

No. Original full-size uploads are protected. Cleanup candidates must also pass path and attachment checks.

= Can I scan several upload folders? =

Yes. Search for and select multiple years or months. Selecting an entire year automatically covers its month folders.

= Is it suitable for a large Media Library? =

Yes. Recommendations, regeneration, scanning, manifest creation, and deletion use persistent jobs and bounded batches that can resume after interruption.

= What happens if I close the page during a job? =

The job state remains in the database. Reload the admin page to resume it. Cancelling stops future batches while retaining an audit record.

= Does orphan discovery delete disk-only files? =

No. Unmapped disk-only filename matches are reported and skipped by default. Only approved, validated manifest items are deleted.

= Is it safe for WooCommerce sites? =

The plugin recognizes WooCommerce-related sizes in Recommendations, but storefront requirements vary. Verify the sizes used by your store before disabling or pruning them.

= Does disabling a size unregister it from a theme or plugin? =

No. It changes which registered sizes WordPress generates for future uploads. The original registration remains in place.

= What happens to data when I deactivate or uninstall the plugin? =

Deactivation keeps Thumbnail Manager settings, jobs, audit/recovery records, and media while removing its scheduled cleanup task. Uninstall removes only the plugin's database tables, settings, transient, and scheduled task when the complete site or network scope is safely quiescent. If active work, unresolved recovery evidence, ambiguous storage, or a bounded large-network limit prevents a safe purge, the database state is retained for reinstall and inspection. Uninstall never deletes attachment records, upload files, generated thumbnails, or private regeneration recovery files.

== Changelog ==

= 1.4.0 (Jul 17, 2026) =

* Added persistent job and job-item database storage with ownership, expiry, cancellation, cleanup, locking, resume, and per-item errors
* Split Prune Files into scan, immutable hashed manifest review, explicit approval, and resumable deletion
* Added a five-step prune workflow, paginated manifest review, active and recent jobs, inline delete approval, and responsive layouts
* Added a searchable, grouped multi-folder picker for limiting Prune scans to several upload years or months
* Limited attachment queries and disk orphan scans to the selected upload folders
* Made UI accents inherit the administrator's WordPress profile Color Scheme
* Changed Stop to retain cancelled job and item audit records and prevent late batches from overwriting that state
* Batched disk orphan discovery and recommendation scans with persisted cursors
* Updated force regeneration to prefer the original image source and preserve existing metadata
* Added PHPUnit safety and storage tests, WordPress Coding Standards, PHP compatibility checks, Plugin Check, and a PHP/WordPress CI matrix

See `changelog.txt` for the complete release history.

== Upgrade Notice ==

= 1.4.0 =

Adds persistent resumable jobs, explicit manifest approval, folder-limited scans, and a safer workflow for large Media Libraries.

== Privacy ==

Thumbnail Manager does not collect, store, or transmit personal data to external services. Thumbnail analysis, regeneration, recommendations, and cleanup run locally on the WordPress site.

Persistent job records can include site/user ownership, media paths, errors, and recovery evidence. Browser-local job tokens support resume. Deactivation retains this state. A provably safe uninstall removes plugin-owned database state; an unsafe or ambiguous uninstall retains it so a later reinstall can recover or inspect the job. Media files and attachment metadata are not uninstall cleanup targets.
