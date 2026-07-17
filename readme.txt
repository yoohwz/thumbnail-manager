=== Thumbnail Manager ===
Contributors: yoohw
Tags: thumbnails, regenerate thumbnails, media library, image sizes, cleanup
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Regenerate WordPress thumbnails, control image sizes, and safely clean unused Media Library files with resumable, review-first jobs.

== Description ==

Thumbnail Manager is a WordPress thumbnail management plugin for regenerating image sizes, preventing thumbnail bloat, and safely cleaning unused generated files from the Media Library.

Use it after changing themes, updating WooCommerce image settings, adjusting registered image sizes, migrating a site, or discovering years of old thumbnail files. Thumbnail Manager lets you decide which sizes WordPress should generate, rebuild missing thumbnails, inspect cleanup candidates, and delete only an explicitly approved manifest.

The plugin is designed for both new sites and large, long-running Media Libraries. Persistent cursor-based jobs process work in batches, survive page reloads or network interruptions, and can be limited to multiple upload years or months.

= Key Features =

* Regenerate WordPress thumbnails for existing image attachments
* Generate only missing image sizes or force-regenerate selected media
* Disable unwanted registered image sizes for future uploads
* Review recommendations before changing thumbnail settings
* Prune unused thumbnails while preserving the sizes you choose to keep
* Scan first, review an immutable hashed manifest, then approve deletion separately
* Search and select multiple upload years or month folders in one Prune job
* Filter and paginate large deletion manifests with estimated storage totals
* Detect legacy thumbnail sizes stored in attachment metadata
* Report unmapped disk-only orphan files without deleting them by default
* Resume regeneration, recommendation, scan, and delete jobs after interruption
* Review active and recent jobs with retained audit information
* Use responsive admin screens that inherit the user's WordPress Color Scheme

= Safe WordPress Thumbnail Cleanup =

Prune Files uses a review-first workflow instead of deleting files immediately:

1. Choose the registered image sizes to keep.
2. Scan all uploads or select multiple year and month folders.
3. Review the immutable manifest and estimated storage total.
4. Confirm the reviewed manifest explicitly.
5. Delete approved generated files in resumable batches.

Original full-size uploads are protected. Paths are validated inside the WordPress uploads directory, and attachment metadata is updated when generated size files are removed.

= Built for Large Media Libraries =

Thumbnail Manager avoids a single long-running request for large operations. Persistent jobs, cursor-based attachment queries, bounded AJAX batches, folder-limited scans, operation locks, and per-item errors make long media maintenance tasks easier to monitor and resume.

The Prune folder picker groups upload directories by year, supports search and bulk selection, and automatically removes overlapping month selections when an entire year is selected.

= WooCommerce and Theme Changes =

Thumbnail Manager recognizes WooCommerce-related image sizes in Recommendations and helps you review them before disabling, regenerating, or pruning files. It is also useful after switching themes or removing plugins that registered custom image sizes.

= What Thumbnail Manager Does Not Do =

