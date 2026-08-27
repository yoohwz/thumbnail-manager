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
	$approved               = yotm_job_transition(
		$job['id'],
		array( 'awaiting_approval' ),
		array( 'review' ),
		array(
			'payload'    => $payload,
			'status'     => 'approved',
			'phase'      => 'delete',
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS ),
		),
		array(
			'manifest_hash'   => $manifest_hash,
			'active_deadline' => true,
		)
	);
	if ( ! $approved ) {
		wp_send_json_error( array( 'msg' => __( 'The prune job changed before approval. Refresh and review its current state.', 'thumbnail-manager' ) ), 409 );
	}

	wp_send_json_success(
		array(
			'token'         => $approved['token'],
			'manifest_hash' => $approved['manifest_hash'],
			'total'         => $approved['total'],
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

	$worker = yotm_job_acquire_worker( $job['id'], array( 'approved', 'deleting' ), array( 'delete' ) );
	if ( is_wp_error( $worker ) ) {
		if ( 'yotm_job_worker_busy' !== $worker->get_error_code() ) {
			wp_send_json_error( array( 'msg' => $worker->get_error_message() ), 503 );
		}

		$data     = $worker->get_error_data();
		$current  = is_array( $data ) && is_array( $data['job'] ?? null ) ? $data['job'] : $job;
		$response = yotm_build_prune_delete_response( $current, 0, 0, true );
		wp_send_json_success( $response );
	}

	$job = yotm_job_get_by_id( $job['id'] );
	if ( 'approved' === $job['status'] ) {
		yotm_job_worker_update(
			$worker,
			array(
				'status' => 'deleting',
				'phase'  => 'delete',
			)
		);
		$job = yotm_job_get_by_id( $job['id'] );
	}

	$items = yotm_job_claim_items( $worker, $batch );
	if ( is_wp_error( $items ) ) {
		wp_send_json_error( array( 'msg' => $items->get_error_message() ), 409 );
	}

	$deleted_now = 0;
	$failed_now  = 0;
	$retry       = false;
	$base        = (string) ( $job['payload']['base'] ?? ( wp_get_upload_dir()['basedir'] ?? '' ) );

	foreach ( $items as $item ) {
		$result = yotm_process_claimed_prune_item( $item, $job, $worker, $base );
		if ( is_wp_error( $result ) ) {
			yotm_job_release_item_claim( $item );
			$retry = true;
			break;
		}

		if ( ! empty( $result['deleted'] ) ) {
			if ( yotm_job_finish_item( $item, 'done', '', (int) $result['bytes'] ) ) {
				++$deleted_now;
			}
		} elseif ( ! empty( $result['skipped'] ) ) {
			yotm_job_finish_item( $item, 'skipped', (string) ( $result['message'] ?? '' ) );
		} elseif ( yotm_job_finish_item( $item, 'failed', (string) ( $result['error'] ?? __( 'Could not delete the file.', 'thumbnail-manager' ) ) ) ) {
			++$failed_now;
		}
	}

	$current  = yotm_job_sync_item_counters( $job['id'] );
	$counters = yotm_job_item_counters( $job['id'] );
	$done     = 0 === $counters['remaining'];

	if ( $done && is_array( $current ) && 'deleting' === $current['status'] ) {
		yotm_job_worker_update(
			$worker,
			array(
				'status'     => 'completed',
				'phase'      => 'completed',
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_AUDIT_RETENTION_SECONDS ),
			)
		);
	}

	wp_send_json_success( yotm_build_prune_delete_response( yotm_job_get_by_id( $job['id'] ), $deleted_now, $failed_now, $retry ) );
}

/**
 * Build a prune deletion response from authoritative persisted counters.
 *
 * @param array $job Job row.
 * @param int   $deleted_now Files deleted by this request.
 * @param int   $failed_now Files failed by this request.
 * @param bool  $retry Whether another request currently owns the worker.
 * @return array
 */
