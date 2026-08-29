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
	wp_enqueue_script( 'yotm-prune-admin' );

	// Pass data to JS.
	wp_localize_script(
		'yotm-prune-admin',
		'YOTM',
		array(
			'ajaxurl'                  => admin_url( 'admin-ajax.php' ),
			'nonce'                    => wp_create_nonce( 'yotm_prune_nonce' ),
			'siteId'                   => get_current_blog_id(),
			'registeredSizesSignature' => function_exists( 'yotm_recommend_registered_sizes_signature' )
				? yotm_recommend_registered_sizes_signature( yotm_get_registered_sizes() )
				: '',
			'i18n'                     => array(
				'allSizesEnabled'             => __( 'All sizes are enabled — there are no disabled sizes to prune.', 'thumbnail-manager' ),
				'scanning'                    => __( 'Scanning…', 'thumbnail-manager' ),
				'deleting'                    => __( 'Deleting…', 'thumbnail-manager' ),
				'processing'                  => __( 'Processing…', 'thumbnail-manager' ),
				'scanFailed'                  => __( 'Scan failed:', 'thumbnail-manager' ),
				'prepareFailed'               => __( 'Prepare failed:', 'thumbnail-manager' ),
				'deleteFailed'                => __( 'Delete failed:', 'thumbnail-manager' ),
				'unknownError'                => __( 'Unknown error', 'thumbnail-manager' ),
				'networkPrepare'              => __( 'Network error during prepare.', 'thumbnail-manager' ),
				'networkScan'                 => __( 'Network error during scan.', 'thumbnail-manager' ),
				'networkDelete'               => __( 'Network error during delete.', 'thumbnail-manager' ),
				'cancelled'                   => __( 'Cancelled.', 'thumbnail-manager' ),
				'resumeAvailable'             => __( 'An unfinished job was found. Resuming it now…', 'thumbnail-manager' ),
				'resumeAfterNetworkError'     => __( 'The job is still saved. Reload this page to resume it.', 'thumbnail-manager' ),
				'scanReadyForReview'          => __( 'Scan complete. Review the immutable manifest below; nothing has been deleted.', 'thumbnail-manager' ),
				'approvalFailed'              => __( 'Delete approval failed:', 'thumbnail-manager' ),
				'networkApproval'             => __( 'Network error while approving the manifest.', 'thumbnail-manager' ),
				'manifestHash'                => __( 'Manifest:', 'thumbnail-manager' ),
				'scanningDisk'                => __( 'Scanning uploads folders…', 'thumbnail-manager' ),
				'indexingSources'             => __( 'Indexing authoritative media sources…', 'thumbnail-manager' ),
				'buildingManifest'            => __( 'Building immutable manifest…', 'thumbnail-manager' ),
				'failedFiles'                 => __( 'Failed files:', 'thumbnail-manager' ),
				'noMatchingThumbnails'        => __( 'No matching thumbnails found. Try enabling orphan discovery or widen the folder scope.', 'thumbnail-manager' ),
				/* translators: 1: deleted file count, 2: formatted bytes freed. */
				'doneDeleted'                 => __( 'Done. Deleted %1$s files — Freed %2$s.', 'thumbnail-manager' ),
				'regenerationCancelled'       => __( 'Regeneration cancelled.', 'thumbnail-manager' ),
				'preparingRegenerationQueue'  => __( 'Preparing regeneration queue…', 'thumbnail-manager' ),
				/* translators: %s: number of authoritative attachment rows checked. */
				'scanningAttachmentRows'      => __( 'Scanning attachment rows… %s checked', 'thumbnail-manager' ),
				'starting'                    => __( 'Starting…', 'thumbnail-manager' ),
				/* translators: 1: regenerated count, 2: skipped count, 3: failed count. */
				'doneRegenerated'             => __( 'Done. Regenerated %1$s attachments, skipped %2$s, failed %3$s.', 'thumbnail-manager' ),
				'batchFailed'                 => __( 'Batch failed:', 'thumbnail-manager' ),
				'networkRegenerateBatch'      => __( 'Network error during regenerate batch.', 'thumbnail-manager' ),
				'networkRegeneratePrepare'    => __( 'Network error during regenerate prepare.', 'thumbnail-manager' ),
				'noImageAttachments'          => __( 'No image attachments found.', 'thumbnail-manager' ),
				'noItemsToProcess'            => __( 'No items to process.', 'thumbnail-manager' ),
				'recommendationScanFailed'    => __( 'Recommendation scan failed.', 'thumbnail-manager' ),
				'networkRecommendationScan'   => __( 'Network error during recommendation scan.', 'thumbnail-manager' ),
				'recommendationScanCompleted' => __( 'Scan completed.', 'thumbnail-manager' ),
				'scanBase'                    => __( 'Scan base:', 'thumbnail-manager' ),
				'keep'                        => __( 'KEEP:', 'thumbnail-manager' ),
				'deleteNotSelected'           => __( 'DELETE (not selected):', 'thumbnail-manager' ),
				'matchedFiles'                => __( 'Matched files:', 'thumbnail-manager' ),
				'orphanDiscovery'             => __( 'Orphan discovery:', 'thumbnail-manager' ),
				'distinctDimsFound'           => __( 'distinct dims on disk/metadata.', 'thumbnail-manager' ),
				'dimsKeptBySelection'         => __( 'Dims kept by selection:', 'thumbnail-manager' ),
				'metadataOrphanDimsDelete'    => __( 'Metadata orphan dims marked for deletion:', 'thumbnail-manager' ),
				'originalFilesProtected'      => __( 'Original files protected:', 'thumbnail-manager' ),
				'unmappedDiskSkipped'         => __( 'Unmapped disk candidates skipped:', 'thumbnail-manager' ),
				'unverifiedSidecars'          => __( 'Unverified format sidecars preserved:', 'thumbnail-manager' ),
				'ambiguousSiblings'           => __( 'Ambiguous sibling files preserved:', 'thumbnail-manager' ),
				'protectedSources'            => __( 'Authoritative source paths protected:', 'thumbnail-manager' ),
				'sourceErrors'                => __( 'Indeterminate source checks preserved:', 'thumbnail-manager' ),
				'sampleMatches'               => __( 'Sample matches', 'thumbnail-manager' ),
				'shown'                       => __( 'shown', 'thumbnail-manager' ),
				'scope'                       => __( 'Scope:', 'thumbnail-manager' ),
				'processed'                   => __( 'Processed:', 'thumbnail-manager' ),
				'regenerated'                 => __( 'Regenerated:', 'thumbnail-manager' ),
				'skipped'                     => __( 'Skipped:', 'thumbnail-manager' ),
				'failed'                      => __( 'Failed:', 'thumbnail-manager' ),
				'attachmentsFound'            => __( 'Attachments found:', 'thumbnail-manager' ),
				'onlyGenerateMissing'         => __( 'Only generate missing:', 'thumbnail-manager' ),
				'forceRegenerateAll'          => __( 'Force regenerate all:', 'thumbnail-manager' ),
				'yes'                         => __( 'Yes', 'thumbnail-manager' ),
				'no'                          => __( 'No', 'thumbnail-manager' ),
				'noRecommendationData'        => __( 'No recommendation data found.', 'thumbnail-manager' ),
				'unknown'                     => __( 'Unknown', 'thumbnail-manager' ),
				'attachmentMetadata'          => __( 'Attachment metadata', 'thumbnail-manager' ),
				'size'                        => __( 'Size', 'thumbnail-manager' ),
				'dimensions'                  => __( 'Dimensions', 'thumbnail-manager' ),
				'status'                      => __( 'Status', 'thumbnail-manager' ),
				'reason'                      => __( 'Reason', 'thumbnail-manager' ),
				'recommendation'              => __( 'Recommendation', 'thumbnail-manager' ),
				'confidence'                  => __( 'Keep confidence', 'thumbnail-manager' ),
				'confidenceHigh'              => __( 'High', 'thumbnail-manager' ),
				'confidenceMedium'            => __( 'Medium', 'thumbnail-manager' ),
				'confidenceLow'               => __( 'Low', 'thumbnail-manager' ),
				'evidence'                    => __( 'Evidence', 'thumbnail-manager' ),
				'legacyRecommendationResult'  => __( 'This result uses an older recommendation format. Run a new scan before applying recommendations.', 'thumbnail-manager' ),
				'staleRecommendationResult'   => __( 'Registered image sizes changed after this scan. Run a new scan before applying recommendations.', 'thumbnail-manager' ),
				'invalidRecommendationResult' => __( 'This recommendation result is incomplete. Run a new scan before applying recommendations.', 'thumbnail-manager' ),
				'conservativeApplyReady'      => __( 'Safe recommendations are ready. Unknown sizes will keep their current setting.', 'thumbnail-manager' ),
				'scanningMediaUsage'          => __( 'Scanning media usage…', 'thumbnail-manager' ),
				'scanningAttachmentMetadata'  => __( 'Scanning attachment metadata…', 'thumbnail-manager' ),
				'scanningContentReferences'   => __( 'Scanning content references…', 'thumbnail-manager' ),
				'jobStopped'                  => __( 'Job stopped. Completed work was not rolled back, and the audit record was retained.', 'thumbnail-manager' ),
				'stopping'                    => __( 'Stopping after the current batch…', 'thumbnail-manager' ),
				'activeJob'                   => __( 'Active job', 'thumbnail-manager' ),
				'viewJob'                     => __( 'View job', 'thumbnail-manager' ),
				'noRecentJobs'                => __( 'No recent jobs.', 'thumbnail-manager' ),
				'jobPrune'                    => __( 'Prune files', 'thumbnail-manager' ),
				'jobRegenerate'               => __( 'Regenerate', 'thumbnail-manager' ),
				'jobRecommendation'           => __( 'Recommendations', 'thumbnail-manager' ),
				'statusScanning'              => __( 'Scanning', 'thumbnail-manager' ),
				'statusRunning'               => __( 'Running', 'thumbnail-manager' ),
				'statusAwaitingApproval'      => __( 'Awaiting review', 'thumbnail-manager' ),
				'statusApproved'              => __( 'Approved', 'thumbnail-manager' ),
				'statusDeleting'              => __( 'Deleting', 'thumbnail-manager' ),
				'statusCompleted'             => __( 'Completed', 'thumbnail-manager' ),
				'statusCancelled'             => __( 'Stopped', 'thumbnail-manager' ),
				'statusExpired'               => __( 'Expired', 'thumbnail-manager' ),
				'manifestEmpty'               => __( 'No manifest items match this filter.', 'thumbnail-manager' ),
				'manifestLoading'             => __( 'Loading manifest…', 'thumbnail-manager' ),
				'manifestLoadFailed'          => __( 'Could not load the manifest.', 'thumbnail-manager' ),
				/* translators: 1: current manifest page, 2: total manifest pages. */
				'pageOf'                      => __( 'Page %1$s of %2$s', 'thumbnail-manager' ),
				/* translators: %s: number of reviewed files. */
				'deleteReviewedCount'         => __( 'Delete %s reviewed files', 'thumbnail-manager' ),
				'errorsTitle'                 => __( 'Error details', 'thumbnail-manager' ),
				/* translators: %s: number of upload directory entries checked. */
				'scanningDiskCount'           => __( 'Scanning uploads folders… %s entries checked', 'thumbnail-manager' ),
				'expiresIn'                   => __( 'Approval window: 30 minutes after confirmation', 'thumbnail-manager' ),
				'unknownPath'                 => __( 'Unknown item', 'thumbnail-manager' ),
				'allUploads'                  => __( 'All uploads', 'thumbnail-manager' ),
				'allMedia'                    => __( 'All media', 'thumbnail-manager' ),
				/* translators: %s: number of selected uploads folders. */
				'foldersSelected'             => __( '%s folders selected', 'thumbnail-manager' ),
				'oneFolderSelected'           => __( '1 folder selected', 'thumbnail-manager' ),
				'noFolderSelected'            => __( 'Choose at least one folder or use All uploads.', 'thumbnail-manager' ),
				'removeFolder'                => __( 'Remove folder', 'thumbnail-manager' ),
			),
		)
	);
}
