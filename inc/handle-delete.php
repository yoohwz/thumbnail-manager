<?php
/**
 * Explicit prune approval and resumable deletion.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_yotm_prune_approve', 'yotm_prune_approve' );
add_action( 'wp_ajax_yotm_prune_delete_batch', 'yotm_prune_delete_batch' );

/**
 * Approve an immutable, reviewed prune manifest for a short delete window.
 */
function yotm_prune_approve() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$token         = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	$manifest_hash = sanitize_text_field( wp_unslash( $_POST['manifest_hash'] ?? '' ) );
	$confirmed     = ! empty( $_POST['confirmed'] );
	$job           = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		wp_send_json_error( array( 'msg' => $job->get_error_message() ), 400 );
	}

	$valid = yotm_prune_validate_review_job( $job, $manifest_hash, $confirmed );

	if ( is_wp_error( $valid ) ) {
		wp_send_json_error( array( 'msg' => $valid->get_error_message() ), 409 );
	}

	$payload                = $job['payload'];
	$payload['approved_at'] = gmdate( 'c' );
	yotm_job_update(
		$job['id'],
		array(
			'payload'    => $payload,
			'status'     => 'approved',
			'phase'      => 'delete',
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS ),
		)
	);

	wp_send_json_success(
		array(
			'token'         => $job['token'],
			'manifest_hash' => $job['manifest_hash'],
			'total'         => $job['total'],
			'expires_in'    => 30 * MINUTE_IN_SECONDS,
		)
	);
}

/**
 * Delete approved items in bounded, resumable batches.
 */
function yotm_prune_delete_batch() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$token         = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	$manifest_hash = sanitize_text_field( wp_unslash( $_POST['manifest_hash'] ?? '' ) );
	$batch         = isset( $_POST['batch'] ) ? absint( wp_unslash( $_POST['batch'] ) ) : 100;
	$batch         = max( 1, min( 500, $batch ) );
	$job           = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		wp_send_json_error( array( 'msg' => $job->get_error_message() ), 400 );
	}

	$delete_allowed = yotm_prune_validate_delete_job( $job, $manifest_hash );

	if ( is_wp_error( $delete_allowed ) ) {
		wp_send_json_error( array( 'msg' => $delete_allowed->get_error_message() ), 409 );
	}

	if ( 'approved' === $job['status'] ) {
		yotm_job_update(
			$job['id'],
			array(
				'status' => 'deleting',
				'phase'  => 'delete',
			)
		);
		$job = yotm_job_get_by_id( $job['id'] );
	}

	$items         = yotm_job_get_items( $job['id'], array( 'queued', 'processing' ), $batch );
	$processed_now = 0;
	$deleted_now   = 0;
	$failed_now    = 0;
	$freed_now     = 0;
	$base          = (string) ( $job['payload']['base'] ?? ( wp_get_upload_dir()['basedir'] ?? '' ) );

	foreach ( $items as $item ) {
		yotm_job_update_item( $item['id'], 'processing' );
		$result = yotm_delete_prune_item( $item['payload'], $base );
		++$processed_now;

		if ( ! empty( $result['deleted'] ) ) {
			++$deleted_now;
			$freed_now += (int) $result['bytes'];
			yotm_job_update_item( $item['id'], 'done', '', (int) $result['bytes'] );
		} elseif ( ! empty( $result['skipped'] ) ) {
			yotm_job_update_item( $item['id'], 'skipped', (string) ( $result['message'] ?? '' ) );
		} else {
			++$failed_now;
			yotm_job_update_item( $item['id'], 'failed', (string) ( $result['error'] ?? __( 'Could not delete the file.', 'thumbnail-manager' ) ) );
		}
	}

	$processed = $job['processed'] + $processed_now;
	$deleted   = $job['succeeded'] + $deleted_now;
	$failed    = $job['failed'] + $failed_now;
	$bytes     = $job['bytes'] + $freed_now;
	$remaining = yotm_job_count_items( $job['id'], 'queued' ) + yotm_job_count_items( $job['id'], 'processing' );
	$fields    = array(
		'processed' => $processed,
		'succeeded' => $deleted,
		'failed'    => $failed,
		'bytes'     => $bytes,
	);
	$done      = 0 === $remaining;
	$current   = yotm_job_get_by_id( $job['id'] );
	$stopped   = is_array( $current ) && 'cancelled' === $current['status'];

	if ( $done && ! $stopped ) {
		$fields['status']     = 'completed';
		$fields['phase']      = 'completed';
		$fields['expires_at'] = gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
	}

	yotm_job_update( $job['id'], $fields );
	$total   = max( 1, $job['total'] );
	$percent = $done && ! $stopped ? 100 : min( 99.9, ( $processed / $total ) * 100 );

	wp_send_json_success(
		array(
			'processed'     => $processed,
			'deleted'       => $deleted,
			'deleted_count' => $deleted_now,
			'failed'        => $failed,
			'failed_count'  => $failed_now,
			'skipped'       => max( 0, $processed - $deleted - $failed ),
			'bytes'         => $bytes,
			'bytes_human'   => size_format( $bytes ),
			'remaining'     => $remaining,
			'percent'       => $percent,
			'done'          => $done && ! $stopped,
			'stopped'       => $stopped,
			'status'        => $stopped ? 'cancelled' : ( $done ? 'completed' : 'deleting' ),
			'errors'        => yotm_job_get_error_sample( $job['id'] ),
		)
	);
}