function yotm_build_prune_delete_response( $job, $deleted_now = 0, $failed_now = 0, $retry = false ) {
	$counters  = yotm_job_item_counters( $job['id'] );
	$processed = (int) $job['processed'];
	$deleted   = (int) $job['succeeded'];
	$failed    = (int) $job['failed'];
	$bytes     = (int) $job['bytes'];
	$remaining = $counters['remaining'];
	$stopped   = in_array( $job['status'], array( 'cancelled', 'expired' ), true );
	$done      = 'completed' === $job['status'];
	$total     = max( 1, (int) $job['total'] );

	return array(
		'processed'      => $processed,
		'deleted'        => $deleted,
		'deleted_count'  => absint( $deleted_now ),
		'failed'         => $failed,
		'failed_count'   => absint( $failed_now ),
		'skipped'        => max( 0, $processed - $deleted - $failed ),
		'bytes'          => $bytes,
		'bytes_human'    => size_format( $bytes ),
		'remaining'      => $remaining,
		'percent'        => $done ? 100 : min( 99.9, ( $processed / $total ) * 100 ),
		'done'           => $done,
		'stopped'        => $stopped,
		'status'         => $job['status'],
		'retry_after_ms' => $retry ? 250 : 0,
		'errors'         => yotm_job_get_error_sample( $job['id'] ),
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
	$clean = yotm_media_source_require_clean_index();
	if ( is_wp_error( $clean ) ) {
		return $clean;
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

	if ( 'generated_file_v1' !== ( $job['payload']['ownership_schema'] ?? '' ) || empty( $job['payload']['source_index_complete'] ) ) {
		return new WP_Error( 'yotm_prune_ownership_upgrade_required', __( 'This prune manifest predates the current media-safety rules. Run and review a new scan.', 'thumbnail-manager' ) );
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
	$clean = yotm_media_source_require_clean_index();
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}

	if ( empty( $job['manifest_hash'] ) || empty( $manifest_hash ) || ! hash_equals( $job['manifest_hash'], $manifest_hash ) ) {
		return new WP_Error( 'yotm_manifest_changed', __( 'The delete manifest does not match the reviewed scan.', 'thumbnail-manager' ) );
	}

	if ( strtotime( $job['expires_at'] . ' UTC' ) < time() ) {
		return new WP_Error( 'yotm_delete_grant_expired', __( 'The delete approval has expired. Run and review a new scan.', 'thumbnail-manager' ) );
	}

	if ( 'generated_file_v1' !== ( $job['payload']['ownership_schema'] ?? '' ) || empty( $job['payload']['source_index_complete'] ) ) {
		return new WP_Error( 'yotm_prune_ownership_upgrade_required', __( 'This prune manifest predates the current media-safety rules. Run and review a new scan.', 'thumbnail-manager' ) );
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
 * Execute one claimed prune deletion behind worker and item ownership fences.
 *
 * The optional barrier is used by the cross-process smoke test to pause at the
 * final pre-side-effect boundary while the production unit remains unchanged.
 * Ownership is refreshed again after the barrier before deleting anything.
 *
 * @param array         $item Claimed item.
 * @param array         $job Current job.
 * @param array         $worker Worker ownership data.
 * @param string        $uploads_base Uploads base path.
 * @param callable|null $barrier Optional pre-delete barrier.
 * @return array|WP_Error
 */
function yotm_process_claimed_prune_item( $item, $job, $worker, $uploads_base, $barrier = null ) {
	if ( ! yotm_job_refresh_worker( $worker ) || ! yotm_job_refresh_item_claim( $item ) ) {
		return new WP_Error( 'yotm_job_worker_stale', __( 'This job worker no longer owns the current batch.', 'thumbnail-manager' ) );
	}

	$path = yotm_validate_prune_item_ownership( $item['payload'] ?? array(), $uploads_base );
	if ( is_wp_error( $path ) ) {
		return array(
			'deleted' => false,
			'skipped' => false,
			'bytes'   => 0,
			'error'   => $path->get_error_message(),
		);
	}

	$source_fence = yotm_media_source_fence_acquire();
	if ( is_wp_error( $source_fence ) ) {
		return $source_fence;
	}

	try {
		$path_lock = yotm_media_path_lock_acquire( $path );
		if ( is_wp_error( $path_lock ) ) {
			return $path_lock;
		}

		try {
			if ( is_callable( $barrier ) ) {
				call_user_func( $barrier, $item, $job, $worker );
			}

			if ( ! yotm_job_refresh_worker( $worker ) || ! yotm_job_refresh_item_claim( $item ) ) {
				return new WP_Error( 'yotm_job_worker_stale', __( 'This job worker no longer owns the current batch.', 'thumbnail-manager' ) );
			}

			$protected = yotm_media_source_path_is_authoritative( $path );
			if ( is_wp_error( $protected ) ) {
				return array(
					'deleted' => false,
					'skipped' => false,
					'bytes'   => 0,
					'error'   => $protected->get_error_message(),
				);
			}
			if ( $protected ) {
				return array(
					'deleted' => false,
					'skipped' => true,
					'bytes'   => 0,
					'message' => __( 'The file is now an authoritative attachment source and was preserved.', 'thumbnail-manager' ),
				);
			}

			$payload         = $item['payload'];
			$payload['path'] = $path;
			return yotm_delete_prune_item( $payload, $uploads_base );
		} finally {
			yotm_media_path_lock_release( $path_lock );
		}//end try
	} finally {
		yotm_media_source_fence_release( $source_fence );
	}//end try
}

/**
 * Validate immutable exact generated-file ownership before a side effect.
 *
 * @param array  $item Item payload.
 * @param string $uploads_base Uploads base path.
 * @return string|WP_Error Canonical candidate path.
 */
function yotm_validate_prune_item_ownership( $item, $uploads_base ) {
	if ( ! is_array( $item ) || 'generated_file_v1' !== ( $item['ownership_schema'] ?? '' ) || 'metadata_size' !== ( $item['ownership'] ?? '' ) ) {
		return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item lacks exact generated-file ownership evidence.', 'thumbnail-manager' ) );
	}

	$path = yotm_media_source_canonical_path( $item['path'] ?? '' );
	if ( is_wp_error( $path ) || ! yotm_is_path_inside_dir( is_wp_error( $path ) ? '' : $path, $uploads_base ) ) {
		return is_wp_error( $path ) ? $path : new WP_Error( 'yotm_prune_path_invalid', __( 'File path is outside uploads.', 'thumbnail-manager' ) );
	}

	$filename = wp_basename( $path );
	$evidence = is_array( $item['ownership_evidence'] ?? null ) ? $item['ownership_evidence'] : array();
	$refs     = is_array( $item['metadata_refs'] ?? null ) ? $item['metadata_refs'] : array();
	if ( empty( $evidence ) || empty( $refs ) ) {
		return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item lacks exact generated-file ownership evidence.', 'thumbnail-manager' ) );
	}

	$proof_keys = array();
	foreach ( $evidence as $proof ) {
		if (
			empty( $proof['attachment_id'] )
			|| '' === sanitize_key( $proof['size'] ?? '' )
			|| ! hash_equals( $filename, wp_basename( (string) ( $proof['filename'] ?? '' ) ) )
			|| ! in_array( $proof['selection'] ?? '', array( 'registered_remove', 'metadata_orphan' ), true )
		) {
			return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item contains malformed ownership evidence.', 'thumbnail-manager' ) );
		}
		$proof_keys[ absint( $proof['attachment_id'] ) . ':' . sanitize_key( $proof['size'] ) . ':' . $filename ] = true;
	}

	$ref_keys = array();
	foreach ( $refs as $ref ) {
		if ( empty( $ref['attachment_id'] ) || '' === sanitize_key( $ref['size'] ?? '' ) || ! hash_equals( $filename, wp_basename( (string) ( $ref['filename'] ?? '' ) ) ) ) {
			return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item contains a mismatched metadata reference.', 'thumbnail-manager' ) );
		}
		$ref_keys[ absint( $ref['attachment_id'] ) . ':' . sanitize_key( $ref['size'] ) . ':' . $filename ] = true;
	}

	ksort( $proof_keys );
	ksort( $ref_keys );
	if ( array_keys( $proof_keys ) !== array_keys( $ref_keys ) ) {
		return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item ownership evidence does not match its metadata references.', 'thumbnail-manager' ) );
	}

	return $path;
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
		$reconciled = yotm_reconcile_prune_item_metadata( $item, $path );
		if ( is_wp_error( $reconciled ) ) {
			return $reconciled;
		}

		return array(
			'deleted' => false,
			'skipped' => true,
			'bytes'   => 0,
			'message' => __( 'File was already missing; metadata was reconciled.', 'thumbnail-manager' ),
		);
	}

	$result = yotm_delete_file_with_result( $path );

	if ( ! empty( $result['deleted'] ) ) {
		$reconciled = yotm_reconcile_prune_item_metadata( $item, $path );
		if ( is_wp_error( $reconciled ) ) {
			return $reconciled;
		}
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
		return true;
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
		$removed      = yotm_remove_attachment_size_metadata( $attachment_id, $size, $filename );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}
	}

	return true;
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

	if ( ! isset( $metadata['sizes'][ $size_name ] ) || ! is_array( $metadata['sizes'][ $size_name ] ) ) {
		return true;
	}

	$size_file = $metadata['sizes'][ $size_name ]['file'] ?? ( $metadata['sizes'][ $size_name ]['filename'] ?? '' );
	if ( '' === $size_file || ! hash_equals( wp_basename( (string) $size_file ), wp_basename( (string) $filename ) ) ) {
		return true;
	}

	unset( $metadata['sizes'][ $size_name ] );
	unset( $GLOBALS['yotm_media_source_last_error'] );
	$updated = wp_update_attachment_metadata( $attachment_id, $metadata );
	if ( false === $updated ) {
		if ( isset( $GLOBALS['yotm_media_source_last_error'] ) && is_wp_error( $GLOBALS['yotm_media_source_last_error'] ) ) {
			return $GLOBALS['yotm_media_source_last_error'];
		}
		return new WP_Error( 'yotm_prune_metadata_update_failed', __( 'The file was removed, but its exact metadata reference could not be reconciled.', 'thumbnail-manager' ) );
	}

	return true;
}
