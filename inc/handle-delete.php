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

	$items = yotm_job_claim_items( $worker, $batch, ! empty( $job['payload']['recovery_only'] ) );
	if ( is_wp_error( $items ) ) {
		wp_send_json_error( array( 'msg' => $items->get_error_message() ), 409 );
	}

	$deleted_now = 0;
	$failed_now  = 0;
	$retry       = false;
	$base        = (string) ( $job['payload']['base'] ?? ( wp_get_upload_dir()['basedir'] ?? '' ) );
	$item_v3     = 'item_v3' === ( $job['counter_mode'] ?? '' );

	foreach ( $items as $item ) {
		if ( ! empty( $job['payload']['recovery_only'] ) && empty( $item['payload']['prune_operation_journal_v1'] ) ) {
			yotm_job_release_item_claim( $item );
			continue;
		}
		$result = yotm_process_claimed_prune_item( $item, $job, $worker, $base );
		if ( is_wp_error( $result ) ) {
			yotm_job_release_item_claim( $item );
			$retry = true;
			break;
		}

		if ( ! empty( $result['deleted'] ) ) {
			$finished = $item_v3
				? yotm_job_finish_item_v3( $item, $worker, 'done', '', (int) $result['bytes'] )
				: yotm_job_finish_item( $item, 'done', '', (int) $result['bytes'] );
			if ( $finished ) {
				++$deleted_now;
			}
		} elseif ( ! empty( $result['skipped'] ) ) {
			$item_v3
				? yotm_job_finish_item_v3( $item, $worker, 'skipped', (string) ( $result['message'] ?? '' ) )
				: yotm_job_finish_item( $item, 'skipped', (string) ( $result['message'] ?? '' ) );
		} else {
			$finished = $item_v3
				? yotm_job_finish_item_v3( $item, $worker, 'failed', (string) ( $result['error'] ?? __( 'Could not delete the file.', 'thumbnail-manager' ) ) )
				: yotm_job_finish_item( $item, 'failed', (string) ( $result['error'] ?? __( 'Could not delete the file.', 'thumbnail-manager' ) ) );
			if ( $finished ) {
				++$failed_now;
			}
		}
	}//end foreach

	$current = $item_v3 ? yotm_job_get_by_id( $job['id'] ) : yotm_job_sync_item_counters( $job['id'] );
	if ( $item_v3 && ! empty( $current['payload']['recovery_only'] ) && ! yotm_job_has_recovery_journals( $job['id'] ) ) {
		$terminal = yotm_job_recovery_terminal_status( $current );
		yotm_job_worker_update(
			$worker,
			array(
				'status'     => $terminal,
				'phase'      => $terminal,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_AUDIT_RETENTION_SECONDS ),
			)
		);
		wp_send_json_success( yotm_build_prune_delete_response( yotm_job_get_by_id( $job['id'] ), $deleted_now, $failed_now, false ) );
	}
	$done = $item_v3 ? ! yotm_job_has_remaining_items( $job['id'] ) : 0 === yotm_job_item_counters( $job['id'] )['remaining'];

	if ( $done && is_array( $current ) && 'deleting' === $current['status'] ) {
		if ( $item_v3 ) {
			$audit = yotm_job_item_counters( $job['id'] );
			if (
				(int) $current['processed'] !== $audit['processed']
				|| (int) $current['succeeded'] !== $audit['succeeded']
				|| (int) $current['failed'] !== $audit['failed']
				|| (int) $current['bytes'] !== $audit['bytes']
			) {
				wp_send_json_error( array( 'msg' => __( 'Prune counters did not match the terminal item audit.', 'thumbnail-manager' ) ), 503 );
			}
		}
		yotm_job_worker_update(
			$worker,
			array(
				'status'     => 'completed',
				'phase'      => 'completed',
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_AUDIT_RETENTION_SECONDS ),
			)
		);
	}//end if

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
	$processed = (int) $job['processed'];
	$deleted   = (int) $job['succeeded'];
	$failed    = (int) $job['failed'];
	$bytes     = (int) $job['bytes'];
	$remaining = 'item_v3' === ( $job['counter_mode'] ?? '' )
		? max( 0, (int) $job['total'] - $processed )
		: yotm_job_item_counters( $job['id'] )['remaining'];
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
	$clean = yotm_media_reference_require_complete_index();
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

	if (
		'generated_file_v1' !== ( $job['payload']['ownership_schema'] ?? '' )
		|| empty( $job['payload']['source_index_complete'] )
		|| YOTM_MEDIA_REFERENCE_GENERATION !== absint( $job['payload']['reference_generation'] ?? 0 )
	) {
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
	$clean = yotm_media_reference_require_complete_index();
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}

	if ( empty( $job['manifest_hash'] ) || empty( $manifest_hash ) || ! hash_equals( $job['manifest_hash'], $manifest_hash ) ) {
		return new WP_Error( 'yotm_manifest_changed', __( 'The delete manifest does not match the reviewed scan.', 'thumbnail-manager' ) );
	}

	if ( strtotime( $job['expires_at'] . ' UTC' ) < time() ) {
		return new WP_Error( 'yotm_delete_grant_expired', __( 'The delete approval has expired. Run and review a new scan.', 'thumbnail-manager' ) );
	}

	if (
		'generated_file_v1' !== ( $job['payload']['ownership_schema'] ?? '' )
		|| empty( $job['payload']['source_index_complete'] )
		|| YOTM_MEDIA_REFERENCE_GENERATION !== absint( $job['payload']['reference_generation'] ?? 0 )
	) {
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
			$journaled      = 'item_v3' === ( $job['counter_mode'] ?? '' ) && ! empty( $item['payload']['prune_operation_journal_v1'] );
			$journal_absent = false;
			if ( $journaled ) {
				$journal_node = yotm_prune_journal_path_state( $path );
				if ( is_wp_error( $journal_node ) ) {
					return $journal_node;
				}
				if ( 'changed' === $journal_node['state'] ) {
					return array(
						'deleted' => false,
						'skipped' => false,
						'bytes'   => 0,
						'error'   => __( 'The armed prune path contains a changed filesystem node and requires manual inspection.', 'thumbnail-manager' ),
					);
				}
				$journal_absent = 'absent' === $journal_node['state'];
			}
			if ( ! $journal_absent ) {
				$selector = yotm_prune_validate_selector_bindings( $item['payload'] ?? array(), $job['payload'] ?? array() );
				if ( is_wp_error( $selector ) ) {
					return array(
						'deleted' => false,
						'skipped' => false,
						'bytes'   => 0,
						'error'   => $selector->get_error_message(),
					);
				}
			}

			$references = $journal_absent ? true : yotm_prune_validate_live_reference_evidence( $item['payload'] ?? array(), $path );
			if ( is_wp_error( $references ) ) {
				if ( 'yotm_prune_path_protected' === $references->get_error_code() ) {
					return array(
						'deleted' => false,
						'skipped' => true,
						'bytes'   => 0,
						'message' => $references->get_error_message(),
					);
				}
				return array(
					'deleted' => false,
					'skipped' => false,
					'bytes'   => 0,
					'error'   => $references->get_error_message(),
				);
			}

			$payload         = $item['payload'];
			$payload['path'] = $path;
			return 'item_v3' === ( $job['counter_mode'] ?? '' )
				? yotm_delete_prune_item_recoverable( $item, $payload, $uploads_base, ! empty( $job['payload']['recovery_only'] ) )
				: yotm_delete_prune_item( $payload, $uploads_base );
		} finally {
			yotm_media_path_lock_release( $path_lock );
		}//end try
	} finally {
		yotm_media_source_fence_release( $source_fence );
	}//end try
}

