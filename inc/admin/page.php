<?php
/**
 * Thumbnail Manager admin request and page adapter.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Tools administration page.
 */
function yotm_register_admin_page() {
	add_management_page(
		__( 'Thumbnails', 'thumbnail-manager' ),
		__( 'Thumbnails', 'thumbnail-manager' ),
		'manage_options',
		'yo-manage-thumbnails',
		'yotm_manage_thumbnails_page'
	);
}

/**
 * Map an authenticated Size Management form submission to Application.
 *
 * @return array|null Application outcome, or null when no form was submitted.
 */
function yotm_admin_handle_size_management_request() {
	if ( ! isset( $_POST['yotm_sizes_save'] ) && ! isset( $_POST['yotm_save_and_regenerate'] ) ) {
		return null;
	}

	check_admin_referer( 'yotm_sizes_save_nonce', 'yotm_sizes_save_nonce' );

	$enabled = isset( $_POST['yotm_enable_sizes'] ) && is_array( $_POST['yotm_enable_sizes'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['yotm_enable_sizes'] ) )
		: array();

	return yotm_size_management_save_application(
		$enabled,
		! empty( $_POST['yotm_save_and_regenerate'] )
	);
}

/**
 * Read the optional companion's state and link to native WordPress screens.
 * Viewing the introduction never installs, activates or processes anything.
 *
 * @return array
 */
function yotm_admin_optimizer_introduction() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$plugin    = 'yoohw-media-optimizer/yoohw-media-optimizer.php';
	$installed = file_exists( WP_PLUGIN_DIR . '/' . $plugin );
	$active    = class_exists( 'YoOhw_Media_Optimizer', false ) || ( $installed && is_plugin_active( $plugin ) );
	$ready     = $active && yotm_optimizer_available();
	$state     = $ready ? 'ready' : ( $active ? 'update' : ( $installed ? 'inactive' : 'missing' ) );
	$directory = 'https://wordpress.org/plugins/yoohw-media-optimizer/';
	$action    = array(
		'url'   => $directory,
		'label' => __( 'View on WordPress.org', 'thumbnail-manager' ),
	);
	if ( $ready ) {
		$action = array(
			'url'   => admin_url( 'upload.php?page=yoohw-media-optimizer' ),
			'label' => __( 'Open Media Optimizer', 'thumbnail-manager' ),
		);
	} elseif ( $active && current_user_can( 'activate_plugins' ) ) {
		$action = array(
			'url'   => self_admin_url( 'plugins.php' ),
			'label' => __( 'Manage plugin updates', 'thumbnail-manager' ),
		);
	} elseif ( $installed && current_user_can( 'activate_plugin', $plugin ) ) {
		$action = array(
			'url'   => self_admin_url( 'plugins.php' ),
			'label' => __( 'Review and activate in Plugins', 'thumbnail-manager' ),
		);
	} elseif ( ! $installed && current_user_can( 'install_plugins' ) ) {
		$action = array(
			'url'   => add_query_arg(
				array(
					'tab'    => 'plugin-information',
					'plugin' => 'yoohw-media-optimizer',
				),
				is_multisite() ? network_admin_url( 'plugin-install.php' ) : admin_url( 'plugin-install.php' )
			),
			'label' => __( 'View plugin and install', 'thumbnail-manager' ),
		);
	}//end if
	$labels = array(
		'ready'    => __( 'Active · Ready to work together', 'thumbnail-manager' ),
		'update'   => __( 'Active · Compatibility update needed', 'thumbnail-manager' ),
		'inactive' => __( 'Installed · Not active', 'thumbnail-manager' ),
		'missing'  => __( 'Not installed', 'thumbnail-manager' ),
	);
	return array(
		'state'      => $state,
		'status'     => $labels[ $state ],
		'action'     => $action,
		'can_manage' => current_user_can( 'install_plugins' ) || current_user_can( 'activate_plugin', $plugin ),
		'cleanup'    => $ready && defined( 'YoOhw_Media_Optimizer::THUMBNAIL_MANAGER_CLEANUP_API' ) && 1 === YoOhw_Media_Optimizer::THUMBNAIL_MANAGER_CLEANUP_API,
	);
}

/**
 * Build the presentation data for the administration page.
 *
 * @param array|null $size_outcome Optional Size Management outcome.
 * @return array
 */
function yotm_admin_build_page_view_model( $size_outcome = null ) {
	$uploads              = wp_get_upload_dir();
	$base                 = trailingslashit( $uploads['basedir'] );
	$sizes                = yotm_get_registered_sizes();
	$subpaths             = yotm_list_upload_subpaths( $base );
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

	$disabled_now  = yotm_get_disabled_sizes_option( null );
	$option_exists = ! is_null( $disabled_now );
	if ( ! is_array( $disabled_now ) ) {
		$disabled_now = array();
	}

	uasort(
		$sizes,
		function ( $a, $b ) {
			$wa = (int) ( $a['width'] ?? 0 );
			$wb = (int) ( $b['width'] ?? 0 );
			return $wa === $wb ? 0 : ( $wa < $wb ? 1 : -1 );
		}
	);

	$default_keep = $option_exists
		? array_values( array_diff( array_keys( $sizes ), $disabled_now ) )
		: array( 'thumbnail', 'medium', 'large' );

	$outcome_data = is_array( $size_outcome ) && ! empty( $size_outcome['success'] ) && is_array( $size_outcome['data'] ?? null )
		? $size_outcome['data']
		: array();
	$notice       = '';

	if ( $outcome_data ) {
		$notice = sprintf(
			/* translators: 1: number of enabled sizes. 2: number of disabled sizes. */
			__( 'Saved. %1$d size(s) enabled, %2$d disabled. These apply to future uploads.', 'thumbnail-manager' ),
			(int) ( $outcome_data['enabled_count'] ?? 0 ),
			(int) ( $outcome_data['disabled_count'] ?? 0 )
		);
	}

	return array(
		'optimizer_introduction'    => yotm_admin_optimizer_introduction(),
		'base'                      => $base,
		'sizes'                     => $sizes,
		'subpaths'                  => $subpaths,
		'prune_subpath_groups'      => $prune_subpath_groups,
		'disabled_now'              => $disabled_now,
		'default_keep'              => $default_keep,
		'sizes_saved_notice'        => $notice,
		'run_regenerate_after_save' => ! empty( $outcome_data['run_regenerate_after_save'] ),
	);
}

/**
 * Authorize, map, and render the administration page.
 */
function yotm_admin_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'thumbnail-manager' ) );
	}

	$outcome = yotm_admin_handle_size_management_request();
	yotm_render_admin_view( yotm_admin_build_page_view_model( $outcome ) );
}
