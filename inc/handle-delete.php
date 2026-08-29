<?php
/**
 * Prune approval and delete AJAX transport adapters.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/application/prune.php';

add_action( 'wp_ajax_yotm_prune_approve', 'yotm_prune_approve' );
add_action( 'wp_ajax_yotm_prune_delete_batch', 'yotm_prune_delete_batch' );

/**
 * Parse and authorize immutable prune approval.
 */
function yotm_prune_approve() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}
	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$token         = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	$manifest_hash = sanitize_text_field( wp_unslash( $_POST['manifest_hash'] ?? '' ) );
	$result        = yotm_prune_approve_application( $token, $manifest_hash, ! empty( $_POST['confirmed'] ) );
	if ( empty( $result['success'] ) ) {
		wp_send_json_error( $result['data'], $result['status'] );
	}
	wp_send_json_success( $result['data'] );
}

/**
 * Parse and authorize one bounded prune delete request.
 */
function yotm_prune_delete_batch() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}
	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$token         = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	$manifest_hash = sanitize_text_field( wp_unslash( $_POST['manifest_hash'] ?? '' ) );
	$batch         = isset( $_POST['batch'] ) ? absint( wp_unslash( $_POST['batch'] ) ) : 100;
	$result        = yotm_prune_delete_application( $token, $manifest_hash, $batch );
	if ( empty( $result['success'] ) ) {
		wp_send_json_error( $result['data'], $result['status'] );
	}
	wp_send_json_success( $result['data'] );
}