/**
 * Reauthorize every exact folder-selector binding before a new prune side effect.
 *
 * @param array $item Immutable prune item payload.
 * @param array $job_payload Prune job payload.
 * @return true|WP_Error
 */
function yotm_prune_validate_selector_bindings( $item, $job_payload ) {
	if ( 'attached_meta_v2' !== ( $job_payload['selector'] ?? '' ) ) {
		return true;
	}

	$bindings = array();
	foreach ( (array) ( $item['ownership_evidence'] ?? array() ) as $evidence ) {
		$attachment_id = absint( $evidence['attachment_id'] ?? 0 );
		$meta_id       = absint( $evidence['selection_meta_id'] ?? 0 );
		if ( ! $attachment_id || ! $meta_id || ( isset( $bindings[ $attachment_id ] ) && $bindings[ $attachment_id ] !== $meta_id ) ) {
			return new WP_Error( 'yotm_prune_selector_binding_invalid', __( 'The prune item is not bound to its exact folder-selection row.', 'thumbnail-manager' ) );
		}
		$bindings[ $attachment_id ] = $meta_id;
	}
	if ( empty( $bindings ) ) {
		return new WP_Error( 'yotm_prune_selector_binding_invalid', __( 'The prune item is not bound to its exact folder-selection row.', 'thumbnail-manager' ) );
	}

	foreach ( $bindings as $attachment_id => $meta_id ) {
		$authorized = yotm_authorize_attached_file_selector_scope(
			$attachment_id,
			$meta_id,
			absint( $job_payload['selection_meta_max'] ?? 0 ),
			(array) ( $job_payload['selection_subpaths'] ?? array() )
		);
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}
	}

	return true;
}

