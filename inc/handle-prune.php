<?php
/**
 * Prune scan AJAX transport adapters.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/application/prune.php';

add_action( 'wp_ajax_yotm_prune_prepare', 'yotm_prune_prepare' );
add_action( 'wp_ajax_yotm_prune_scan_batch', 'yotm_prune_scan_batch' );

/**
 * Parse and authorize a persistent prune scan request.
 */
function yotm_prune_prepare() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}
	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$keep           = isset( $_POST['keep'] ) && is_array( $_POST['keep'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['keep'] ) ) : array();
	$limit_subpaths = isset( $_POST['limit_subpaths'] ) && is_array( $_POST['limit_subpaths'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['limit_subpaths'] ) ) : array();
	if ( empty( $limit_subpaths ) && isset( $_POST['limit_subpath'] ) ) {
		$limit_subpaths = array( sanitize_text_field( wp_unslash( $_POST['limit_subpath'] ) ) );
	}
	$result = yotm_prune_prepare_application(
		$keep,
		$limit_subpaths,
		! empty( $_POST['discover_orphans'] ),
		! empty( $_POST['discover_historical'] )
	);
	if ( empty( $result['success'] ) ) {
		wp_send_json_error( $result['data'], $result['status'] );
	}
	wp_send_json_success( $result['data'] );
}

/**
 * Parse and authorize one bounded prune scan request.
 */
function yotm_prune_scan_batch() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}
	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$token  = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	$batch  = isset( $_POST['batch'] ) ? absint( wp_unslash( $_POST['batch'] ) ) : 100;
	$result = yotm_prune_scan_application( $token, $batch );
	if ( empty( $result['success'] ) ) {
		wp_send_json_error( $result['data'], $result['status'] );
	}
	wp_send_json_success( $result['data'] );
}
