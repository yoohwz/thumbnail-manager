<?php
/**
 * Thumbnail Manager administration assets and localization.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue assets only on the Thumbnail Manager administration page.
 *
 * @param string $hook Current administration page hook.
 */
function yotm_admin_enqueue_assets( $hook ) {
	// Load only on Tools → Prune Thumbnails.
	if ( 'tools_page_yo-manage-thumbnails' !== $hook ) {
		return;
	}

	wp_enqueue_media();

	// CSS.
	wp_register_style(
		'yotm-prune-admin',
		YOTM_PLUGIN_URL . 'css/style.css',
		array(),
		file_exists( YOTM_PLUGIN_PATH . 'css/style.css' ) ? (string) filemtime( YOTM_PLUGIN_PATH . 'css/style.css' ) : YOTM_VERSION
	);
	wp_enqueue_style( 'yotm-prune-admin' );

	// JS.
	wp_register_script(
		'yotm-prune-admin',
		YOTM_PLUGIN_URL . 'js/admin.js',
		array( 'jquery' ),
		file_exists( YOTM_PLUGIN_PATH . 'js/admin.js' ) ? (string) filemtime( YOTM_PLUGIN_PATH . 'js/admin.js' ) : YOTM_VERSION,
		true
	);
	wp_register_script(
		'yotm-prune-admin-prune',
		YOTM_PLUGIN_URL . 'js/admin-prune.js',
		array( 'yotm-prune-admin' ),
		file_exists( YOTM_PLUGIN_PATH . 'js/admin-prune.js' ) ? (string) filemtime( YOTM_PLUGIN_PATH . 'js/admin-prune.js' ) : YOTM_VERSION,
		true
	);
	wp_register_script(
		'yotm-prune-admin-recommendations',
		YOTM_PLUGIN_URL . 'js/admin-recommendations.js',
		array( 'yotm-prune-admin' ),
		file_exists( YOTM_PLUGIN_PATH . 'js/admin-recommendations.js' ) ? (string) filemtime( YOTM_PLUGIN_PATH . 'js/admin-recommendations.js' ) : YOTM_VERSION,
		true
	);
	wp_register_script(
		'yotm-prune-admin-regenerate',
		YOTM_PLUGIN_URL . 'js/admin-regenerate.js',
		array( 'yotm-prune-admin' ),
		file_exists( YOTM_PLUGIN_PATH . 'js/admin-regenerate.js' ) ? (string) filemtime( YOTM_PLUGIN_PATH . 'js/admin-regenerate.js' ) : YOTM_VERSION,
		true
	);
	wp_register_script(
		'yotm-prune-admin-sizes',
		YOTM_PLUGIN_URL . 'js/admin-sizes.js',
		array( 'yotm-prune-admin' ),
		file_exists( YOTM_PLUGIN_PATH . 'js/admin-sizes.js' ) ? (string) filemtime( YOTM_PLUGIN_PATH . 'js/admin-sizes.js' ) : YOTM_VERSION,
		true
	);
	wp_enqueue_script(
		'yotm-job-results',
		YOTM_PLUGIN_URL . 'js/admin-results.js',
		array( 'yotm-prune-admin' ),
		(string) filemtime( YOTM_PLUGIN_PATH . 'js/admin-results.js' ),
		true
	);
	wp_enqueue_script( 'yotm-prune-admin' );
	wp_enqueue_script( 'yotm-prune-admin-prune' );
	wp_enqueue_script( 'yotm-prune-admin-recommendations' );
	wp_enqueue_script( 'yotm-prune-admin-regenerate' );
	wp_enqueue_script( 'yotm-prune-admin-sizes' );

	// Pass data to JS.
	wp_localize_script(
		'yotm-prune-admin',
		'YOTM',
		array(
			'ajaxurl'                  => admin_url( 'admin-ajax.php' ),
			'nonce'                    => wp_create_nonce( 'yotm_prune_nonce' ),
			'mediaSelection'           => yotm_admin_requested_media_selection(),
			'siteId'                   => get_current_blog_id(),
			'userId'                   => get_current_user_id(),
			'registeredSizesSignature' => function_exists( 'yotm_recommend_registered_sizes_signature' )
				? yotm_recommend_registered_sizes_signature( yotm_get_registered_sizes() )
				: '',
			'i18n'                     => array(
				'viewResultsReport'               => __( 'View results / download report', 'thumbnail-manager' ),
				'resultQueued'                    => __( 'Queued', 'thumbnail-manager' ),
				'resultRefresh'                   => __( 'Refresh results', 'thumbnail-manager' ),
				'resultInspected'                 => __( 'Inspected successfully:', 'thumbnail-manager' ),
				'jobFiles'                        => __( 'files', 'thumbnail-manager' ),
				'jobImages'                       => __( 'images', 'thumbnail-manager' ),
				'jobScanRecords'                  => __( 'scan records', 'thumbnail-manager' ),
				'recommendationSampleLimits'      => __( 'Up to five accessible content examples are shown per size. The scan covers IDs present when it began; new or changed content may be missed.', 'thumbnail-manager' ),
				'resultPreservedSizes'            => __( 'Preserved because current size policy excludes them:', 'thumbnail-manager' ),
				'resultFilter'                    => __( 'Show results', 'thumbnail-manager' ),
				'resultAll'                       => __( 'All items', 'thumbnail-manager' ),
				'resultUnsuccessful'              => __( 'Unsuccessful items', 'thumbnail-manager' ),
				'resultFailed'                    => __( 'Failed', 'thumbnail-manager' ),
				'resultSkipped'                   => __( 'Skipped', 'thumbnail-manager' ),
				'resultExport'                    => __( 'Download JSON report', 'thumbnail-manager' ),
				'resultClose'                     => __( 'Close results', 'thumbnail-manager' ),
				'resultDefaultSizes'              => __( 'Default size policy for this run', 'thumbnail-manager' ),
				'resultTitle'                     => __( 'Saved job results', 'thumbnail-manager' ),
				'resultSucceeded'                 => __( 'Successful:', 'thumbnail-manager' ),
				'resultCandidateBytes'            => __( 'Candidate bytes (estimate):', 'thumbnail-manager' ),
				'resultFreedBytes'                => __( 'Recorded bytes freed:', 'thumbnail-manager' ),
				'resultCreated'                   => __( 'Started (UTC):', 'thumbnail-manager' ),
				'resultUpdated'                   => __( 'Updated (UTC):', 'thumbnail-manager' ),
				'resultExpires'                   => __( 'Saved deadline (UTC):', 'thumbnail-manager' ),
				'resultMatchCount'                => __( 'Items matching this filter:', 'thumbnail-manager' ),
				'resultSelect'                    => __( 'Select for retry', 'thumbnail-manager' ),
				'resultPath'                      => __( 'File', 'thumbnail-manager' ),
				'resultRetry'                     => __( 'Retry selected items in a new job', 'thumbnail-manager' ),
				'resultResume'                    => __( 'Continue active job', 'thumbnail-manager' ),
				'resultUnavailable'               => __( 'This saved result is unavailable. Run a new check or scan.', 'thumbnail-manager' ),
				'resultExportFailed'              => __( 'Export failed. Saved results are unchanged; reopen them and try again.', 'thumbnail-manager' ),
				'resultExportLimit'               => __( 'Export contains the first 10,000 items. The saved summary covers the full job; use the on-screen filters to inspect other items.', 'thumbnail-manager' ),
				'resultExportSnapshot'            => __( 'Export reads saved pages in order. An active job can change while it is being exported; use a terminal job for a stable result.', 'thumbnail-manager' ),
				'resultExportDone'                => __( 'Report downloaded.', 'thumbnail-manager' ),
				'resultRetryFailed'               => __( 'Could not prepare retry.', 'thumbnail-manager' ),
				'healthFilePresent'               => __( 'Thumbnail file is present.', 'thumbnail-manager' ),
				'healthFileMissing'               => __( 'Thumbnail file is missing.', 'thumbnail-manager' ),
				'healthMetadataMissing'           => __( 'Thumbnail metadata is missing.', 'thumbnail-manager' ),
				'healthSourceTooSmall'            => __( 'This size is not needed for the source image dimensions.', 'thumbnail-manager' ),
				'healthSizeNotEnabled'            => __( 'This thumbnail size is currently disabled.', 'thumbnail-manager' ),
				'healthUnsafePath'                => __( 'The thumbnail path could not be verified safely.', 'thumbnail-manager' ),
				'healthInvalidDefinition'         => __( 'This thumbnail size has an invalid definition.', 'thumbnail-manager' ),
				'healthUpscaledDefinition'        => __( 'This thumbnail size would require enlarging the source image.', 'thumbnail-manager' ),
				'selectedSizesEmpty'              => __( 'Choose at least one enabled thumbnail size.', 'thumbnail-manager' ),
				'selectedSizesLabel'              => __( 'Selected sizes:', 'thumbnail-manager' ),
				'healthSizeUnregistered'          => __( 'This size is no longer registered; existing files were preserved.', 'thumbnail-manager' ),
				'checkThumbnails'                 => __( 'Check thumbnails', 'thumbnail-manager' ),
				'runForceRegenerate'              => __( 'Run force regeneration', 'thumbnail-manager' ),
				'chooseImages'                    => __( 'Choose images', 'thumbnail-manager' ),
				'useSelectedImages'               => __( 'Use selected images', 'thumbnail-manager' ),
				'oneImageSelected'                => __( '1 image selected', 'thumbnail-manager' ),
				/* translators: %s: selected image count. */
				'selectedImageCount'              => __( '%s images selected', 'thumbnail-manager' ),
				'selectionLimit'                  => __( 'Choose up to 500 images at a time. Your previous selection has been retained.', 'thumbnail-manager' ),
				'selectionEmpty'                  => __( 'Choose at least one image from the Media Library.', 'thumbnail-manager' ),
				'healthCheckDone'                 => __( 'Check complete. Review the results, then choose Repair missing thumbnails to make changes.', 'thumbnail-manager' ),
				'healthRepairDone'                => __( 'Repair complete. Results show the files restored by this job.', 'thumbnail-manager' ),
				'healthCoverage'                  => __( 'Only currently enabled sizes and local upload files were checked. Frontend pages and CDN copies are outside this report.', 'thumbnail-manager' ),
				'healthLoading'                   => __( 'Loading thumbnail results…', 'thumbnail-manager' ),
				'healthLoadFailed'                => __( 'Could not load thumbnail results.', 'thumbnail-manager' ),
				'healthInspected'                 => __( 'Inspected (UTC):', 'thumbnail-manager' ),
				'healthInspectedCount'            => __( 'Inspected:', 'thumbnail-manager' ),
				'healthTitle'                     => __( 'Image', 'thumbnail-manager' ),
				'healthThumbnailResults'          => __( 'Thumbnail results', 'thumbnail-manager' ),
				'healthDetails'                   => __( 'Details', 'thumbnail-manager' ),
				'healthSizeDetails'               => __( 'Thumbnail size details', 'thumbnail-manager' ),
				/* translators: %s: number of thumbnail sizes in the image result. */
				'healthViewSizes'                 => __( 'View size details (%s)', 'thumbnail-manager' ),
				'healthAction'                    => __( 'Suggested action', 'thumbnail-manager' ),
				'healthMissing'                   => __( 'Missing', 'thumbnail-manager' ),
				'healthHealthy'                   => __( 'Healthy', 'thumbnail-manager' ),
				'healthBlocked'                   => __( 'Blocked', 'thumbnail-manager' ),
				'healthUnsupported'               => __( 'Unsupported', 'thumbnail-manager' ),
				'healthDisabled'                  => __( 'Disabled', 'thumbnail-manager' ),
				'healthNotNeeded'                 => __( 'Not needed', 'thumbnail-manager' ),
				'healthRestored'                  => __( 'Restored:', 'thumbnail-manager' ),
				'healthNoResults'                 => __( 'No thumbnail results are available yet.', 'thumbnail-manager' ),
				'previousPage'                    => __( 'Previous', 'thumbnail-manager' ),
				'nextPage'                        => __( 'Next', 'thumbnail-manager' ),
				'healthNewCheck'                  => __( 'Run a new check after changing enabled sizes or media files.', 'thumbnail-manager' ),
				'healthItemResult'                => __( 'Job result:', 'thumbnail-manager' ),
				'allSizesEnabled'                 => __( 'All sizes are enabled — there are no disabled sizes to prune.', 'thumbnail-manager' ),
				'scanning'                        => __( 'Scanning…', 'thumbnail-manager' ),
				'deleting'                        => __( 'Deleting…', 'thumbnail-manager' ),
				'processing'                      => __( 'Processing…', 'thumbnail-manager' ),
				'scanFailed'                      => __( 'Scan failed:', 'thumbnail-manager' ),
				'prepareFailed'                   => __( 'Prepare failed:', 'thumbnail-manager' ),
				'deleteFailed'                    => __( 'Delete failed:', 'thumbnail-manager' ),
				'unknownError'                    => __( 'Unknown error', 'thumbnail-manager' ),
				'networkPrepare'                  => __( 'Network error during prepare.', 'thumbnail-manager' ),
				'networkScan'                     => __( 'Network error during scan.', 'thumbnail-manager' ),
				'networkDelete'                   => __( 'Network error during delete.', 'thumbnail-manager' ),
				'cancelled'                       => __( 'Cancelled.', 'thumbnail-manager' ),
				'resumeAvailable'                 => __( 'An unfinished job was found. Resuming it now…', 'thumbnail-manager' ),
				'resumeAfterNetworkError'         => __( 'The job is still saved. Reload this page to resume it.', 'thumbnail-manager' ),
				'scanReadyForReview'              => __( 'Scan complete. Review the immutable manifest below; nothing has been deleted.', 'thumbnail-manager' ),
				'approvalFailed'                  => __( 'Delete approval failed:', 'thumbnail-manager' ),
				'networkApproval'                 => __( 'Network error while approving the manifest.', 'thumbnail-manager' ),
				'manifestHash'                    => __( 'Manifest:', 'thumbnail-manager' ),
				'scanningDisk'                    => __( 'Scanning uploads folders…', 'thumbnail-manager' ),
				'indexingSources'                 => __( 'Indexing authoritative media sources…', 'thumbnail-manager' ),
				'buildingManifest'                => __( 'Building immutable manifest…', 'thumbnail-manager' ),
				'failedFiles'                     => __( 'Failed files:', 'thumbnail-manager' ),
				'noMatchingThumbnails'            => __( 'No matching thumbnails found. Try enabling orphan discovery or widen the folder scope.', 'thumbnail-manager' ),
				/* translators: 1: deleted file count, 2: formatted bytes freed. */
				'doneDeleted'                     => __( 'Done. Deleted %1$s files — Freed %2$s.', 'thumbnail-manager' ),
				'regenerationCancelled'           => __( 'Regeneration cancelled.', 'thumbnail-manager' ),
				'preparingRegenerationQueue'      => __( 'Preparing regeneration queue…', 'thumbnail-manager' ),
				/* translators: %s: number of authoritative attachment rows checked. */
				'scanningAttachmentRows'          => __( 'Scanning attachment rows… %s checked', 'thumbnail-manager' ),
				'starting'                        => __( 'Starting…', 'thumbnail-manager' ),
				/* translators: 1: regenerated count, 2: skipped count, 3: failed count. */
				'doneRegenerated'                 => __( 'Done. Regenerated %1$s attachments, skipped %2$s, failed %3$s.', 'thumbnail-manager' ),
				'batchFailed'                     => __( 'Batch failed:', 'thumbnail-manager' ),
				'networkRegenerateBatch'          => __( 'Network error during regenerate batch.', 'thumbnail-manager' ),
				'networkRegeneratePrepare'        => __( 'Network error during regenerate prepare.', 'thumbnail-manager' ),
				'noImageAttachments'              => __( 'No image attachments found.', 'thumbnail-manager' ),
				'noItemsToProcess'                => __( 'No items to process.', 'thumbnail-manager' ),
				'recommendationScanFailed'        => __( 'Recommendation scan failed.', 'thumbnail-manager' ),
				'networkRecommendationScan'       => __( 'Network error during recommendation scan.', 'thumbnail-manager' ),
				'recommendationScanCompleted'     => __( 'Scan completed.', 'thumbnail-manager' ),
				'scanBase'                        => __( 'Scan base:', 'thumbnail-manager' ),
				'keep'                            => __( 'KEEP:', 'thumbnail-manager' ),
				'deleteNotSelected'               => __( 'DELETE (not selected):', 'thumbnail-manager' ),
				'matchedFiles'                    => __( 'Matched files:', 'thumbnail-manager' ),
				'orphanDiscovery'                 => __( 'Orphan discovery:', 'thumbnail-manager' ),
				'distinctDimsFound'               => __( 'distinct dims on disk/metadata.', 'thumbnail-manager' ),
				'dimsKeptBySelection'             => __( 'Dims kept by selection:', 'thumbnail-manager' ),
				'metadataOrphanDimsDelete'        => __( 'Metadata orphan dims marked for deletion:', 'thumbnail-manager' ),
				'originalFilesProtected'          => __( 'Original files protected:', 'thumbnail-manager' ),
				'unmappedDiskSkipped'             => __( 'Unmapped disk candidates skipped:', 'thumbnail-manager' ),
				'unverifiedSidecars'              => __( 'Unverified format sidecars preserved:', 'thumbnail-manager' ),
				'ambiguousSiblings'               => __( 'Ambiguous sibling files preserved:', 'thumbnail-manager' ),
				'protectedSources'                => __( 'Authoritative source paths protected:', 'thumbnail-manager' ),
				'sourceErrors'                    => __( 'Indeterminate source checks preserved:', 'thumbnail-manager' ),
				'metadataBackedCandidates'        => __( 'Metadata-backed candidates:', 'thumbnail-manager' ),
				'verifiedLegacyCandidates'        => __( 'Verified legacy disk-only candidates:', 'thumbnail-manager' ),
				'verifiedCurrentLegacyCandidates' => __( 'Verified legacy — currently disabled size:', 'thumbnail-manager' ),
				'verifiedHistoricalCandidates'    => __( 'Verified historical legacy — size no longer registered:', 'thumbnail-manager' ),
				'historicalPreserved'             => __( 'Ambiguous/unverified historical-looking files preserved:', 'thumbnail-manager' ),
				'malformedPreserved'              => __( 'Malformed or mismatched files preserved:', 'thumbnail-manager' ),
				'keptDimensionPreserved'          => __( 'Files matching a kept size preserved:', 'thumbnail-manager' ),
				'verifiedLegacyEvidence'          => __( 'Verified legacy disk-only evidence', 'thumbnail-manager' ),
				'verifiedHistoricalEvidence'      => __( 'Verified historical legacy / no longer registered', 'thumbnail-manager' ),
				'sampleMatches'                   => __( 'Sample matches', 'thumbnail-manager' ),
				'shown'                           => __( 'shown', 'thumbnail-manager' ),
				'scope'                           => __( 'Scope:', 'thumbnail-manager' ),
				'processed'                       => __( 'Processed:', 'thumbnail-manager' ),
				'regenerated'                     => __( 'Regenerated:', 'thumbnail-manager' ),
				'skipped'                         => __( 'Skipped:', 'thumbnail-manager' ),
				'failed'                          => __( 'Failed:', 'thumbnail-manager' ),
				'attachmentsFound'                => __( 'Attachments found:', 'thumbnail-manager' ),
				'onlyGenerateMissing'             => __( 'Only generate missing:', 'thumbnail-manager' ),
				'forceRegenerateAll'              => __( 'Force regenerate all:', 'thumbnail-manager' ),
				'yes'                             => __( 'Yes', 'thumbnail-manager' ),
				'no'                              => __( 'No', 'thumbnail-manager' ),
				'noRecommendationData'            => __( 'No recommendation data found.', 'thumbnail-manager' ),
				'unknown'                         => __( 'Unknown', 'thumbnail-manager' ),
				'attachmentMetadata'              => __( 'Attachment metadata', 'thumbnail-manager' ),
				'size'                            => __( 'Size', 'thumbnail-manager' ),
				'dimensions'                      => __( 'Dimensions', 'thumbnail-manager' ),
				'status'                          => __( 'Status', 'thumbnail-manager' ),
				'reason'                          => __( 'Reason', 'thumbnail-manager' ),
				'recommendation'                  => __( 'Recommendation', 'thumbnail-manager' ),
				'confidence'                      => __( 'Keep confidence', 'thumbnail-manager' ),
				'confidenceHigh'                  => __( 'High', 'thumbnail-manager' ),
				'confidenceMedium'                => __( 'Medium', 'thumbnail-manager' ),
				'confidenceLow'                   => __( 'Low', 'thumbnail-manager' ),
				'evidence'                        => __( 'Evidence', 'thumbnail-manager' ),
				'attachmentsScanned'              => __( 'Attachments scanned', 'thumbnail-manager' ),
				'uniqueCheckedFiles'              => __( 'Unique checked files', 'thumbnail-manager' ),
				'checkedFileBytes'                => __( 'Checked file bytes', 'thumbnail-manager' ),
				'missingFiles'                    => __( 'Missing files', 'thumbnail-manager' ),
				'unreadableFiles'                 => __( 'Unreadable files', 'thumbnail-manager' ),
				'unverifiedEntries'               => __( 'Unverified metadata entries', 'thumbnail-manager' ),
				'scanCoverageLimits'              => __( 'Scan coverage and limits', 'thumbnail-manager' ),
				'policyProtected'                 => __( 'Policy protected', 'thumbnail-manager' ),
				/* translators: %1$s: detected content reference count. */
				'contentHintsCount'               => __( '%1$s content hints', 'thumbnail-manager' ),
				'reviewEvidence'                  => __( 'Review scan evidence', 'thumbnail-manager' ),
				'viewEvidence'                    => __( 'View evidence', 'thumbnail-manager' ),
				'policyProtection'                => __( 'Policy protection', 'thumbnail-manager' ),
				'contentReferenceHints'           => __( 'Content reference hints', 'thumbnail-manager' ),
				'recordedEvidence'                => __( 'Recorded evidence', 'thumbnail-manager' ),
				'keepDecision'                    => __( 'Keep decision', 'thumbnail-manager' ),
				'recordedThumbnails'              => __( 'Recorded thumbnails', 'thumbnail-manager' ),
				'contentClassHint'                => __( 'Size class hint', 'thumbnail-manager' ),
				'contentJsonHint'                 => __( 'Size setting hint', 'thumbnail-manager' ),
				'metadataEntries'                 => __( 'Metadata entries', 'thumbnail-manager' ),
				'referenceBytes'                  => __( 'Readable reference bytes', 'thumbnail-manager' ),
				'recommendationEvidenceLimits'    => __( 'Content evidence samples show size-name hints in post content, not verified image usage. Policy protections are separate. Dynamic theme, plugin, custom-field and external usage is not observed.', 'thumbnail-manager' ),
				'recommendationLegacyStatistics'  => __( 'Unique file statistics are unavailable for this saved scan. Run a new scan; legacy footprint totals may count shared references more than once.', 'thumbnail-manager' ),
				/* translators: 1: scanned attachments, 2: metadata entries, 3: unique checked files, 4: readable bytes, 5: missing files, 6: unreadable files, 7: unverified entries. */
				'recommendationStatistics'        => __( 'Attachments scanned: %1$s. Metadata entries: %2$s. Unique checked files: %3$s (%4$s). Missing files: %5$s. Unreadable files: %6$s. Unverified metadata entries: %7$s.', 'thumbnail-manager' ),
				'recommendationStatisticsLimits'  => __( 'File totals cover unique canonical paths referenced by registered-size metadata, observed during the scan. They are not a live snapshot or a complete uploads inventory. Per-size bytes count metadata references and may overlap.', 'thumbnail-manager' ),
				'legacyRecommendationResult'      => __( 'This result uses an older recommendation format. Run a new scan before applying recommendations.', 'thumbnail-manager' ),
				'staleRecommendationResult'       => __( 'Registered image sizes changed after this scan. Run a new scan before applying recommendations.', 'thumbnail-manager' ),
				'invalidRecommendationResult'     => __( 'This recommendation result is incomplete. Run a new scan before applying recommendations.', 'thumbnail-manager' ),
				'conservativeApplyReady'          => __( 'Safe recommendations are ready. Unknown sizes will keep their current setting.', 'thumbnail-manager' ),
				'scanningMediaUsage'              => __( 'Scanning media usage…', 'thumbnail-manager' ),
				'scanningAttachmentMetadata'      => __( 'Scanning attachment metadata…', 'thumbnail-manager' ),
				'scanningContentReferences'       => __( 'Scanning content references…', 'thumbnail-manager' ),
				'jobStopped'                      => __( 'Job stopped. Completed work was not rolled back, and the audit record was retained.', 'thumbnail-manager' ),
				'stopUnconfirmed'                 => __( 'Stop has not been confirmed. Retry Stop job or reload this page to continue stopping; no new batch will be started.', 'thumbnail-manager' ),
				'stopAwaitingPrepare'             => __( 'Waiting for the preparation request to finish so its job can be stopped. If interrupted, reload this page to find and stop it.', 'thumbnail-manager' ),
				'jobCompletedBeforeStop'          => __( 'The job completed before it could be stopped. Review its results.', 'thumbnail-manager' ),
				'jobExpiredBeforeStop'            => __( 'The job has expired. No new work will be started.', 'thumbnail-manager' ),
				'stopping'                        => __( 'Stopping after the current batch…', 'thumbnail-manager' ),
				'activeJob'                       => __( 'Active job', 'thumbnail-manager' ),
				'viewJob'                         => __( 'View job', 'thumbnail-manager' ),
				'noRecentJobs'                    => __( 'No recent jobs.', 'thumbnail-manager' ),
				'jobPrune'                        => __( 'Prune files', 'thumbnail-manager' ),
				'jobThumbnailCheck'               => __( 'Check thumbnails', 'thumbnail-manager' ),
				'jobThumbnailRepair'              => __( 'Repair missing thumbnails', 'thumbnail-manager' ),
				'healthSavedResults'              => __( 'Saved job results. No new processing was started.', 'thumbnail-manager' ),
				'jobRegenerate'                   => __( 'Regenerate', 'thumbnail-manager' ),
				'jobRecommendation'               => __( 'Recommendations', 'thumbnail-manager' ),
				'statusScanning'                  => __( 'Scanning', 'thumbnail-manager' ),
				'statusRunning'                   => __( 'Running', 'thumbnail-manager' ),
				'statusAwaitingApproval'          => __( 'Awaiting review', 'thumbnail-manager' ),
				'statusApproved'                  => __( 'Approved', 'thumbnail-manager' ),
				'statusDeleting'                  => __( 'Deleting', 'thumbnail-manager' ),
				'statusCompleted'                 => __( 'Completed', 'thumbnail-manager' ),
				'statusCancelled'                 => __( 'Stopped', 'thumbnail-manager' ),
				'statusExpired'                   => __( 'Expired', 'thumbnail-manager' ),
				'manifestEmpty'                   => __( 'No manifest items match this filter.', 'thumbnail-manager' ),
				'manifestLoading'                 => __( 'Loading manifest…', 'thumbnail-manager' ),
				'manifestLoadFailed'              => __( 'Could not load the manifest.', 'thumbnail-manager' ),
				/* translators: 1: current manifest page, 2: total manifest pages. */
				'pageOf'                          => __( 'Page %1$s of %2$s', 'thumbnail-manager' ),
				/* translators: %s: number of reviewed files. */
				'deleteReviewedCount'             => __( 'Delete %s reviewed files', 'thumbnail-manager' ),
				'errorsTitle'                     => __( 'Error details', 'thumbnail-manager' ),
				/* translators: %s: number of upload directory entries checked. */
				'scanningDiskCount'               => __( 'Scanning uploads folders… %s entries checked', 'thumbnail-manager' ),
				'expiresIn'                       => __( 'Approval window: 30 minutes after confirmation', 'thumbnail-manager' ),
				'unknownPath'                     => __( 'Unknown item', 'thumbnail-manager' ),
				'allUploads'                      => __( 'All uploads', 'thumbnail-manager' ),
				'allMedia'                        => __( 'All media', 'thumbnail-manager' ),
				/* translators: %s: number of selected uploads folders. */
				'foldersSelected'                 => __( '%s folders selected', 'thumbnail-manager' ),
				'oneFolderSelected'               => __( '1 folder selected', 'thumbnail-manager' ),
				'noFolderSelected'                => __( 'Choose at least one folder or use All uploads.', 'thumbnail-manager' ),
				'removeFolder'                    => __( 'Remove folder', 'thumbnail-manager' ),
			),
		)
	);
}