/**
 * Delete or recover one exact prune item using its persisted operation journal.
 *
 * @param array         $item Claimed item.
 * @param array         $payload Validated immutable item payload.
 * @param string        $uploads_base Uploads base path.
 * @param bool          $recovery_only Whether only an already-achieved side effect may be reconciled.
 * @param callable|null $journal_barrier Optional fault-injection barrier after arming and before unlink.
 * @return array|WP_Error
 */
function yotm_delete_prune_item_recoverable( &$item, $payload, $uploads_base, $recovery_only = false, $journal_barrier = null ) {
	$path    = yotm_prune_journal_lexical_path( $payload['path'] ?? '' );
	$journal = is_array( $item['payload']['prune_operation_journal_v1'] ?? null ) ? $item['payload']['prune_operation_journal_v1'] : array();

	if ( ! empty( $journal ) ) {
		$journal_path = yotm_prune_journal_lexical_path( $journal['path'] ?? '' );
		$fingerprint  = (string) ( $journal['node_fingerprint'] ?? '' );
		if (
			1 !== absint( $journal['version'] ?? 0 )
			|| '' === $path
			|| ! hash_equals( $path, $journal_path )
			|| ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $journal['file_hash'] ?? '' ) )
			|| ( '' !== $fingerprint && ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) )
			|| ! isset( $journal['bytes'] )
			|| 0 > (int) $journal['bytes']
		) {
			return new WP_Error( 'yotm_prune_journal_invalid', __( 'The prune recovery journal is malformed and requires manual inspection.', 'thumbnail-manager' ) );
		}
		if ( 'delete_reconciled' === ( $journal['outcome'] ?? '' ) ) {
			return array(
				'deleted' => true,
				'skipped' => false,
				'bytes'   => absint( $journal['bytes'] ?? 0 ),
				'error'   => '',
			);
		}

		$node = yotm_prune_journal_path_state( $path );
		if ( is_wp_error( $node ) ) {
			return $node;
		}
		if ( 'absent' === $node['state'] ) {
			$reconciled = yotm_reconcile_prune_item_metadata( $payload, $path );
			if ( is_wp_error( $reconciled ) ) {
				return $reconciled;
			}
			$journal['outcome']                         = 'delete_reconciled';
			$item_payload                               = $item['payload'];
			$item_payload['prune_operation_journal_v1'] = $journal;
			if ( ! yotm_job_update_claimed_item_payload( $item, $item_payload ) ) {
				return new WP_Error( 'yotm_prune_journal_persist_failed', __( 'Could not persist the recovered prune outcome.', 'thumbnail-manager' ) );
			}
			$item['payload'] = $item_payload;
			return array(
				'deleted' => true,
				'skipped' => false,
				'bytes'   => absint( $journal['bytes'] ?? 0 ),
				'error'   => '',
			);
		}
		if ( 'regular' !== $node['state'] ) {
			return array(
				'deleted' => false,
				'skipped' => false,
				'bytes'   => 0,
				'error'   => __( 'The reviewed prune path now contains a different filesystem node and requires manual inspection.', 'thumbnail-manager' ),
			);
		}

		$current_hash = hash_file( 'sha256', $path );
		if (
			! is_string( $current_hash )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint )
			|| empty( $node['fingerprint'] )
			|| ! hash_equals( $fingerprint, (string) $node['fingerprint'] )
			|| ! hash_equals( (string) ( $journal['file_hash'] ?? '' ), $current_hash )
			|| (int) $journal['bytes'] !== (int) $node['bytes']
		) {
			return array(
				'deleted' => false,
				'skipped' => false,
				'bytes'   => 0,
				'error'   => __( 'The reviewed prune file changed after the delete journal was armed.', 'thumbnail-manager' ),
			);
		}
		if ( $recovery_only ) {
			return array(
				'deleted' => false,
				'skipped' => false,
				'bytes'   => 0,
				'error'   => __( 'Recovery-only processing preserved the intact reviewed prune file.', 'thumbnail-manager' ),
			);
		}
	} else {
		$node = yotm_prune_journal_path_state( $path );
		if ( is_wp_error( $node ) ) {
			return $node;
		}
		if ( 'absent' === $node['state'] ) {
			return yotm_delete_prune_item( $payload, $uploads_base );
		}
		if ( 'regular' !== $node['state'] ) {
			return array(
				'deleted' => false,
				'skipped' => false,
				'bytes'   => 0,
				'error'   => __( 'The reviewed prune path is not a regular file and could not be journaled.', 'thumbnail-manager' ),
			);
		}
		$file_hash = hash_file( 'sha256', $path );
		$bytes     = $node['bytes'];
		if ( ! is_string( $file_hash ) ) {
			return array(
				'deleted' => false,
				'skipped' => false,
				'bytes'   => 0,
				'error'   => __( 'The reviewed prune file could not be journaled safely.', 'thumbnail-manager' ),
			);
		}
		$journal                                    = array(
			'version'          => 1,
			'path'             => $path,
			'file_hash'        => $file_hash,
			'node_fingerprint' => $node['fingerprint'],
			'bytes'            => (int) $bytes,
			'outcome'          => '',
		);
		$item_payload                               = $item['payload'];
		$item_payload['prune_operation_journal_v1'] = $journal;
		if ( ! yotm_job_update_claimed_item_payload( $item, $item_payload ) ) {
			return new WP_Error( 'yotm_prune_journal_persist_failed', __( 'Could not persist the prune operation journal.', 'thumbnail-manager' ) );
		}
		$item['payload'] = $item_payload;
		if ( is_callable( $journal_barrier ) ) {
			call_user_func( $journal_barrier, $item, $journal );
		}
	}//end if

	$result = yotm_delete_prune_item( $payload, $uploads_base );
	if ( empty( $result['deleted'] ) ) {
		return $result;
	}

	$journal['outcome']                         = 'delete_reconciled';
	$item_payload                               = $item['payload'];
	$item_payload['prune_operation_journal_v1'] = $journal;
	if ( ! yotm_job_update_claimed_item_payload( $item, $item_payload ) ) {
		return new WP_Error( 'yotm_prune_journal_persist_failed', __( 'Could not persist the completed prune outcome.', 'thumbnail-manager' ) );
	}
	$item['payload'] = $item_payload;

	return $result;
}

