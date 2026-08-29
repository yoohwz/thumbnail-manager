<?php
/**
 * AJAX transport for persistent thumbnail regeneration jobs.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_yotm_regenerate_prepare', 'yotm_regenerate_prepare' );
add_action( 'wp_ajax_yotm_regenerate_batch', 'yotm_regenerate_batch' );

/**
 * Prepare a persistent regeneration job.
 */
function yotm_regenerate_prepare() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$outcome = yotm_regenerate_prepare_application(
		sanitize_text_field( wp_unslash( $_POST['scope'] ?? 'all' ) ),
		isset( $_POST['subpath'] ) ? sanitize_text_field( wp_unslash( $_POST['subpath'] ) ) : '',
		isset( $_POST['attachment_ids'] ) ? sanitize_textarea_field( wp_unslash( $_POST['attachment_ids'] ) ) : '',
		! empty( $_POST['force_all'] ),
		! empty( $_POST['only_missing'] )
	);
	yotm_regenerate_send_application_outcome( $outcome );
}

/**
 * Process a persistent regeneration job batch.
 */
function yotm_regenerate_batch() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$outcome = yotm_regenerate_batch_application(
		sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ),
		isset( $_POST['batch'] ) ? absint( wp_unslash( $_POST['batch'] ) ) : 20
	);
	yotm_regenerate_send_application_outcome( $outcome );
}

/**
 * Map an application outcome to the WordPress AJAX transport.
 *
 * @param array{success:bool,data:mixed,status:int} $outcome Application outcome.
 */
function yotm_regenerate_send_application_outcome( $outcome ) {
	$status = absint( $outcome['status'] ?? 200 );
	if ( ! empty( $outcome['success'] ) ) {
		wp_send_json_success( $outcome['data'] ?? null, $status );
	}

	wp_send_json_error( $outcome['data'] ?? null, $status );
}