* It does not compress or change image quality.
* It does not replace an image optimization, WebP, CDN, or offload plugin.
* It does not delete original uploaded images.
* It does not automatically delete files without manifest review and approval.
* It does not unregister image sizes from the theme or plugin that registered them.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/thumbnail-manager/`, or install it through the WordPress Plugins screen.
2. Activate Thumbnail Manager.
3. Go to Tools > Thumbnail Manager.
4. Review Recommendations before changing image size settings or cleaning a production site.

== Usage ==

= Recommended Workflow =

1. Open Tools > Thumbnail Manager.
2. Run Recommendations and review which sizes appear necessary.
3. Open Thumbnail Sizes and save the sizes WordPress should generate for future uploads.
4. Run Regenerate to rebuild missing or selected thumbnails.
5. Open Prune Files and choose the sizes to keep.
6. Select All uploads or choose multiple upload folders.
7. Scan, review the manifest, and approve deletion only when the result is correct.

= Regenerate Thumbnails =

Use Regenerate after changing image dimensions, switching themes, changing WooCommerce image settings, migrating media, or restoring missing thumbnail files. Process all image attachments, the current year, a selected uploads folder, or specific attachment IDs.

Only Missing generates absent sub-sizes. Force Regenerate rebuilds metadata and selected sub-sizes from the original image path when WordPress provides one.

= Thumbnail Sizes =

Use Thumbnail Sizes to control which registered image sizes WordPress should generate for future uploads. Disabling a size does not remove existing files; use Prune Files when you are ready to scan and review old generated thumbnails.

= Recommendations =

Recommendations reviews registered sizes against protected WordPress sizes, WooCommerce-related sizes, attachment metadata, and common content references. Treat the result as an informed starting point and review it for your theme and site before applying changes.

= Prune Files =

Choose the image sizes to keep, decide whether to scan all uploads or selected year/month folders, and start Scan. Scanning builds a manifest only. Nothing is deleted until the scan finishes, the manifest is displayed, and an administrator explicitly approves it.

Optional orphan discovery includes metadata-backed legacy sizes in the review. Unmapped disk-only filename matches are reported and skipped by default.

== Frequently Asked Questions ==

= Can I regenerate thumbnails after changing WordPress image sizes? =

Yes. Save the desired Thumbnail Sizes, then run Regenerate. Only Missing rebuilds absent files, while Force Regenerate rebuilds the selected attachments more comprehensively.

= Can Thumbnail Manager clean unused thumbnails? =

Yes. Prune Files scans generated files for sizes you no longer want to keep, presents a reviewable manifest, and deletes only after explicit approval.

= Will it delete original images? =

No. Original full-size attachment files are protected. Cleanup candidates are validated under the uploads directory and checked against attachment metadata before deletion.

= Can I limit a Prune scan to several folders? =

Yes. Choose Selected folders, search by year or path such as `2024/08`, and select multiple years or months. Selecting an entire year automatically covers its month folders.

= Is it suitable for a large or long-running Media Library? =

Yes. Regeneration, recommendations, pruning, orphan discovery, manifest creation, and deletion use persistent jobs and bounded batches. Interrupted jobs can resume after a page reload or network error.

= What happens if I stop or close the page during a job? =

The job state is stored persistently. Reload the admin page to resume unfinished work. Using Stop cancels future batches while retaining the audit record for review.

= Does orphan discovery delete files missing from attachment metadata? =

No. Disk-only `-WxH` filename matches that cannot be mapped to attachment metadata are reported and skipped by default. Metadata-backed legacy sizes can be included in the reviewed manifest.

= Is Thumbnail Manager safe for WooCommerce sites? =

It detects WooCommerce-related sizes in Recommendations and lets you review every cleanup manifest. Because product image requirements vary by theme and store setup, verify the sizes your storefront uses before disabling or pruning them.

= Does it optimize, compress, or convert images to WebP? =

No. Thumbnail Manager controls image sizes, regeneration, and cleanup. Use a dedicated optimization or CDN plugin for compression, WebP/AVIF conversion, quality settings, or media offloading.

= Does disabling a size unregister it from my theme or plugin? =

No. Thumbnail Manager filters which registered sizes are generated for future uploads. The theme or plugin that originally registered the size remains unchanged.

== Technical Notes ==

* Uses `intermediate_image_sizes_advanced` to control future thumbnail generation
* Uses dedicated persistent job and job-item tables instead of storing full queues in transients
* Uses cursor-based AJAX queues for regeneration, recommendations, pruning, orphan discovery, manifest creation, and deletion
* Separates scan, immutable manifest review, explicit approval, and deletion
* Binds expiring job tokens and manifests to the current site and user
* Supports resume, server-side cancellation, operation locking, cleanup, and per-item error tracking
* Uses attachment metadata as the primary source for prune candidates
* Validates filesystem paths under the WordPress uploads directory
* Updates attachment metadata after generated thumbnail files are removed
* Prefers the original image path during force regeneration when WordPress provides one
* Requires the `manage_options` capability
* Runs per site on WordPress multisite

== Changelog ==

= 1.4.0 (Jul 17, 2026) =
* Added persistent job and job-item storage with ownership, expiry, cancellation, locking, resume, and per-item errors
* Split Prune Files into scan, immutable manifest review, explicit approval, and resumable deletion
* Added a five-step prune workflow, paginated manifest review, active and recent jobs, and responsive layouts
* Added searchable multi-folder selection for upload years and months
* Limited attachment and disk scans to the selected upload folders
* Made UI accents inherit the administrator's WordPress profile Color Scheme
* Batched orphan discovery and Recommendations with persisted cursors
* Updated Force Regenerate to prefer the original image source and preserve existing metadata
* Added automated safety, storage, coding standards, compatibility, Plugin Check, and CI coverage

See `changelog.txt` for the complete release history.

== Upgrade Notice ==

= 1.4.0 =
Persistent resumable jobs, explicit manifest approval, folder-limited scans, and a safer responsive workflow make large Media Library maintenance more reliable.

== Privacy ==

Thumbnail Manager does not collect, store, or transmit personal data to external services. All thumbnail scans, regeneration, recommendations, and cleanup operations run locally on your WordPress site.