/**
 * Normalize an immutable prune path without resolving its final filesystem node.
 *
 * Journal identity and lstat inspection must address the reviewed path itself;
 * resolving a replacement symlink here would inspect its target instead.
 *
 * @param string $path Reviewed candidate path.
 * @return string
 */
function yotm_prune_journal_lexical_path( $path ) {
	return untrailingslashit( wp_normalize_path( (string) $path ) );
}

/**
 * Fingerprint one regular filesystem node from a single lstat snapshot.
 *
 * Device and inode bind the node across requests. Mode, link count, owner,
 * device type, and ctime make inode reuse or intervening stat changes fail
 * closed. Platforms that cannot expose a usable inode cannot authorize prune.
 *
 * @param array $stat lstat result.
 * @return string|WP_Error
 */
function yotm_prune_journal_node_fingerprint( $stat ) {
	$fields   = array( 'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'ctime' );
	$identity = array();

	foreach ( $fields as $field ) {
		if ( ! array_key_exists( $field, $stat ) || ! is_int( $stat[ $field ] ) ) {
			return new WP_Error( 'yotm_prune_node_identity_unavailable', __( 'The reviewed prune file identity could not be established safely.', 'thumbnail-manager' ) );
		}
		$identity[] = $field . '=' . (string) $stat[ $field ];
	}
	if ( 0 >= $stat['ino'] ) {
		return new WP_Error( 'yotm_prune_node_identity_unavailable', __( 'The reviewed prune file identity could not be established safely.', 'thumbnail-manager' ) );
	}

	return hash( 'sha256', implode( '|', $identity ) );
}

/**
 * Inspect the exact filesystem node at an armed prune path without following symlinks.
 *
 * @param string $path Reviewed candidate path.
 * @return array{state:string,bytes:int,fingerprint?:string}|WP_Error
 */
function yotm_prune_journal_path_state( $path ) {
	$path = yotm_prune_journal_lexical_path( $path );
	clearstatcache( true, $path );
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A false lstat result is classified explicitly below and must not emit during recovery.
	$stat = @lstat( $path );

	if ( false === $stat ) {
		$parent = dirname( $path );
		if ( ! is_dir( $parent ) || ! is_readable( $parent ) || ! is_executable( $parent ) ) {
			return new WP_Error( 'yotm_prune_path_inspection_failed', __( 'The reviewed prune path could not be inspected safely.', 'thumbnail-manager' ) );
		}
		return array(
			'state' => 'absent',
			'bytes' => 0,
		);
	}

	$mode = absint( $stat['mode'] ?? 0 ) & 0170000;
	if ( 0100000 !== $mode || is_link( $path ) || ! is_file( $path ) ) {
		return array(
			'state' => 'changed',
			'bytes' => 0,
		);
	}
	$fingerprint = yotm_prune_journal_node_fingerprint( $stat );
	if ( is_wp_error( $fingerprint ) ) {
		return $fingerprint;
	}

	return array(
		'state'       => 'regular',
		'bytes'       => absint( $stat['size'] ?? 0 ),
		'fingerprint' => $fingerprint,
	);
}

/**
 * Prove that immutable candidate evidence covers every current generated owner.
 *
 * Source/protected owners always veto. Extra historical candidate evidence is
 * allowed so a repointed size does not prevent deleting its reviewed old file.
 *
 * @param array  $item Immutable candidate payload.
 * @param string $path Canonical candidate path.
 * @return true|WP_Error
 */
function yotm_prune_validate_live_reference_evidence( $item, $path ) {
	$owners = yotm_media_reference_path_owners( $path );
	if ( is_wp_error( $owners ) ) {
		return $owners;
	}
	if ( ! empty( $owners['protected'] ) ) {
		return new WP_Error( 'yotm_prune_path_protected', __( 'The file is now a protected attachment source or companion and was preserved.', 'thumbnail-manager' ) );
	}

	$represented = array();
	foreach ( (array) ( $item['metadata_refs'] ?? array() ) as $ref ) {
		if ( ! is_array( $ref ) ) {
			continue;
		}
		$key                 = absint( $ref['attachment_id'] ?? 0 ) . ':' . sanitize_key( $ref['size'] ?? '' ) . ':' . wp_basename( (string) ( $ref['filename'] ?? '' ) );
		$represented[ $key ] = true;
	}

	foreach ( $owners['generated'] as $owner ) {
		$key = absint( $owner['attachment_id'] ?? 0 ) . ':' . sanitize_key( $owner['size'] ?? '' ) . ':' . wp_basename( (string) ( $owner['filename'] ?? '' ) );
		if ( empty( $represented[ $key ] ) ) {
			return new WP_Error( 'yotm_prune_generated_owner_changed', __( 'A generated-file reference outside the reviewed manifest now owns this path.', 'thumbnail-manager' ) );
		}
	}

	return true;
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
 * @return bool|WP_Error
 */
function yotm_remove_attachment_size_metadata( $attachment_id, $size_name, $filename ) {
	$rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_metadata' );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	if ( 1 !== count( $rows ) || ! is_array( $rows[0]['value'] ) ) {
		return new WP_Error( 'yotm_prune_metadata_state_ambiguous', __( 'The file was removed, but raw attachment metadata could not be reconciled safely.', 'thumbnail-manager' ) );
	}
	$metadata = $rows[0]['value'];

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
