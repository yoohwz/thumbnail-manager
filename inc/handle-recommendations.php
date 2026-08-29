<?php
/**
 * Recommendations AJAX transport adapters.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/application/recommendations.php';

add_action( 'wp_ajax_yotm_recommend_prepare', 'yotm_recommend_prepare' );
add_action( 'wp_ajax_yotm_recommend_batch', 'yotm_recommend_batch' );

/**
 * Parse and authorize a recommendation prepare request.
 */
function yotm_recommend_prepare() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	yotm_recommend_send_application_outcome( yotm_recommend_prepare_application() );
}

/**
 * Parse and authorize one recommendation batch request.
 */
function yotm_recommend_batch() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$outcome = yotm_recommend_batch_application(
		sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ),
		isset( $_POST['batch'] ) ? absint( wp_unslash( $_POST['batch'] ) ) : 100
	);
	yotm_recommend_send_application_outcome( $outcome );
}

/**
 * Map an application outcome to the WordPress AJAX transport.
 *
 * @param array{success:bool,data:mixed,status:int} $outcome Application outcome.
 */
function yotm_recommend_send_application_outcome( $outcome ) {
	$status = absint( $outcome['status'] ?? 200 );
	if ( ! empty( $outcome['success'] ) ) {
		wp_send_json_success( $outcome['data'] ?? null, $status );
	}

	wp_send_json_error( $outcome['data'] ?? null, $status );
}
