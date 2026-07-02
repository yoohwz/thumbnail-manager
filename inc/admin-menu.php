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
	            'i18n'    => [
	                'allSizesEnabled'              => __( 'All sizes are enabled — there are no disabled sizes to prune.', 'thumbnail-manager' ),
	                'confirmPruneDisabled'         => __( 'This will delete thumbnails for all currently DISABLED sizes. Proceed?', 'thumbnail-manager' ),
	                'scanning'                     => __( 'Scanning…', 'thumbnail-manager' ),
	                'deleting'                     => __( 'Deleting…', 'thumbnail-manager' ),
	                'processing'                   => __( 'Processing…', 'thumbnail-manager' ),
	                'scanFailed'                   => __( 'Scan failed:', 'thumbnail-manager' ),
	                'prepareFailed'                => __( 'Prepare failed:', 'thumbnail-manager' ),
	                'deleteFailed'                 => __( 'Delete failed:', 'thumbnail-manager' ),
	                'unknownError'                 => __( 'Unknown error', 'thumbnail-manager' ),
	                'dryRunComplete'               => __( 'Dry-run complete. No deletions performed.', 'thumbnail-manager' ),
	                'networkPrepare'               => __( 'Network error during prepare.', 'thumbnail-manager' ),
	                'networkScan'                  => __( 'Network error during scan.', 'thumbnail-manager' ),
	                'networkDelete'                => __( 'Network error during delete.', 'thumbnail-manager' ),
	                'cancelled'                    => __( 'Cancelled.', 'thumbnail-manager' ),
	                'noMatchingThumbnails'         => __( 'No matching thumbnails found. Try enabling orphan discovery or widen the folder scope.', 'thumbnail-manager' ),
	                'doneDeleted'                  => __( 'Done. Deleted %1$s files — Freed %2$s.', 'thumbnail-manager' ),
	                'regenerationCancelled'        => __( 'Regeneration cancelled.', 'thumbnail-manager' ),
	                'preparingRegenerationQueue'   => __( 'Preparing regeneration queue…', 'thumbnail-manager' ),
	                'starting'                     => __( 'Starting…', 'thumbnail-manager' ),
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
	                'size'                         => __( 'Size', 'thumbnail-manager' ),
	                'dimensions'                   => __( 'Dimensions', 'thumbnail-manager' ),
	                'status'                       => __( 'Status', 'thumbnail-manager' ),
	                'reason'                       => __( 'Reason', 'thumbnail-manager' ),
	                'recommendation'               => __( 'Recommendation', 'thumbnail-manager' ),
	                'scanningMediaUsage'           => __( 'Scanning media usage…', 'thumbnail-manager' ),
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
    <div class="wrap">
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

	        <div class="yo-tabs" id="yotm_tabs" role="tablist">
	            <div class="yo-tab active" data-tab="regenerate" id="yotm_tab_regenerate" role="tab" tabindex="0" aria-controls="yotm_panel_regenerate" aria-selected="true">
	                <?php echo esc_html__( 'Regenerate', 'thumbnail-manager' ); ?>
	            </div>
	            <div class="yo-tab" data-tab="recommendations" id="yotm_tab_recommendations" role="tab" tabindex="-1" aria-controls="yotm_panel_recommendations" aria-selected="false">
	                <?php echo esc_html__( 'Recommendations', 'thumbnail-manager' ); ?>
	            </div>
	            <div class="yo-tab" data-tab="prune" id="yotm_tab_prune" role="tab" tabindex="-1" aria-controls="yotm_panel_prune" aria-selected="false">
	                <?php echo esc_html__( 'Prune Files', 'thumbnail-manager' ); ?>
	            </div>
	            <div class="yo-tab" data-tab="sizes" id="yotm_tab_sizes" role="tab" tabindex="-1" aria-controls="yotm_panel_sizes" aria-selected="false">
	                <?php echo esc_html__( 'Thumbnail Sizes', 'thumbnail-manager' ); ?>
	            </div>
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

            <div class="yo-row">
                <label>
                    <input type="checkbox" id="yotm_regen_only_missing" value="1" checked>
                    <?php echo esc_html__( 'Only generate missing thumbnails', 'thumbnail-manager' ); ?>
                </label>
                <br>
                <label>
                    <input type="checkbox" id="yotm_regen_force_all" value="1">
                    <?php echo esc_html__( 'Force regenerate all selected items', 'thumbnail-manager' ); ?>
                </label>
            </div>

            <p class="description">
                <?php echo esc_html__( 'Force regenerate ignores the “only missing” optimization and rebuilds metadata for the selected attachments.', 'thumbnail-manager' ); ?>
            </p>

            <p class="yo-row">
                <button id="yotm_regen_run" class="button button-primary">
                    <?php echo esc_html__( 'Run regenerate', 'thumbnail-manager' ); ?>
                </button>
                <button id="yotm_regen_cancel" type="button" class="button yo-hidden">
                    <?php echo esc_html__( 'Cancel', 'thumbnail-manager' ); ?>
                </button>
            </p>

	            <div id="yotm_regen_progress" class="yo-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
	                <div class="bar"></div>
	            </div>
	            <div id="yotm_regen_status" class="yo-status" aria-live="polite"></div>
            <div id="yotm_regen_results"></div>
        </div>

        <!-- TAB: RECOMMENDATIONS -->
	        <div class="yo-panel" id="yotm_panel_recommendations" role="tabpanel" aria-labelledby="yotm_tab_recommendations" hidden>

            <h2>
                <?php echo esc_html__( 'Recommendations', 'thumbnail-manager' ); ?>
            </h2>

            <p>
                <?php echo esc_html__( 'These suggestions combine protected size names, generated thumbnail metadata, and content references. They are still heuristic, so always run Dry-run before pruning.', 'thumbnail-manager' ); ?>
            </p>

            <div class="yo-row">
                <button id="yotm_recommend_scan" class="button button-primary">
                    <?php echo esc_html__( 'Scan media usage', 'thumbnail-manager' ); ?>
                </button>
            </div>

	            <div id="yotm_recommend_progress" class="yo-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
	                <div class="bar"></div>
	            </div>

	            <div id="yotm_recommend_status" class="yo-status" aria-live="polite"></div>

            <div class="yo-card-grid" id="yotm_recommend_summary">

                <div class="yo-card">
                    <strong><?php echo esc_html__( 'Recommended Keep', 'thumbnail-manager' ); ?></strong>
                    <div class="yo-card-value" id="yotm_recommend_keep_count">—</div>
                </div>

                <div class="yo-card">
                    <strong><?php echo esc_html__( 'Likely Unused', 'thumbnail-manager' ); ?></strong>
                    <div class="yo-card-value" id="yotm_recommend_unused_count">—</div>
                </div>

                <div class="yo-card">
                    <strong><?php echo esc_html__( 'Protected', 'thumbnail-manager' ); ?></strong>
                    <div class="yo-card-value" id="yotm_recommend_protected_count">—</div>
                </div>

                <div class="yo-card">
                    <strong><?php echo esc_html__( 'Potential Savings', 'thumbnail-manager' ); ?></strong>
                    <div class="yo-card-value" id="yotm_recommend_savings">—</div>
                </div>

            </div>

            <div id="yotm_recommend_results"></div>

            <div class="yo-row">

                <button id="yotm_apply_recommendations" class="button button-primary yo-hidden">
                    <?php echo esc_html__( 'Apply recommended sizes', 'thumbnail-manager' ); ?>
                </button>

                <button id="yotm_recommend_go_prune" class="button yo-hidden">
                    <?php echo esc_html__( 'Review in Prune Files', 'thumbnail-manager' ); ?>
                </button>

            </div>

        </div>

        <!-- TAB 2: PRUNE FILES -->
	        <div class="yo-panel" id="yotm_panel_prune" role="tabpanel" aria-labelledby="yotm_tab_prune" hidden>
            <p>
                <?php
                echo wp_kses(
                    __(
                        'Choose which registered image sizes to <strong>KEEP</strong>. All <em>non-selected</em> sizes will be scanned and (if Delete selected) removed in batches with a progress bar.',
                        'thumbnail-manager'
                    ),
                    [
                        'strong' => [],
                        'em'     => [],
                    ]
                );
                ?>
            </p>

            <div class="yo-row">
                <label>
                    <?php
                    echo wp_kses(
                        __(
                            'Limit to subfolder inside <code>uploads/</code>:',
                            'thumbnail-manager'
                        ),
                        [
                            'code' => [],
                        ]
                    );
                    ?>
                </label><br>
                <select id="yotm_limit_subpath">
                    <?php foreach ( $subpaths as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>">
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <form id="yotm_form" onsubmit="return false;">
                <table class="widefat striped yo-sizes" style="max-width:980px;">
                    <thead>
                        <tr>
                            <th style="width:4ch;"><?php echo esc_html__( 'Keep', 'thumbnail-manager' ); ?></th>
                            <th><?php echo esc_html__( 'Size name', 'thumbnail-manager' ); ?></th>
                            <th style="width:120px;"><?php echo esc_html__( 'Target (WxH)', 'thumbnail-manager' ); ?></th>
                            <th style="width:4ch;"><?php echo esc_html__( 'Crop', 'thumbnail-manager' ); ?></th>
                            <th><?php echo esc_html__( 'File variants', 'thumbnail-manager' ); ?></th>
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

                        $note = __( 'Uses attachment metadata, plus @2x / -1 / .webp / .bak variants when present', 'thumbnail-manager' );

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

                <p class="yo-row" style="margin-top:12px;">
                    <label>
                        <input type="radio" name="yotm_mode" value="dry" checked>
                        <?php echo esc_html__( 'Dry-run (no deletion)', 'thumbnail-manager' ); ?>
                    </label><br>
                    <label>
                        <input type="radio" name="yotm_mode" value="delete">
                        <?php echo esc_html__( 'Delete matching thumbnails', 'thumbnail-manager' ); ?>
                    </label>
                </p>

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

                <p class="yo-row">
                    <button id="yotm_run" class="button button-primary">
                        <?php echo esc_html__( 'Run', 'thumbnail-manager' ); ?>
                    </button>
                    <button id="yotm_cancel" type="button" class="button yo-hidden">
                        <?php echo esc_html__( 'Cancel', 'thumbnail-manager' ); ?>
                    </button>
                </p>
            </form>

	            <div id="yotm_progress" class="yo-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="bar"></div></div>
	            <div id="yotm_status" class="yo-status" aria-live="polite"></div>
            <div id="yotm_results"></div>
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