/**
 * Validate a completed manifest before approval.
 *
 * @param array  $job Job row.
 * @param string $manifest_hash Submitted manifest hash.
 * @param bool   $confirmed Explicit confirmation flag.
 * @return true|WP_Error
 */
function yotm_prune_validate_review_job( $job, $manifest_hash, $confirmed ) {
	if ( ! is_array( $job ) || 'prune' !== ( $job['type'] ?? '' ) || 'awaiting_approval' !== ( $job['status'] ?? '' ) ) {
		return new WP_Error( 'yotm_scan_not_reviewable', __( 'The prune scan is not ready for approval.', 'thumbnail-manager' ) );
	}

	if ( ! $confirmed ) {
		return new WP_Error( 'yotm_confirmation_required', __( 'Explicit delete confirmation is required.', 'thumbnail-manager' ) );
	}

	if ( empty( $job['manifest_hash'] ) || empty( $manifest_hash ) || ! hash_equals( $job['manifest_hash'], $manifest_hash ) ) {
		return new WP_Error( 'yotm_manifest_changed', __( 'The reviewed manifest does not match this job. Run the scan again.', 'thumbnail-manager' ) );
	}

	if ( empty( $job['total'] ) ) {
		return new WP_Error( 'yotm_empty_manifest', __( 'There are no files in this manifest.', 'thumbnail-manager' ) );
	}

	return true;
}

/**
 * Validate the short-lived delete grant and immutable manifest.
 *
 * @param array  $job Job row.
 * @param string $manifest_hash Submitted manifest hash.
 * @return true|WP_Error
 */
function yotm_prune_validate_delete_job( $job, $manifest_hash ) {
	if ( ! is_array( $job ) || 'prune' !== ( $job['type'] ?? '' ) || ! in_array( $job['status'] ?? '', array( 'approved', 'deleting' ), true ) ) {
		return new WP_Error( 'yotm_not_delete_mode', __( 'This job has not been approved for deletion.', 'thumbnail-manager' ) );
	}

	if ( empty( $job['manifest_hash'] ) || empty( $manifest_hash ) || ! hash_equals( $job['manifest_hash'], $manifest_hash ) ) {
		return new WP_Error( 'yotm_manifest_changed', __( 'The delete manifest does not match the reviewed scan.', 'thumbnail-manager' ) );
	}

	if ( strtotime( $job['expires_at'] . ' UTC' ) < time() ) {
		return new WP_Error( 'yotm_delete_grant_expired', __( 'The delete approval has expired. Run and review a new scan.', 'thumbnail-manager' ) );
	}

	return true;
}

/**
 * Backward-compatible validation helper retained for older integrations.
 *
 * @param array $meta Legacy metadata.
 * @return true|WP_Error
 */
function yotm_prune_validate_delete_meta( $meta ) {
	if ( ! is_array( $meta ) || ( $meta['mode'] ?? '' ) !== 'delete' ) {
		return new WP_Error( 'yotm_not_delete_mode', __( 'This token was not prepared for deletion.', 'thumbnail-manager' ) );
	}

	if ( empty( $meta['scan_done'] ) ) {
		return new WP_Error( 'yotm_scan_not_done', __( 'Scan is still running.', 'thumbnail-manager' ) );
	}

	return true;
}

/**
 * Delete a file via the WordPress API and return bytes freed.
 *
 * @param string $path File path.
 * @return int
 */
function yotm_delete_file_and_count( $path ) {
	$uploads = wp_get_upload_dir();
	$base    = $uploads['basedir'] ?? '';

	if ( '' === $base || ! yotm_is_path_inside_dir( $path, $base ) ) {
		return 0;
	}

	$result = yotm_delete_file_with_result( $path );

	return ! empty( $result['deleted'] ) ? (int) $result['bytes'] : 0;
}

