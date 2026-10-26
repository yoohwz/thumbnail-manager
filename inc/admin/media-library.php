<?php
/**
 * Read-only Media Library entry points for thumbnail inspection.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validate a complete explicit image selection without silently reducing scope.
 *
 * @param mixed $raw Comma/space separated IDs, or an array of IDs.
 * @return array Selection and any validation error.
 */
function yotm_admin_media_selection( $raw ) {
	$selection = array(
		'requested' => true,
		'ids'       => array(),
		'error'     => '',
	);
	$parts     = is_array( $raw ) ? $raw : ( is_scalar( $raw ) ? preg_split( '/[\s,]+/', trim( (string) $raw ), -1, PREG_SPLIT_NO_EMPTY ) : array() );
	if ( empty( $parts ) ) {
		$selection['error'] = __( 'Choose at least one image from the Media Library.', 'thumbnail-manager' );
		return $selection;
	}
	$ids = array();
	foreach ( $parts as $part ) {
		if ( ! is_scalar( $part ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $part ) || (string) (int) $part !== (string) $part ) {
			$selection['error'] = __( 'The image selection is invalid. Choose images again from the Media Library.', 'thumbnail-manager' );
			return $selection;
		}
		$ids[ (int) $part ] = (int) $part;
	}
	if ( count( $ids ) > 500 ) {
		$selection['error'] = __( 'Choose up to 500 images at a time. No images have been selected.', 'thumbnail-manager' );
		return $selection;
	}
	foreach ( $ids as $id ) {
		if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			$selection['error'] = __( 'The selection contains an unavailable or non-image attachment. Choose images again.', 'thumbnail-manager' );
			return $selection;
		}
	}
	$selection['ids'] = array_values( $ids );
	return $selection;
}

/**
 * Read the optional navigation selection; this never starts a media operation.
 *
 * @return array Validated navigation data.
 */
function yotm_admin_requested_media_selection() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation state; AJAX operations require their own nonce.
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['yotm_attachment_ids'] ) ) {
		return array(
			'requested' => false,
			'ids'       => array(),
			'error'     => '',
		);
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only selection is strictly validated as IDs in full by yotm_admin_media_selection.
	return yotm_admin_media_selection( wp_unslash( $_GET['yotm_attachment_ids'] ) );
}

/**
 * Build a selection-only destination.
 *
 * @param array $ids Attachment IDs.
 * @return string Administration URL.
 */
function yotm_admin_media_health_url( $ids ) {
	return add_query_arg(
		array(
			'page'                => 'yo-manage-thumbnails',
			'yotm_attachment_ids' => implode( ',', $ids ),
		),
		admin_url( 'tools.php' )
	);
}

/**
 * Add the individual image inspection link.
 *
 * @param array   $actions Existing row actions.
 * @param WP_Post $post Attachment.
 * @return array Row actions.
 */
function yotm_admin_media_health_row_action( $actions, $post ) {
	if ( current_user_can( 'manage_options' ) && wp_attachment_is_image( $post->ID ) ) {
		$actions['yotm_thumbnail_health'] = '<a href="' . esc_url( yotm_admin_media_health_url( array( $post->ID ) ) ) . '">' . esc_html__( 'Check thumbnails', 'thumbnail-manager' ) . '</a>';
	}
	return $actions;
}

/**
 * Register the list-view bulk selection action.
 *
 * @param array $actions Existing bulk actions.
 * @return array Bulk actions.
 */
function yotm_admin_media_health_bulk_action( $actions ) {
	if ( current_user_can( 'manage_options' ) ) {
		$actions['yotm_thumbnail_health'] = __( 'Check thumbnails', 'thumbnail-manager' );
	}
	return $actions;
}

/**
 * Route all selected IDs without starting inspection or repair.
 *
 * @param string $redirect Existing redirect.
 * @param string $action Selected action.
 * @param array  $ids Selected IDs.
 * @return string Redirect URL.
 */
function yotm_admin_media_health_bulk_redirect( $redirect, $action, $ids ) {
	if ( 'yotm_thumbnail_health' !== $action || ! current_user_can( 'manage_options' ) ) {
		return $redirect;
	}
	$selection = yotm_admin_media_selection( $ids );
	// Preserve invalid/oversized selection as an explicit invalid scope, never all media.
	return yotm_admin_media_health_url( $selection['error'] ? array( 'invalid' ) : $selection['ids'] );
}

/**
 * Add the same entry point to the native attachment details form.
 *
 * @param array   $fields Attachment fields.
 * @param WP_Post $post Attachment.
 * @return array Attachment fields.
 */
function yotm_admin_media_health_attachment_field( $fields, $post ) {
	if ( current_user_can( 'manage_options' ) && wp_attachment_is_image( $post->ID ) ) {
		$fields['yotm_thumbnail_health'] = array(
			'label' => __( 'Thumbnails', 'thumbnail-manager' ),
			'input' => 'html',
			'html'  => '<a href="' . esc_url( yotm_admin_media_health_url( array( $post->ID ) ) ) . '">' . esc_html__( 'Check thumbnails', 'thumbnail-manager' ) . '</a>',
		);
	}
	return $fields;
}

add_filter( 'media_row_actions', 'yotm_admin_media_health_row_action', 10, 2 );
add_filter( 'bulk_actions-upload', 'yotm_admin_media_health_bulk_action' );
add_filter( 'handle_bulk_actions-upload', 'yotm_admin_media_health_bulk_redirect', 10, 3 );
add_filter( 'attachment_fields_to_edit', 'yotm_admin_media_health_attachment_field', 10, 2 );
