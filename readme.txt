=== Thumbnail Manager ===
Contributors: yoohw
Tags: thumbnails, regenerate thumbnails, media library, image sizes, cleanup
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Regenerate WordPress thumbnails, control image sizes, and safely clean generated files with resumable, review-first jobs.

== Description ==

[Product page](https://yoohw.com/product/thumbnail-manager/) | [Documentation](https://docs.yoohw.com/category/thumbnail-manager/) | [Support](https://workspace.yoohw.com/)

Thumbnail Manager helps regenerate WordPress image sizes, prevent future thumbnail bloat, and review generated files before cleanup. Use it after changing themes or WooCommerce image settings, migrating a site, or finding obsolete sizes in a long-running Media Library.

Large operations run as persistent, bounded jobs. They continue in batches, survive a page reload or network interruption, and use scoped processing where the workflow supports it.

= Thumbnail control and regeneration =

* Review every registered image size and decide which sizes WordPress should generate for future uploads.
* Generate only missing sizes or force-regenerate selected image attachments.
* Regenerate all media, current-year images, one uploads folder, or specific image attachments.
* Review conservative keep recommendations with explicit evidence and confidence based on protected size policies, attachment metadata, and common content references.
* Resume or cancel persistent jobs and review recent job results and per-item errors.

= Review-first cleanup =

Prune Files separates discovery from deletion:

1. Choose the image sizes to keep.
2. Scan all uploads or selected year and month folders.
3. Review the immutable, hashed manifest and estimated storage total.
4. Explicitly approve the reviewed manifest.
5. Delete only those approved generated files in resumable batches.

Original full-size uploads are protected. Candidate paths are validated inside the WordPress uploads directory, and attachment metadata is updated only when an exact metadata-backed generated size is removed.

Prune Files can optionally include verified disk-only legacy thumbnails for currently disabled sizes. A separate opt-in can also discover conservatively verified historical thumbnails from fixed image sizes that are no longer registered by the current theme or plugins. Unverified, ambiguous, sidecar, backup-like, or otherwise unmapped disk files remain preserved and do not enter the deletion manifest.

= Scope =

Thumbnail Manager controls image-size generation, regeneration, and cleanup. It does not compress images, configure a CDN, offload media, or unregister a size from the theme or plugin that originally registered it. Force Regenerate can follow WordPress image-editor output-format filters, including WebP or AVIF when supported by the site, while keeping original/full-size sources protected.

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

Yes. **Only Missing** creates absent sub-sizes and honors the sizes currently enabled in **Thumbnail Sizes**. **Force Regenerate** rebuilds the selected attachments more comprehensively from an available original image source and follows the same enabled-size policy.

Regenerate can process all media, current-year images, one uploads folder, or specific image attachments. Choose the narrowest useful scope before starting a large job.

= Can it clean unused generated thumbnails? =

Yes. Prune Files first builds a manifest for review. Nothing is deleted until the scan finishes and an administrator explicitly approves that manifest.

= Will it delete original images? =

No. Original full-size uploads are protected. Cleanup candidates must also pass path, ownership, source, and manifest checks.

= Can I scan several upload folders? =

Yes, in **Prune Files**. Search for and select multiple years or months. Selecting an entire year automatically covers its month folders.

= Is it suitable for a large Media Library? =

Yes. Recommendations, regeneration, scanning, manifest creation, and deletion use persistent jobs and bounded batches that can resume after interruption.

= What happens if I close the page during a job? =

The job state remains in the database. Reload the admin page to resume it. Cancelling stops future batches while retaining an audit record.

= Can Prune Files delete old disk-only thumbnails? =

Only when they can be verified conservatively. You can opt in to verified legacy thumbnails for currently disabled sizes, and separately opt in to verified historical thumbnails from sizes that are no longer registered. Unmapped filename matches, ambiguous files, sidecars, edit/backup-like siblings, and other files without sufficient ownership evidence are preserved by default.

= Is it safe for WooCommerce sites? =

The plugin recognizes WooCommerce-related sizes in Recommendations, but storefront requirements vary. Verify the sizes used by your store before disabling or pruning them.

= Does disabling a size unregister it from a theme or plugin? =

No. It changes which registered sizes WordPress generates for future uploads and regeneration. The original registration remains in place.

= What data remains after deactivation or plugin deletion? =

Deactivation clears Thumbnail Manager's scheduled cleanup task but retains settings, job history, recovery evidence, and media-reference data. Deleting the plugin files intentionally retains the same database state and does not delete attachment metadata, uploads, generated thumbnails, or recovery files. This retained state may be reused after reinstall, subject to the plugin's normal expiry, ownership, and authorization checks. Remove retained database data separately only if your site's privacy and recovery requirements allow it.

== Changelog ==

= 1.5.1 (Aug 30, 2026) =

* Fixed regeneration so future uploads, Generate Missing Sizes Only, Force Regenerate, and Save + Regenerate consistently honor the sizes enabled in Thumbnail Sizes
* Added review-first cleanup for verified disk-only thumbnails from currently disabled sizes, plus a separate conservative opt-in for historical sizes that are no longer registered
* Fixed WebP/AVIF Force Regenerate cleanup so the exact obsolete metadata-backed JPEG/PNG derivative is removed after a successful replacement while unrelated files and sidecars remain protected
* Hardened historical Prune resume and replay handling, including partial evidence cleanup and legitimate no-op database updates
* Improved Prune scan errors so server/application failures are distinguished from network loss while the persistent job remains available to reload and resume
* Improved the Prune Files size table layout and marked WordPress core image sizes with the WordPress icon

See `changelog.txt` for the complete release history.

== Upgrade Notice ==

= 1.5.1 =

Fixes enabled-size regeneration, adds conservative reviewed cleanup for legacy and historical thumbnails, improves format-conversion cleanup, and hardens Prune resume behavior.

= 1.5.0 =

Improves regeneration reliability, cleanup safety, recommendations, large-library performance, and Regenerate scope selection.

= 1.4.0 =

Adds persistent resumable jobs, explicit manifest approval, folder-limited scans, and a safer workflow for large Media Libraries.

== Privacy ==

Thumbnail Manager does not collect, store, or transmit personal data to external services. Thumbnail analysis, regeneration, recommendations, and cleanup run locally on the WordPress site.

Persistent settings, job history, audit/recovery rows, and derived media-reference data remain in the site's database after deactivation or plugin deletion. Browser-local job tokens may also remain in the administrator's browser. Review and remove retained data separately when required by the site's privacy policy.
