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
* Check selected images for missing enabled thumbnails, including files missing on disk while metadata still exists.
* Explicitly repair only missing sizes while preserving existing thumbnail files and the current full image.
* Select images using the native Media Library picker, individual Check thumbnails links, or the Media Library list-view bulk action.
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

= Can I use Thumbnail Manager with Media Optimizer? =

The **Media Optimizer** tab introduces the free companion, shows its installation and compatibility state, and links to the standard WordPress screens to review installation or activation. Opening the tab does not install, activate or start processing media.

With compatible versions active, Thumbnail Manager prevents Media Optimizer from automatically processing files during thumbnail transactions. After a completed Repair or Force regeneration job, open its saved result and choose “Create WebP/AVIF for completed thumbnails”. Media Optimizer previews the exact successful generated files and requires a separate Run action. This handoff excludes full-size originals and unrelated thumbnail sizes, even when original optimization is enabled in Media Optimizer.

An unfinished Thumbnail Manager job blocks Media Optimizer writes. Complete or cancel the job before optimizing. Expired results, changed files and missing evidence require a new check; they never expand into a whole-library run. Older active Media Optimizer versions must be updated before Thumbnail Manager media operations can run together. Prune itself continues to preserve optimizer sidecars. After a completed Prune, choose “Review leftover WebP/AVIF sidecars” in its saved result. Media Optimizer prepares a separate preview and approval for each page of up to 100 deleted thumbnail files, then deletes at most eight verified sidecars per explicit batch. Only unchanged sidecars with recorded Optimizer ownership can qualify; legacy, referenced, replaced or unverifiable files remain preserved. Opening the preview never approves or deletes files.

Source optimization and restore attempts in Media Optimizer also mark older thumbnail observations and Recommendations for a fresh check. This is conservative: a partial or no-change attempt can still trigger the warning. Saved outcomes are retained; no thumbnails are regenerated automatically. Older results without a source revision snapshot disclose that freshness cannot be confirmed.



= Can I regenerate thumbnails after changing image sizes? =

Yes. **Check and repair missing thumbnails** first inspects the selected images and the sizes currently enabled in **Thumbnail Sizes**. Review the results, then choose **Repair missing thumbnails**. Repair uses the current full image, honors edits, and preserves existing thumbnails and unrelated metadata. Sizes that cannot be made without enlarging the source are not required. **Force Regenerate** rebuilds the selected attachments more comprehensively from an available original image source and follows the enabled-size policy.

Regenerate can process all media, current-year images, one uploads folder, or specific image attachments. Choose the narrowest useful scope before starting a large job.

= How do I check and repair selected images? =

In **Media > Library**, use **Check thumbnails** on an image, or select images in list view and choose the **Check thumbnails** bulk action. In **Tools > Thumbnails > Regenerate**, you can also choose **Selected images > Choose images** and use the familiar Media Library search and filters. Select up to 500 images at a time; folder and broader scopes run in bounded batches.

Choose **Check thumbnails**, review the per-image and per-size results, then explicitly choose **Repair missing thumbnails**. Navigation and checking do not modify media. Repair derives the selection from the saved check and rechecks current files and enabled sizes before generating anything. Recent jobs lets you reopen saved check and repair results.

Checks cover currently enabled registered thumbnail sizes and local upload files. A present file is not a full corruption audit, and the report does not verify frontend pages, external references or CDN copies. Disabled and historical unregistered sizes are preserved and are not recreated. Missing original/full files, missing or malformed complete attachment metadata, unsupported formats and unsafe paths require the suggested manual action; the plugin cannot reconstruct a lost original. An occupied, unreferenced destination is preserved rather than overwritten.

Jobs continue only while the administration page sends batches. After a network interruption or page reload, return to the plugin page to resume the saved active job. Stopping keeps completed work and its audit record; in-flight transactions may need recovery before stopping finishes. After expiry or after resolving a blocked source/storage issue, run a new check. Result retention is temporary, not a permanent media audit.

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
