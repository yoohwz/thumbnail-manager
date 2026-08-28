<?php

if (!defined('ABSPATH')) {
	exit;
}

// Define plugin root URL/paths only once (safe-guarded).
if ( ! defined( 'YOTM_PLUGIN_URL' ) ) {
    define( 'YOTM_PLUGIN_URL', plugin_dir_url( dirname( __FILE__ ) ) );
}
if ( ! defined( 'YOTM_PLUGIN_PATH' ) ) {
    define( 'YOTM_PLUGIN_PATH', plugin_dir_path( dirname( __FILE__ ) ) );
}

add_action( 'admin_enqueue_scripts', function( $hook ) {
    // Load only on Tools → Prune Thumbnails
    if ( 'tools_page_yo-manage-thumbnails' !== $hook ) {
        return;
    }

    // CSS
	    wp_register_style(
	        'yotm-prune-admin',
	        YOTM_PLUGIN_URL . 'css/style.css',
	        [],
	        file_exists( YOTM_PLUGIN_PATH . 'css/style.css' ) ? (string) filemtime( YOTM_PLUGIN_PATH . 'css/style.css' ) : YOTM_VERSION
	    );
    wp_enqueue_style( 'yotm-prune-admin' );

    // JS
    wp_register_script(
	        'yotm-prune-admin',
	        YOTM_PLUGIN_URL . 'js/admin.js',
	        [ 'jquery' ],
	        file_exists( YOTM_PLUGIN_PATH . 'js/admin.js' ) ? (string) filemtime( YOTM_PLUGIN_PATH . 'js/admin.js' ) : YOTM_VERSION,
	        true
	    );
    wp_enqueue_script( 'yotm-prune-admin' );

    // Pass data to JS
    wp_localize_script(
        'yotm-prune-admin',
        'YOTM',
	        [
	            'ajaxurl' => admin_url( 'admin-ajax.php' ),
		            'nonce'   => wp_create_nonce( 'yotm_prune_nonce' ),
		            'siteId'  => get_current_blog_id(),
		            'registeredSizesSignature' => function_exists( 'yotm_recommend_registered_sizes_signature' )
			            ? yotm_recommend_registered_sizes_signature( yotm_get_registered_sizes() )
			            : '',
	            'i18n'    => [
	                'allSizesEnabled'              => __( 'All sizes are enabled — there are no disabled sizes to prune.', 'thumbnail-manager' ),
	                'scanning'                     => __( 'Scanning…', 'thumbnail-manager' ),
	                'deleting'                     => __( 'Deleting…', 'thumbnail-manager' ),
	                'processing'                   => __( 'Processing…', 'thumbnail-manager' ),
	                'scanFailed'                   => __( 'Scan failed:', 'thumbnail-manager' ),
	                'prepareFailed'                => __( 'Prepare failed:', 'thumbnail-manager' ),
	                'deleteFailed'                 => __( 'Delete failed:', 'thumbnail-manager' ),
	                'unknownError'                 => __( 'Unknown error', 'thumbnail-manager' ),
	                'networkPrepare'               => __( 'Network error during prepare.', 'thumbnail-manager' ),
	                'networkScan'                  => __( 'Network error during scan.', 'thumbnail-manager' ),
	                'networkDelete'                => __( 'Network error during delete.', 'thumbnail-manager' ),
		                'cancelled'                    => __( 'Cancelled.', 'thumbnail-manager' ),
		                'resumeAvailable'              => __( 'An unfinished job was found. Resuming it now…', 'thumbnail-manager' ),
		                'resumeAfterNetworkError'      => __( 'The job is still saved. Reload this page to resume it.', 'thumbnail-manager' ),
		                'scanReadyForReview'           => __( 'Scan complete. Review the immutable manifest below; nothing has been deleted.', 'thumbnail-manager' ),
		                'approvalFailed'               => __( 'Delete approval failed:', 'thumbnail-manager' ),
		                'networkApproval'              => __( 'Network error while approving the manifest.', 'thumbnail-manager' ),
		                'manifestHash'                 => __( 'Manifest:', 'thumbnail-manager' ),
		                'scanningDisk'                 => __( 'Scanning uploads folders…', 'thumbnail-manager' ),
		                'indexingSources'              => __( 'Indexing authoritative media sources…', 'thumbnail-manager' ),
		                'buildingManifest'             => __( 'Building immutable manifest…', 'thumbnail-manager' ),
		                'failedFiles'                  => __( 'Failed files:', 'thumbnail-manager' ),
	                'noMatchingThumbnails'         => __( 'No matching thumbnails found. Try enabling orphan discovery or widen the folder scope.', 'thumbnail-manager' ),
			                /* translators: 1: deleted file count, 2: formatted bytes freed. */
			                'doneDeleted'                  => __( 'Done. Deleted %1$s files — Freed %2$s.', 'thumbnail-manager' ),
		                'regenerationCancelled'        => __( 'Regeneration cancelled.', 'thumbnail-manager' ),
		                'preparingRegenerationQueue'   => __( 'Preparing regeneration queue…', 'thumbnail-manager' ),
		                /* translators: %s: number of authoritative attachment rows checked. */
		                'scanningAttachmentRows'       => __( 'Scanning attachment rows… %s checked', 'thumbnail-manager' ),
	                'starting'                     => __( 'Starting…', 'thumbnail-manager' ),
			                /* translators: 1: regenerated count, 2: skipped count, 3: failed count. */
			                'doneRegenerated'              => __( 'Done. Regenerated %1$s attachments, skipped %2$s, failed %3$s.', 'thumbnail-manager' ),
	                'batchFailed'                  => __( 'Batch failed:', 'thumbnail-manager' ),
	                'networkRegenerateBatch'       => __( 'Network error during regenerate batch.', 'thumbnail-manager' ),
	                'networkRegeneratePrepare'     => __( 'Network error during regenerate prepare.', 'thumbnail-manager' ),
	                'noImageAttachments'           => __( 'No image attachments found.', 'thumbnail-manager' ),
	                'noItemsToProcess'             => __( 'No items to process.', 'thumbnail-manager' ),
	                'recommendationScanFailed'     => __( 'Recommendation scan failed.', 'thumbnail-manager' ),
	                'networkRecommendationScan'    => __( 'Network error during recommendation scan.', 'thumbnail-manager' ),
	                'recommendationScanCompleted'  => __( 'Scan completed.', 'thumbnail-manager' ),
	                'scanBase'                     => __( 'Scan base:', 'thumbnail-manager' ),
	                'keep'                         => __( 'KEEP:', 'thumbnail-manager' ),
	                'deleteNotSelected'            => __( 'DELETE (not selected):', 'thumbnail-manager' ),
	                'matchedFiles'                 => __( 'Matched files:', 'thumbnail-manager' ),
	                'orphanDiscovery'              => __( 'Orphan discovery:', 'thumbnail-manager' ),
	                'distinctDimsFound'            => __( 'distinct dims on disk/metadata.', 'thumbnail-manager' ),
	                'dimsKeptBySelection'          => __( 'Dims kept by selection:', 'thumbnail-manager' ),
	                'metadataOrphanDimsDelete'     => __( 'Metadata orphan dims marked for deletion:', 'thumbnail-manager' ),
	                'originalFilesProtected'       => __( 'Original files protected:', 'thumbnail-manager' ),
	                'unmappedDiskSkipped'          => __( 'Unmapped disk candidates skipped:', 'thumbnail-manager' ),
	                'unverifiedSidecars'           => __( 'Unverified format sidecars preserved:', 'thumbnail-manager' ),
	                'ambiguousSiblings'            => __( 'Ambiguous sibling files preserved:', 'thumbnail-manager' ),
	                'protectedSources'             => __( 'Authoritative source paths protected:', 'thumbnail-manager' ),
	                'sourceErrors'                 => __( 'Indeterminate source checks preserved:', 'thumbnail-manager' ),
	                'sampleMatches'                => __( 'Sample matches', 'thumbnail-manager' ),
	                'shown'                        => __( 'shown', 'thumbnail-manager' ),
	                'scope'                        => __( 'Scope:', 'thumbnail-manager' ),
	                'processed'                    => __( 'Processed:', 'thumbnail-manager' ),
	                'regenerated'                  => __( 'Regenerated:', 'thumbnail-manager' ),
	                'skipped'                      => __( 'Skipped:', 'thumbnail-manager' ),
	                'failed'                       => __( 'Failed:', 'thumbnail-manager' ),
	                'attachmentsFound'             => __( 'Attachments found:', 'thumbnail-manager' ),
	                'onlyGenerateMissing'          => __( 'Only generate missing:', 'thumbnail-manager' ),
	                'forceRegenerateAll'           => __( 'Force regenerate all:', 'thumbnail-manager' ),
	                'yes'                          => __( 'Yes', 'thumbnail-manager' ),
	                'no'                           => __( 'No', 'thumbnail-manager' ),
	                'noRecommendationData'         => __( 'No recommendation data found.', 'thumbnail-manager' ),
	                'unknown'                      => __( 'Unknown', 'thumbnail-manager' ),
	                'attachmentMetadata'           => __( 'Attachment metadata', 'thumbnail-manager' ),
	                'size'                         => __( 'Size', 'thumbnail-manager' ),
	                'dimensions'                   => __( 'Dimensions', 'thumbnail-manager' ),
	                'status'                       => __( 'Status', 'thumbnail-manager' ),
	                'reason'                       => __( 'Reason', 'thumbnail-manager' ),
	                'recommendation'               => __( 'Recommendation', 'thumbnail-manager' ),
	                'confidence'                   => __( 'Keep confidence', 'thumbnail-manager' ),
	                'confidenceHigh'               => __( 'High', 'thumbnail-manager' ),
	                'confidenceMedium'             => __( 'Medium', 'thumbnail-manager' ),
	                'confidenceLow'                => __( 'Low', 'thumbnail-manager' ),
	                'evidence'                     => __( 'Evidence', 'thumbnail-manager' ),
	                'legacyRecommendationResult'   => __( 'This result uses an older recommendation format. Run a new scan before applying recommendations.', 'thumbnail-manager' ),
	                'staleRecommendationResult'    => __( 'Registered image sizes changed after this scan. Run a new scan before applying recommendations.', 'thumbnail-manager' ),
	                'invalidRecommendationResult'  => __( 'This recommendation result is incomplete. Run a new scan before applying recommendations.', 'thumbnail-manager' ),
	                'conservativeApplyReady'       => __( 'Safe recommendations are ready. Unknown sizes will keep their current setting.', 'thumbnail-manager' ),
		                'scanningMediaUsage'           => __( 'Scanning media usage…', 'thumbnail-manager' ),
		                'scanningAttachmentMetadata'   => __( 'Scanning attachment metadata…', 'thumbnail-manager' ),
		                'scanningContentReferences'    => __( 'Scanning content references…', 'thumbnail-manager' ),
		                'jobStopped'                   => __( 'Job stopped. Completed work was not rolled back, and the audit record was retained.', 'thumbnail-manager' ),
		                'stopping'                     => __( 'Stopping after the current batch…', 'thumbnail-manager' ),
		                'activeJob'                    => __( 'Active job', 'thumbnail-manager' ),
		                'viewJob'                      => __( 'View job', 'thumbnail-manager' ),
		                'noRecentJobs'                 => __( 'No recent jobs.', 'thumbnail-manager' ),
		                'jobPrune'                     => __( 'Prune files', 'thumbnail-manager' ),
		                'jobRegenerate'                => __( 'Regenerate', 'thumbnail-manager' ),
		                'jobRecommendation'            => __( 'Recommendations', 'thumbnail-manager' ),
		                'statusScanning'               => __( 'Scanning', 'thumbnail-manager' ),
		                'statusRunning'                => __( 'Running', 'thumbnail-manager' ),
		                'statusAwaitingApproval'       => __( 'Awaiting review', 'thumbnail-manager' ),
		                'statusApproved'               => __( 'Approved', 'thumbnail-manager' ),
		                'statusDeleting'               => __( 'Deleting', 'thumbnail-manager' ),
		                'statusCompleted'              => __( 'Completed', 'thumbnail-manager' ),
		                'statusCancelled'              => __( 'Stopped', 'thumbnail-manager' ),
		                'statusExpired'                => __( 'Expired', 'thumbnail-manager' ),
		                'manifestEmpty'                => __( 'No manifest items match this filter.', 'thumbnail-manager' ),
		                'manifestLoading'              => __( 'Loading manifest…', 'thumbnail-manager' ),
		                'manifestLoadFailed'           => __( 'Could not load the manifest.', 'thumbnail-manager' ),
		                /* translators: 1: current manifest page, 2: total manifest pages. */
		                'pageOf'                       => __( 'Page %1$s of %2$s', 'thumbnail-manager' ),
		                /* translators: %s: number of reviewed files. */
		                'deleteReviewedCount'          => __( 'Delete %s reviewed files', 'thumbnail-manager' ),
		                'errorsTitle'                  => __( 'Error details', 'thumbnail-manager' ),
		                /* translators: %s: number of upload directory entries checked. */
		                'scanningDiskCount'            => __( 'Scanning uploads folders… %s entries checked', 'thumbnail-manager' ),
		                'expiresIn'                    => __( 'Approval window: 30 minutes after confirmation', 'thumbnail-manager' ),
		                'unknownPath'                  => __( 'Unknown item', 'thumbnail-manager' ),
		                'allUploads'                   => __( 'All uploads', 'thumbnail-manager' ),
		                /* translators: %s: number of selected uploads folders. */
		                'foldersSelected'              => __( '%s folders selected', 'thumbnail-manager' ),
		                'oneFolderSelected'            => __( '1 folder selected', 'thumbnail-manager' ),
		                'noFolderSelected'             => __( 'Choose at least one folder or use All uploads.', 'thumbnail-manager' ),
		                'removeFolder'                 => __( 'Remove folder', 'thumbnail-manager' ),
		            ],
	        ]
	    );
	} );