/**
 * Delete a manifest item and reconcile every attachment metadata reference.
 *
 * @param array|string $item Item payload.
 * @param string       $uploads_base Uploads base path.
 * @return array
 */
function yotm_delete_prune_item( $item, $uploads_base ) {
	$path = is_array( $item ) ? ( $item['path'] ?? '' ) : $item;
	$path = yotm_normalize_filesystem_path( $path );

	if ( '' === $path || ! yotm_is_path_inside_dir( $path, $uploads_base ) ) {
		return array(
			'deleted' => false,
			'skipped' => false,
			'bytes'   => 0,
			'error'   => __( 'File path is outside uploads.', 'thumbnail-manager' ),
		);
	}

	if ( ! is_file( $path ) ) {
		yotm_reconcile_prune_item_metadata( $item, $path );

		return array(
			'deleted' => false,
			'skipped' => true,
			'bytes'   => 0,
			'message' => __( 'File was already missing; metadata was reconciled.', 'thumbnail-manager' ),
		);
	}

	$result = yotm_delete_file_with_result( $path );

	if ( ! empty( $result['deleted'] ) ) {
		yotm_reconcile_prune_item_metadata( $item, $path );
	}

	return $result;
}

/**
 * Remove all metadata references recorded for a deleted path.
 *
 * @param array|string $item Item payload.
 * @param string       $path File path.
 */
function yotm_reconcile_prune_item_metadata( $item, $path ) {
	if ( ! is_array( $item ) ) {
		return;
	}

	$refs = is_array( $item['metadata_refs'] ?? null ) ? $item['metadata_refs'] : array();

	if ( empty( $refs ) && ! empty( $item['remove_metadata'] ) && ! empty( $item['attachment_id'] ) && ! empty( $item['size'] ) ) {
		$refs[] = array(
			'attachment_id' => $item['attachment_id'],
			'size'          => $item['size'],
			'filename'      => wp_basename( $path ),
		);
	}

	$seen = array();
	foreach ( $refs as $ref ) {
		$attachment_id = absint( $ref['attachment_id'] ?? 0 );
		$size          = sanitize_key( $ref['size'] ?? '' );
		$filename      = sanitize_file_name( $ref['filename'] ?? wp_basename( $path ) );
		$key           = $attachment_id . ':' . $size . ':' . $filename;

		if ( ! $attachment_id || '' === $size || isset( $seen[ $key ] ) ) {
			continue;
		}

		$seen[ $key ] = true;
		yotm_remove_attachment_size_metadata( $attachment_id, $size, $filename );
	}
}

/**
 * Delete a local file and report a useful failure.
 *
 * @param string $path File path.
 * @return array
 */
function yotm_delete_file_with_result( $path ) {
	$path = yotm_normalize_filesystem_path( $path );

	if ( ! is_file( $path ) ) {
		return array(
			'deleted' => false,
			'skipped' => true,
			'bytes'   => 0,
			'message' => __( 'File does not exist.', 'thumbnail-manager' ),
		);
	}

	if ( ! is_readable( $path ) ) {
		return array(
			'deleted' => false,
			'skipped' => false,
			'bytes'   => 0,
			'error'   => __( 'File is not readable.', 'thumbnail-manager' ),
		);
	}

	$bytes = filesize( $path );
	$bytes = false === $bytes ? 0 : $bytes;
	wp_delete_file( $path );

	if ( file_exists( $path ) ) {
		return array(
			'deleted' => false,
			'skipped' => false,
			'bytes'   => 0,
			'error'   => __( 'WordPress could not delete the file.', 'thumbnail-manager' ),
		);
	}

	return array(
		'deleted' => true,
		'skipped' => false,
		'bytes'   => $bytes,
		'error'   => '',
	);
}

/**
 * Remove a generated size reference from attachment metadata.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $size_name Size name.
 * @param string $filename Generated filename.
 * @return bool
 */
function yotm_remove_attachment_size_metadata( $attachment_id, $size_name, $filename ) {
	$metadata = wp_get_attachment_metadata( $attachment_id );

	if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
		return false;
	}

	$changed = false;

	foreach ( $metadata['sizes'] as $key => $size_data ) {
		if ( ! is_array( $size_data ) ) {
			continue;
		}

		$size_file = $size_data['file'] ?? ( $size_data['filename'] ?? '' );

		if ( $key === $size_name || ( '' !== $size_file && wp_basename( $size_file ) === $filename ) ) {
			unset( $metadata['sizes'][ $key ] );
			$changed = true;
		}
	}

	if ( ! $changed ) {
		return false;
	}

	return false !== wp_update_attachment_metadata( $attachment_id, $metadata );
}
