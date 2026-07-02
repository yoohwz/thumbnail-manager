=== Thumbnail Manager ===
Contributors: yoohw
Tags: thumbnails, regenerate, media-library, image-sizes, cleanup
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Regenerate WordPress thumbnails, disable image sizes, and safely clean unused media library thumbnails with dry-run previews.

== Description ==

Thumbnail Manager helps you control WordPress thumbnail generation, regenerate image sizes, and clean unused thumbnail files from the Media Library.

Use it after changing themes, installing WooCommerce, disabling custom image sizes, or cleaning up years of generated media files. The plugin focuses on predictable thumbnail management: choose which image sizes should stay enabled, rebuild the thumbnails you need, preview cleanup results, then delete only reviewed generated files.

Cleanup is metadata-aware and safety-focused. Thumbnail Manager validates uploads paths, protects original full-size images, runs prune/delete actions in batches, and updates attachment metadata after deleted thumbnail sizes are removed.

= Key Features =

* Regenerate WordPress thumbnails for existing image attachments
* Generate only missing thumbnails or force-regenerate all selected images
* Disable unwanted registered image sizes for future uploads
* Prune unused thumbnails while keeping selected sizes
* Run Dry-run previews before deleting generated thumbnail files
* Detect legacy thumbnail sizes stored in attachment metadata
* Report disk-only orphan matches without deleting unmapped files by default
* Scan recommendations based on protected sizes, WooCommerce-related sizes, generated metadata, and common content references
* Process large Media Libraries with cursor-based AJAX queues and folder scoping
* Manage everything from Tools > Thumbnail Manager

= Common Use Cases =

* Clean WordPress thumbnail bloat after theme or page builder changes
* Regenerate thumbnails after changing Media Settings or custom image sizes
* Reduce WooCommerce image storage by reviewing generated product thumbnails
* Disable image sizes that should no longer be created for new uploads
* Rebuild missing thumbnails after migration, restore, or failed image processing
* Audit legacy thumbnail sizes left behind by old themes and plugins

= Safety First =

Thumbnail Manager is designed for controlled cleanup, not blind filesystem deletion.

* Original full-size images are not deleted
* Delete mode requires a fresh delete token and a completed scan
* Dry-run mode never deletes files
* Cleanup targets files from attachment metadata
* Disk-only orphan matches are reported, not deleted by default
* Paths are validated under the WordPress uploads directory before deletion
* Attachment metadata is updated after generated thumbnail sizes are removed

= What It Does Not Do =

* It does not compress or optimize image quality
* It does not replace image optimization or CDN plugins
* It does not automatically delete media without confirmation
* It does not delete original uploaded images
* It does not unregister image sizes from themes or plugins

== Installation ==

1. Upload the plugin to `/wp-content/plugins/thumbnail-manager/` or install it from WordPress.org.
2. Activate the plugin in WordPress.
3. Go to Tools > Thumbnail Manager.

== Usage ==

= Recommended Workflow =

1. Open Tools > Thumbnail Manager.
2. Scan Recommendations to review likely keep/remove sizes.
3. Apply recommended sizes if they match your site.
4. Open Thumbnail Sizes and save the sizes you want generated for future uploads.
5. Run Regenerate to rebuild missing or selected thumbnails.
6. Open Prune Files and run Dry-run before any deletion.
7. Review matched files, then switch to Delete only when the preview is correct.

= Regenerate Thumbnails =

Use Regenerate when you need to rebuild WordPress image sizes for existing attachments. You can process all media, the current year, a specific uploads folder, or specific attachment IDs.

= Prune Files =

Use Prune Files to clean generated thumbnails for sizes you no longer want to keep. Always run Dry-run first. If orphan discovery is enabled, metadata-backed legacy sizes can be included, while unmapped disk-only matches are reported for review.

= Thumbnail Sizes =