add_action( 'admin_menu', function () {
    add_management_page(
        __( 'Thumbnails', 'thumbnail-manager' ),
        __( 'Thumbnails', 'thumbnail-manager' ),
        'manage_options',
        'yo-manage-thumbnails',
        'yotm_manage_thumbnails_page'
    );
} );

/** ===== Admin Page ===== */
function yotm_manage_thumbnails_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'thumbnail-manager' ) );
    }

    // Handle "Thumbnail Sizes" form save
    $sizes_saved_notice         = '';
    $run_regenerate_after_save = false;

    if ( isset( $_POST['yotm_sizes_save'] ) || isset( $_POST['yotm_save_and_regenerate'] ) ) {
        check_admin_referer( 'yotm_sizes_save_nonce', 'yotm_sizes_save_nonce' );

        $enable = isset( $_POST['yotm_enable_sizes'] ) && is_array( $_POST['yotm_enable_sizes'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['yotm_enable_sizes'] ) )
            : [];

        $all_sizes_for_save = array_keys( yotm_get_registered_sizes() );

        // Disabled = all - enabled
        $disabled = array_values( array_diff( $all_sizes_for_save, $enable ) );
        update_option( 'yotm_disabled_sizes', $disabled, true );

        $sizes_saved_notice = sprintf(
            /* translators: 1: number of enabled sizes. 2: number of disabled sizes. */
            __( 'Saved. %1$d size(s) enabled, %2$d disabled. These apply to future uploads.', 'thumbnail-manager' ),
            count( $enable ),
            count( $disabled )
        );

        $run_regenerate_after_save = ! empty( $_POST['yotm_save_and_regenerate'] );
    }

    $uploads  = wp_get_upload_dir();
    $base     = trailingslashit( $uploads['basedir'] );
    $sizes    = yotm_get_registered_sizes();
    $subpaths = yotm_list_upload_subpaths( $base );
	$prune_subpath_groups = array();

	foreach ( $subpaths as $subpath => $subpath_label ) {
		if ( '' === $subpath ) {
			continue;
		}

		$parts = explode( '/', $subpath, 2 );
		$group = $parts[0];
		if ( ! isset( $prune_subpath_groups[ $group ] ) ) {
			$prune_subpath_groups[ $group ] = array(
				'label' => isset( $subpaths[ $group ] ) ? $subpaths[ $group ] : $group,
				'items' => array(),
			);
		}

		$prune_subpath_groups[ $group ]['items'][ $subpath ] = $subpath_label;
	}

    // Get disabled sizes and detect if the option exists
    $disabled_now  = get_option( 'yotm_disabled_sizes', null );
    $option_exists = ! is_null( $disabled_now );
    if ( ! is_array( $disabled_now ) ) {
        $disabled_now = [];
    }

    // Sort sizes (largest first)
    uasort(
        $sizes,
        function ( $a, $b ) {
            $wa = (int) ( $a['width'] ?? 0 );
            $wb = (int) ( $b['width'] ?? 0 );
            return $wa === $wb ? 0 : ( $wa < $wb ? 1 : -1 );
        }
    );

    // Defaults for Tab 1 (Prune Files)
    // If option does not exist yet: keep thumbnail/medium/large.
    // If it exists: keep all sizes that are currently enabled (not in disabled list).
    if ( ! $option_exists ) {
        $default_keep = [ 'thumbnail', 'medium', 'large' ];
    } else {
        $default_keep = array_values(
            array_diff(
                array_keys( $sizes ),
                $disabled_now
            )
        );
    }
    ?>
    <div class="wrap yotm-wrap">
        <h1><?php echo esc_html__( 'Thumbnail Management', 'thumbnail-manager' ); ?></h1>
        <p><span class="dashicons dashicons-open-folder"></span> <code><?php echo esc_html( $base ); ?></code></p>

        <?php if ( $sizes_saved_notice ) : ?>
            <div class="notice notice-success">
                <p><?php echo esc_html( $sizes_saved_notice ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $run_regenerate_after_save ) ) : ?>
            <script>
                window.YOTM_RUN_REGENERATE_AFTER_SAVE = true;
            </script>
        <?php endif; ?>

		<section class="yo-job-center" aria-labelledby="yotm_job_center_title">
			<h2 id="yotm_job_center_title" class="screen-reader-text"><?php echo esc_html__( 'Job activity', 'thumbnail-manager' ); ?></h2>
			<div id="yotm_active_job" class="yo-active-job yo-hidden" role="status" aria-live="polite">
				<div>
					<strong id="yotm_active_job_title"><?php echo esc_html__( 'Active job', 'thumbnail-manager' ); ?></strong>
					<span id="yotm_active_job_meta"></span>
				</div>
				<button type="button" id="yotm_active_job_view" class="button"><?php echo esc_html__( 'View job', 'thumbnail-manager' ); ?></button>
			</div>
			<details id="yotm_recent_jobs" class="yo-recent-jobs">
				<summary><?php echo esc_html__( 'Recent jobs', 'thumbnail-manager' ); ?></summary>
				<div class="yo-table-scroll">
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Type', 'thumbnail-manager' ); ?></th>
								<th><?php echo esc_html__( 'Status', 'thumbnail-manager' ); ?></th>
								<th><?php echo esc_html__( 'Progress', 'thumbnail-manager' ); ?></th>
								<th><?php echo esc_html__( 'Updated', 'thumbnail-manager' ); ?></th>
							</tr>
						</thead>
						<tbody id="yotm_recent_jobs_body"></tbody>
					</table>
				</div>
			</details>
		</section>

	        <div class="yo-tabs" id="yotm_tabs" role="tablist">
	            <button type="button" class="yo-tab active" data-tab="regenerate" id="yotm_tab_regenerate" role="tab" tabindex="0" aria-controls="yotm_panel_regenerate" aria-selected="true">
	                <?php echo esc_html__( 'Regenerate', 'thumbnail-manager' ); ?>
	            </button>
	            <button type="button" class="yo-tab" data-tab="recommendations" id="yotm_tab_recommendations" role="tab" tabindex="-1" aria-controls="yotm_panel_recommendations" aria-selected="false">
	                <?php echo esc_html__( 'Recommendations', 'thumbnail-manager' ); ?>
	            </button>
	            <button type="button" class="yo-tab" data-tab="prune" id="yotm_tab_prune" role="tab" tabindex="-1" aria-controls="yotm_panel_prune" aria-selected="false">
	                <?php echo esc_html__( 'Prune Files', 'thumbnail-manager' ); ?>
	            </button>
	            <button type="button" class="yo-tab" data-tab="sizes" id="yotm_tab_sizes" role="tab" tabindex="-1" aria-controls="yotm_panel_sizes" aria-selected="false">
	                <?php echo esc_html__( 'Thumbnail Sizes', 'thumbnail-manager' ); ?>
	            </button>
	        </div>

        <!-- TAB 1: REGENERATE -->
	        <div class="yo-panel active" id="yotm_panel_regenerate" role="tabpanel" aria-labelledby="yotm_tab_regenerate">
            <p>
                <?php
                echo wp_kses(
                    __(
                        'Regenerate thumbnails for existing image attachments in your Media Library. This uses the sizes that are currently <strong>enabled</strong> in the <em>Thumbnail Sizes</em> tab.',
                        'thumbnail-manager'
                    ),
                    [
                        'strong' => [],
                        'em'     => [],
                    ]
                );
                ?>
            </p>

            <p class="description">
                <?php echo esc_html__( 'Recommended after changing thumbnail size settings or after enabling new sizes.', 'thumbnail-manager' ); ?>
            </p>

            <div class="yo-row">
                <label for="yotm_regen_scope">
                    <strong><?php echo esc_html__( 'Scope', 'thumbnail-manager' ); ?></strong>
                </label><br>
                <select id="yotm_regen_scope">
                    <option value="all"><?php echo esc_html__( 'All media', 'thumbnail-manager' ); ?></option>
                    <option value="year"><?php echo esc_html__( 'Current year only', 'thumbnail-manager' ); ?></option>
                    <option value="subpath"><?php echo esc_html__( 'Specific uploads folder', 'thumbnail-manager' ); ?></option>
                    <option value="ids"><?php echo esc_html__( 'Specific attachment IDs', 'thumbnail-manager' ); ?></option>
                </select>
            </div>

            <div class="yo-row yo-hidden" id="yotm_regen_subpath_wrap">
                <label for="yotm_regen_subpath">
                    <?php
                    echo wp_kses(
                        __( 'Choose subfolder inside <code>uploads/</code>:', 'thumbnail-manager' ),
                        [ 'code' => [] ]
                    );
                    ?>
                </label><br>
                <select id="yotm_regen_subpath">
                    <?php foreach ( $subpaths as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>">
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="yo-row yo-hidden" id="yotm_regen_ids_wrap">
                <label for="yotm_regen_attachment_ids">
                    <?php echo esc_html__( 'Attachment IDs', 'thumbnail-manager' ); ?>
                </label><br>
                <textarea
                    id="yotm_regen_attachment_ids"
                    rows="4"
                    style="width:100%;max-width:720px;"
                    placeholder="<?php echo esc_attr__( 'Example: 123, 456, 789', 'thumbnail-manager' ); ?>"
                ></textarea>
                <p class="description">
                    <?php echo esc_html__( 'Enter one or more Media Library attachment IDs separated by commas or spaces.', 'thumbnail-manager' ); ?>
                </p>
            </div>

            <fieldset class="yo-row yo-radio-group" id="yotm_regen_mode_group">
				<legend><strong><?php echo esc_html__( 'Regeneration mode', 'thumbnail-manager' ); ?></strong></legend>
				<label class="yo-radio-option">
					<input type="radio" name="yotm_regen_mode" value="missing" checked>
					<span><strong><?php echo esc_html__( 'Generate missing sizes only', 'thumbnail-manager' ); ?></strong><small><?php echo esc_html__( 'Faster and recommended for routine maintenance.', 'thumbnail-manager' ); ?></small></span>
				</label>
				<label class="yo-radio-option">
					<input type="radio" name="yotm_regen_mode" value="force">
					<span><strong><?php echo esc_html__( 'Force regenerate all selected images', 'thumbnail-manager' ); ?></strong><small><?php echo esc_html__( 'Rebuilds metadata from the original image and removes obsolete generated files.', 'thumbnail-manager' ); ?></small></span>
				</label>
            </fieldset>

            <div id="yotm_regen_force_note" class="notice notice-warning inline yo-hidden" role="alert">
				<p><?php echo esc_html__( 'Force mode is slower and may replace existing thumbnail files. Originals remain protected.', 'thumbnail-manager' ); ?></p>
            </div>

            <p class="yo-row">
                <button type="button" id="yotm_regen_run" class="button button-primary">
                    <?php echo esc_html__( 'Run regenerate', 'thumbnail-manager' ); ?>
                </button>
                <button id="yotm_regen_cancel" type="button" class="button yo-hidden">
                    <?php echo esc_html__( 'Stop job', 'thumbnail-manager' ); ?>
                </button>
            </p>

	            <div id="yotm_regen_progress" class="yo-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
	                <div class="bar"></div>
	            </div>
	            <div id="yotm_regen_status" class="yo-status" aria-live="polite"></div>
            <div id="yotm_regen_results" tabindex="-1" aria-live="polite"></div>
        </div>

        <!-- TAB: RECOMMENDATIONS -->
	        <div class="yo-panel" id="yotm_panel_recommendations" role="tabpanel" aria-labelledby="yotm_tab_recommendations" hidden>

            <h2>
                <?php echo esc_html__( 'Recommendations', 'thumbnail-manager' ); ?>
            </h2>

            <p>
                <?php echo esc_html__( 'These suggestions use policy protections and positive content-reference heuristics to recommend keeping sizes. Missing evidence never proves a size is unused, and recommendations never authorize deletion.', 'thumbnail-manager' ); ?>
            </p>

	            <div class="yo-row">
	                <button type="button" id="yotm_recommend_scan" class="button button-primary">
	                    <?php echo esc_html__( 'Scan media usage', 'thumbnail-manager' ); ?>
	                </button>
	                <button id="yotm_recommend_cancel" type="button" class="button yo-hidden">
	                    <?php echo esc_html__( 'Stop job', 'thumbnail-manager' ); ?>
	                </button>
	            </div>

	            <div id="yotm_recommend_progress" class="yo-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
	                <div class="bar"></div>
	            </div>

	            <div id="yotm_recommend_status" class="yo-status" aria-live="polite"></div>

            <div class="yo-card-grid" id="yotm_recommend_summary">

                <div class="yo-card">
	                    <strong><?php echo esc_html__( 'Protected', 'thumbnail-manager' ); ?></strong>
	                    <div class="yo-card-value" id="yotm_recommend_protected_count">—</div>
                </div>

                <div class="yo-card">
	                    <strong><?php echo esc_html__( 'Detected References', 'thumbnail-manager' ); ?></strong>
	                    <div class="yo-card-value" id="yotm_recommend_reference_count">—</div>
                </div>

                <div class="yo-card">
	                    <strong><?php echo esc_html__( 'Unknown / Review', 'thumbnail-manager' ); ?></strong>
	                    <div class="yo-card-value" id="yotm_recommend_unknown_count">—</div>
                </div>

                <div class="yo-card">
	                    <strong><?php echo esc_html__( 'Generated Footprint', 'thumbnail-manager' ); ?></strong>
	                    <div class="yo-card-value" id="yotm_recommend_generated_bytes">—</div>
                </div>

            </div>

            <div id="yotm_recommend_results" tabindex="-1" aria-live="polite"></div>

            <div class="yo-row">

                <button type="button" id="yotm_apply_recommendations" class="button button-primary yo-hidden">
	                    <?php echo esc_html__( 'Apply safe recommendations', 'thumbnail-manager' ); ?>
                </button>

                <button type="button" id="yotm_recommend_go_prune" class="button yo-hidden">
                    <?php echo esc_html__( 'Review in Prune Files', 'thumbnail-manager' ); ?>
                </button>

            </div>

	            <p class="description">
	                <?php echo esc_html__( 'Apply only enables sizes supported by keep evidence. Unknown sizes keep their current setting; saving remains a separate action.', 'thumbnail-manager' ); ?>
	            </p>

        </div>

        <!-- TAB 2: PRUNE FILES -->
	        <div class="yo-panel" id="yotm_panel_prune" role="tabpanel" aria-labelledby="yotm_tab_prune" hidden>
            <p>
                <?php
                echo wp_kses(
                    __(
	                        'Choose which registered image sizes to <strong>KEEP</strong>, then scan. The scan only builds a reviewable manifest; deletion requires a separate explicit approval after the scan completes.',
                        'thumbnail-manager'
                    ),
                    [
                        'strong' => [],
                        'em'     => [],
                    ]
                );
	                ?>
	            </p>

			<ol id="yotm_prune_steps" class="yo-stepper" aria-label="<?php echo esc_attr__( 'Prune progress', 'thumbnail-manager' ); ?>">
				<li data-step="configure" class="is-active" aria-current="step"><span>1</span><?php echo esc_html__( 'Configure', 'thumbnail-manager' ); ?></li>
				<li data-step="scanning"><span>2</span><?php echo esc_html__( 'Scan', 'thumbnail-manager' ); ?></li>
				<li data-step="review"><span>3</span><?php echo esc_html__( 'Review', 'thumbnail-manager' ); ?></li>
				<li data-step="deleting"><span>4</span><?php echo esc_html__( 'Delete', 'thumbnail-manager' ); ?></li>
				<li data-step="completed"><span>5</span><?php echo esc_html__( 'Complete', 'thumbnail-manager' ); ?></li>
			</ol>

			<fieldset class="yo-row yo-scope-picker" id="yotm_prune_scope_picker">
				<legend><strong><?php echo esc_html__( 'Uploads scope', 'thumbnail-manager' ); ?></strong></legend>
				<label class="yo-scope-mode">
					<input type="radio" name="yotm_prune_scope" value="all" checked>
					<span><strong><?php echo esc_html__( 'All uploads', 'thumbnail-manager' ); ?></strong><small><?php echo esc_html__( 'Scan the complete uploads directory.', 'thumbnail-manager' ); ?></small></span>
				</label>
				<label class="yo-scope-mode">
					<input type="radio" name="yotm_prune_scope" value="selected">
					<span><strong><?php echo esc_html__( 'Selected folders', 'thumbnail-manager' ); ?></strong><small><?php echo esc_html__( 'Choose multiple years or months to reduce scan time.', 'thumbnail-manager' ); ?></small></span>
				</label>

				<div id="yotm_subpath_picker" class="yo-subpath-picker yo-hidden">
					<div class="yo-subpath-toolbar">
						<label for="yotm_subpath_search" class="screen-reader-text"><?php echo esc_html__( 'Search uploads folders', 'thumbnail-manager' ); ?></label>
						<input type="search" id="yotm_subpath_search" placeholder="<?php echo esc_attr__( 'Search year or folder, e.g. 2024/08…', 'thumbnail-manager' ); ?>" autocomplete="off">
						<div class="yo-subpath-actions">
							<button type="button" class="button button-small" id="yotm_subpath_select_visible"><?php echo esc_html__( 'Select visible', 'thumbnail-manager' ); ?></button>
							<button type="button" class="button button-small" id="yotm_subpath_clear"><?php echo esc_html__( 'Clear', 'thumbnail-manager' ); ?></button>
						</div>
					</div>

					<div id="yotm_subpath_options" class="yo-subpath-options" role="group" aria-label="<?php echo esc_attr__( 'Available uploads folders', 'thumbnail-manager' ); ?>">
						<?php foreach ( $prune_subpath_groups as $group_name => $group_data ) :
							$child_items = array_filter(
								$group_data['items'],
								static function ( $item_path ) use ( $group_name ) {
									return $item_path !== $group_name;
								},
								ARRAY_FILTER_USE_KEY
							);
							$folder_count = max( 1, count( $child_items ) );
							?>
							<details class="yo-subpath-group" data-search="<?php echo esc_attr( strtolower( $group_name . ' ' . implode( ' ', array_keys( $group_data['items'] ) ) ) ); ?>">
								<summary>
									<span><?php echo esc_html( $group_name ); ?></span>
									<small>
										<?php
										printf(
											/* translators: %s: localized number of month/subfolder options. */
											esc_html( _n( '%s folder', '%s folders', $folder_count, 'thumbnail-manager' ) ),
											esc_html( number_format_i18n( $folder_count ) )
										);
										?>
									</small>
								</summary>
								<div class="yo-subpath-group-items">
									<label class="yo-subpath-option yo-subpath-parent" data-search="<?php echo esc_attr( strtolower( $group_name ) ); ?>">
										<input type="checkbox" class="yotm_subpath_option" value="<?php echo esc_attr( $group_name ); ?>" data-kind="parent">
										<span><strong><?php echo esc_html( 'uploads/' . $group_name . '/' ); ?></strong><small><?php echo esc_html__( 'Entire folder', 'thumbnail-manager' ); ?></small></span>
									</label>

									<?php foreach ( $child_items as $item_path => $item_label ) : ?>
										<label class="yo-subpath-option" data-search="<?php echo esc_attr( strtolower( $item_path . ' ' . $item_label ) ); ?>">
											<input type="checkbox" class="yotm_subpath_option" value="<?php echo esc_attr( $item_path ); ?>" data-ancestor="<?php echo esc_attr( $group_name ); ?>">
											<span><?php echo esc_html( 'uploads/' . $item_path . '/' ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</details>
						<?php endforeach; ?>
						<p id="yotm_subpath_no_results" class="description yo-hidden"><?php echo esc_html__( 'No folders match this search.', 'thumbnail-manager' ); ?></p>
					</div>

					<div class="yo-subpath-selection" aria-live="polite">
						<strong id="yotm_subpath_selection_count"><?php echo esc_html__( 'No folders selected', 'thumbnail-manager' ); ?></strong>
						<div id="yotm_subpath_chips" class="yo-subpath-chips"></div>
					</div>
				</div>
			</fieldset>

	            <form id="yotm_form" onsubmit="return false;">
				<div class="yo-table-scroll">
	                <table class="widefat striped yo-sizes" style="max-width:980px;">
                    <thead>
                        <tr>
                            <th style="width:4ch;"><?php echo esc_html__( 'Keep', 'thumbnail-manager' ); ?></th>
                            <th><?php echo esc_html__( 'Size name', 'thumbnail-manager' ); ?></th>
                            <th style="width:120px;"><?php echo esc_html__( 'Target (WxH)', 'thumbnail-manager' ); ?></th>
                            <th style="width:4ch;"><?php echo esc_html__( 'Crop', 'thumbnail-manager' ); ?></th>
	                            <th><?php echo esc_html__( 'Deletion evidence', 'thumbnail-manager' ); ?></th>
                            <th style="width:160px;"><?php echo esc_html__( 'Status', 'thumbnail-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $sizes as $name => $def ) :
                        $w = (int) ( $def['width'] ?? 0 );
                        $h = (int) ( $def['height'] ?? 0 );
                        if ( preg_match( '/^(\d+)x(\d+)$/', $name, $m ) ) {
                            $w = $w ?: (int) $m[1];
                            $h = $h ?: (int) $m[2];
                        }
                        $crop = ! empty( $def['crop'] );

	                        $note = __( 'Deletes only the exact generated filename recorded in attachment metadata; unverified sidecars and siblings are report-only.', 'thumbnail-manager' );

                        $is_disabled = in_array( $name, $disabled_now, true );
                        ?>
                        <tr>
                            <td>
                                <input
                                    type="checkbox"
                                    class="yotm_keep"
                                    value="<?php echo esc_attr( $name ); ?>"
                                    <?php checked( in_array( $name, $default_keep, true ) ); ?>
                                >
                            </td>
                            <td><code><?php echo esc_html( $name ); ?></code></td>
                            <td><?php echo esc_html( "{$w} × {$h}" ); ?></td>
                            <td><?php echo $crop ? esc_html__( 'Yes', 'thumbnail-manager' ) : esc_html__( 'No', 'thumbnail-manager' ); ?></td>
                            <td><?php echo esc_html( $note ); ?></td>
                            <td>
                                <?php
                                if ( $is_disabled ) {
                                    echo '<span style="color:#b32d2e">' . esc_html__( 'Disabled for new uploads', 'thumbnail-manager' ) . '</span>';
                                } else {
                                    echo '<span style="color:#1a7f37">' . esc_html__( 'Enabled', 'thumbnail-manager' ) . '</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
	                    </tbody>
	                </table>
				</div>

	                <p class="yo-row">
	                    <label>
	                        <input type="checkbox" id="yotm_discover_orphans" value="1">
	                        <?php
	                        echo wp_kses(
	                            __(
	                                'Also include orphan sizes found in attachment metadata and report unmapped disk matches',
	                                'thumbnail-manager'
	                            ),
                            [
                                'strong' => [],
                            ]
                        );
                        ?>
                    </label>
                    <span class="description" style="display:block;margin-top:4px;">
                        <?php echo esc_html__( 'Disk-only orphan reporting scans the selected uploads folder and never deletes unmapped files by default. Choose a smaller folder first on very large sites.', 'thumbnail-manager' ); ?>
                    </span>
                </p>

		                <p class="yo-row yo-actions">
		                    <button type="button" id="yotm_run" class="button button-primary">
		                        <?php echo esc_html__( 'Scan and review', 'thumbnail-manager' ); ?>
		                    </button>
		                    <button id="yotm_cancel" type="button" class="button yo-hidden">
	                        <?php echo esc_html__( 'Stop job', 'thumbnail-manager' ); ?>
	                    </button>
	                </p>
            </form>

	            <div id="yotm_progress" class="yo-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="bar"></div></div>
	            <div id="yotm_status" class="yo-status" aria-live="polite"></div>
	            <div id="yotm_results" tabindex="-1" aria-live="polite"></div>

			<section id="yotm_review_panel" class="yo-review-panel yo-hidden" tabindex="-1" aria-labelledby="yotm_review_title">
				<div class="yo-review-heading">
					<div>
						<h2 id="yotm_review_title"><?php echo esc_html__( 'Review deletion manifest', 'thumbnail-manager' ); ?></h2>
						<p><?php echo esc_html__( 'This manifest is immutable. Nothing will be deleted until you explicitly approve it below.', 'thumbnail-manager' ); ?></p>
					</div>
					<span class="yo-badge yo-review-badge"><?php echo esc_html__( 'Awaiting approval', 'thumbnail-manager' ); ?></span>
				</div>

				<div class="yo-review-summary">
					<div><span><?php echo esc_html__( 'Files', 'thumbnail-manager' ); ?></span><strong id="yotm_review_count">0</strong></div>
					<div><span><?php echo esc_html__( 'Estimated size', 'thumbnail-manager' ); ?></span><strong id="yotm_review_size">0 B</strong></div>
					<div><span><?php echo esc_html__( 'Scope', 'thumbnail-manager' ); ?></span><strong id="yotm_review_scope">uploads/</strong></div>
					<div><span><?php echo esc_html__( 'Review expires', 'thumbnail-manager' ); ?></span><strong id="yotm_review_expiry">—</strong></div>
				</div>

				<p class="yo-manifest-hash"><strong><?php echo esc_html__( 'Manifest hash', 'thumbnail-manager' ); ?></strong> <code id="yotm_review_hash"></code></p>
				<div id="yotm_review_orphans"></div>

				<div class="yo-manifest-tools">
					<label for="yotm_manifest_search" class="screen-reader-text"><?php echo esc_html__( 'Filter manifest', 'thumbnail-manager' ); ?></label>
					<input type="search" id="yotm_manifest_search" placeholder="<?php echo esc_attr__( 'Filter by path or size…', 'thumbnail-manager' ); ?>">
					<span id="yotm_manifest_count" aria-live="polite"></span>
				</div>

				<div class="yo-table-scroll">
					<table class="widefat striped yo-manifest-table">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'File path', 'thumbnail-manager' ); ?></th>
								<th><?php echo esc_html__( 'Attachment', 'thumbnail-manager' ); ?></th>
								<th><?php echo esc_html__( 'Ownership evidence', 'thumbnail-manager' ); ?></th>
								<th><?php echo esc_html__( 'Estimated size', 'thumbnail-manager' ); ?></th>
							</tr>
						</thead>
						<tbody id="yotm_manifest_body"></tbody>
					</table>
				</div>

				<div class="yo-pagination">
					<button type="button" id="yotm_manifest_prev" class="button"><?php echo esc_html__( 'Previous', 'thumbnail-manager' ); ?></button>
					<span id="yotm_manifest_page"><?php echo esc_html__( 'Page 1 of 1', 'thumbnail-manager' ); ?></span>
					<button type="button" id="yotm_manifest_next" class="button"><?php echo esc_html__( 'Next', 'thumbnail-manager' ); ?></button>
				</div>

				<div class="yo-delete-approval">
					<label>
						<input type="checkbox" id="yotm_review_confirm">
						<span><?php echo esc_html__( 'I reviewed this manifest and understand that only these files will be permanently deleted.', 'thumbnail-manager' ); ?></span>
					</label>
					<p class="description"><?php echo esc_html__( 'Approval is bound to your user, this site, and the manifest hash. It expires 30 minutes after confirmation.', 'thumbnail-manager' ); ?></p>
					<button type="button" id="yotm_approve_delete" class="button yo-button-danger" disabled><?php echo esc_html__( 'Delete reviewed files', 'thumbnail-manager' ); ?></button>
				</div>
			</section>
	        </div>

        <!-- TAB 3: THUMBNAIL SIZES -->
	        <div class="yo-panel" id="yotm_panel_sizes" role="tabpanel" aria-labelledby="yotm_tab_sizes" hidden>
            <p>
                <?php
                echo wp_kses(
                    __(
                        'Choose which sizes to <strong>generate for future uploads</strong>. Sizes you uncheck will be disabled at upload time.  To remove files that already exist for disabled sizes, use the <em>Prune Files</em> tab.',
                        'thumbnail-manager'
                    ),
                    [
                        'strong' => [],
                        'em'     => [],
                    ]
                );
                ?>
            </p>

	            <form method="post">
	                <?php wp_nonce_field( 'yotm_sizes_save_nonce', 'yotm_sizes_save_nonce' ); ?>
				<div class="yo-table-scroll">
	                <table class="widefat striped" style="max-width:980px;">
                    <thead>
                        <tr>
                            <th style="width:80px;"><?php echo esc_html__( 'Generate', 'thumbnail-manager' ); ?></th>
                            <th><?php echo esc_html__( 'Size name', 'thumbnail-manager' ); ?></th>
                            <th style="width:160px;"><?php echo esc_html__( 'Target (WxH)', 'thumbnail-manager' ); ?></th>
                            <th style="width:100px;"><?php echo esc_html__( 'Crop', 'thumbnail-manager' ); ?></th>
                            <th><?php echo esc_html__( 'Notes', 'thumbnail-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $sizes as $name => $def ) :
                        $w = (int) ( $def['width'] ?? 0 );
                        $h = (int) ( $def['height'] ?? 0 );
                        if ( preg_match( '/^(\d+)x(\d+)$/', $name, $m ) ) {
                            $w = $w ?: (int) $m[1];
                            $h = $h ?: (int) $m[2];
                        }
                        $crop    = ! empty( $def['crop'] );
                        $enabled = ! in_array( $name, $disabled_now, true );
                        $core    = in_array( $name, [ 'thumbnail', 'medium', 'large', 'medium_large', '1536x1536', '2048x2048' ], true );
                        ?>
                        <tr>
                            <td>
                                <input
                                    type="checkbox"
                                    name="yotm_enable_sizes[]"
                                    value="<?php echo esc_attr( $name ); ?>"
                                    <?php checked( $enabled ); ?>
                                >
                            </td>
                            <td>
                                <code><?php echo esc_html( $name ); ?></code>
                                <?php
                                if ( $core ) {
                                    echo '<span class="dashicons dashicons-wordpress-alt" title="' . esc_attr__( 'Core', 'thumbnail-manager' ) . '"></span>';
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html( "{$w} × {$h}" ); ?></td>
                            <td><?php echo $crop ? esc_html__( 'Yes', 'thumbnail-manager' ) : esc_html__( 'No', 'thumbnail-manager' ); ?></td>
                            <td>
                                <?php
                                echo $enabled
                                    ? esc_html__( 'Enabled', 'thumbnail-manager' )
                                    : esc_html__( 'Disabled (won’t be generated)', 'thumbnail-manager' );
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
	                    </tbody>
	                </table>
				</div>

                <p class="yo-row">
                    <button class="button button-primary" name="yotm_sizes_save" value="1">
                        <?php echo esc_html__( 'Save changes', 'thumbnail-manager' ); ?>
                    </button>
                    <button type="submit" class="button" name="yotm_save_and_regenerate" value="1">
                        <?php echo esc_html__( 'Save changes and run regenerate', 'thumbnail-manager' ); ?>
                    </button>
                    <button type="button" class="button" id="yotm_sizes_enable_core">
                        <?php echo esc_html__( 'Enable core only', 'thumbnail-manager' ); ?>
                    </button>
                    <button type="button" class="button" id="yotm_sizes_enable_all">
                        <?php echo esc_html__( 'Enable all', 'thumbnail-manager' ); ?>
                    </button>
                    <button type="button" class="button" id="yotm_sizes_disable_all">
                        <?php echo esc_html__( 'Disable all', 'thumbnail-manager' ); ?>
                    </button>
                </p>

                <p class="description">
                    <?php
                    echo wp_kses(
                        __(
                            'Changes above affect <strong>future uploads</strong>. Use the button below to remove existing thumbnails for disabled sizes.',
                            'thumbnail-manager'
                        ),
                        [
                            'strong' => [],
                        ]
                    );
                    ?>
                </p>

                <div class="yo-row" style="border-top:1px solid #ddd;padding-top:12px;margin-top:16px;">
                    <strong><?php echo esc_html__( 'Danger zone', 'thumbnail-manager' ); ?></strong>
                    <p>
                        <?php
                        echo wp_kses(
                            __(
                                'This will delete all thumbnails for sizes that are <em>disabled</em> below. It won’t affect originals.',
                                'thumbnail-manager'
                            ),
                            [
                                'em' => [],
                            ]
                        );
                        ?>
                    </p>
                    <button type="button" class="button button-secondary" id="yotm_sizes_prune_disabled">
                        <?php echo esc_html__( 'Delete thumbnails of disabled sizes', 'thumbnail-manager' ); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php
}