Use Thumbnail Sizes to control which registered image sizes WordPress should generate for future uploads. This setting does not remove existing files until you run Prune Files.

== Frequently Asked Questions ==

= Can I regenerate WordPress thumbnails after changing image sizes? =

Yes. Use the Regenerate tab after changing Media Settings, switching themes, enabling WooCommerce image sizes, or saving different thumbnail size settings.

= Can this clean unused thumbnails from the Media Library? =

Yes. Use Prune Files to select the sizes you want to keep, run Dry-run, review the matched generated files, and then delete the reviewed thumbnail files in batches.

= Will Thumbnail Manager delete original images? =

No. Original full-size attachment files are protected. The plugin targets generated thumbnail files from attachment metadata and validates paths before deletion.

= Does orphan discovery delete files that are not in WordPress metadata? =

No. Disk-only `-WxH` matches that cannot be mapped to attachment metadata are reported and skipped by default. Metadata-backed legacy sizes can be cleaned after review.

= Is it safe for WooCommerce sites? =

Thumbnail Manager detects WooCommerce-related thumbnail sizes in recommendations and lets you review them before disabling, regenerating, or pruning. Always run Dry-run before deleting thumbnails on a live store.

= Is it safe for large Media Libraries? =

Regenerate and prune scans use cursor-based queues, AJAX batches, progress tracking, and optional folder scoping. Disk-only orphan reporting still depends on the size of the selected uploads folder, so start with a smaller folder on very large sites.

= Does it optimize or compress images? =

No. Thumbnail Manager controls image sizes, regeneration, and cleanup. Use a dedicated image optimization plugin for compression, WebP conversion, CDN, or quality changes.

= Does it unregister image sizes from themes or plugins? =

No. It prevents selected sizes from being generated for future uploads through WordPress filters. The theme or plugin that registered the size remains unchanged.

== Technical Notes ==

* Uses `intermediate_image_sizes_advanced` to control future thumbnail generation
* Uses cursor-based AJAX queues for regenerate and prune scans
* Uses attachment metadata for prune candidates
* Validates filesystem paths under the uploads directory before deletion
* Updates attachment metadata after generated thumbnail files are removed
* Requires the `manage_options` capability
* Works per site on multisite installations

== Changelog ==

= 1.3 (Jun 12, 2026) =
* Hardened prune/delete safety checks for tokens, scan completion, uploads path validation, and original image protection
* Refactored prune into a metadata-aware cursor scan instead of filesystem-wide regex deletion
* Updated delete batches to remove stale attachment size metadata after thumbnail files are deleted
* Added disk-only orphan reporting while keeping unmapped files out of the delete queue by default
* Escaped dynamic admin JavaScript output, localized admin strings, and improved ARIA states
* Added asset versioning and WP-CLI prune safety smoke tests

= 1.2 (May 9, 2026) =
* Added Recommendations tab for safer thumbnail cleanup decisions
* Added WooCommerce-aware size recommendations and one-click apply workflow
* Added scoped regenerate processing, force regenerate, and only-missing options
* Improved regenerate batching and admin progress handling

= 1.1 (Mar 19, 2026) =
* Added Regenerate Thumbnails with batch processing
* Improved the Sizes, Regenerate, and Prune workflow

= 1.0.1 (Dec 3, 2025) =
* Added translation support
* Improved UI labels and formatting
* Added accessibility improvements

= 1.0 (Oct 5, 2025) =
* Initial release
* Added Prune Files with Dry-run and batch deletion
* Added orphan detection
* Added controls for future thumbnail generation

== Upgrade Notice ==

= 1.3 =
Safety release with metadata-aware prune scanning, stricter delete guards, stale metadata cleanup, admin JS escaping, accessibility improvements, and prune safety smoke tests.

== Privacy ==

Thumbnail Manager does not collect, store, or transmit personal data. All thumbnail scans, regeneration actions, and cleanup actions run locally on your WordPress site.
