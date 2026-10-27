<?php
/**
 * Public saved results and selected retries using existing job authority.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explain when an item can be selected for a fresh attempt.
 *
 * @param array $item Persisted item.
 * @return bool
 */
function yotm_job_result_retryable_item( $item ) {
	if ( ! in_array( $item['status'], array( 'failed', 'skipped' ), true ) ) {
		return false;
	}
	$payload = $item['payload'];
	$journal = $payload['regeneration_journal'] ?? array();
	if ( $journal && 'cleanup_complete' !== ( $journal['phase'] ?? '' ) ) {
		return false;
	}
	$prune = $payload['prune_operation_journal_v1'] ?? array();
	return ! $prune || 'delete_reconciled' === ( $prune['outcome'] ?? '' );
}

/**
 * Read one public result page. This never expires or executes a job.
 *
 * @param string $token Owned token.
 * @param int    $page Page.
 * @param string $filter Outcome filter.
 * @return array
 */
function yotm_job_results_application( $token, $page = 1, $filter = '' ) {
	$job = yotm_job_get_audit( $token );
	if ( is_wp_error( $job ) ) {
		return yotm_regenerate_application_error( array( 'msg' => $job->get_error_message() ), 404 );
	}
	$statuses = array( 'queued', 'processing', 'done', 'deleted', 'failed', 'skipped' );
	if ( 'unsuccessful' === $filter ) {
		$statuses = array( 'failed', 'skipped' );
	} elseif ( in_array( $filter, $statuses, true ) ) {
		$statuses = array( $filter );
	}
	$data = yotm_job_get_item_rows_page( $job['id'], $page, 100, '', $statuses );
	if ( is_wp_error( $data ) ) {
		return yotm_regenerate_application_error( array( 'msg' => $data->get_error_message() ), 503 );
	}
	$public = yotm_job_public_data( $job );
	unset( $public['context']['sample'], $public['context']['report_token'], $public['context']['orphan_summary']['unmapped_sample'], $public['context']['orphan_summary']['skipped_original_sample'] );
	$public['context']['selected_sizes'] = $job['payload']['selected_sizes'] ?? null;
	$public['context']['retry_of']       = $job['payload']['retry_of'] ?? '';
	$terminal                            = in_array( $job['status'], array( 'completed', 'cancelled', 'expired' ), true );
	$expired                             = 'expired' === $job['status'] || $job['expires_at'] < gmdate( 'Y-m-d H:i:s' );
	$can_retry                           = $terminal && ! $expired && in_array( $job['type'], array( 'regenerate', 'prune' ), true ) && empty( $job['payload']['recovery_only'] ) && ! yotm_job_has_terminal_recovery_evidence( $job['id'] );
	$items                               = array();
	foreach ( $data['items'] as $row ) {
		$payload = $row['payload'];
		$id      = absint( $payload['attachment_id'] ?? 0 );
		$health  = array_intersect_key(
			(array) ( $payload['health_report'] ?? array() ),
			array_flip( array( 'status', 'message', 'reason', 'action', 'sizes', 'missing_count', 'checked_count', 'restored_count', 'restored_sizes', 'inspected_at' ) )
		);
		$path    = (string) ( $payload['path'] ?? '' );
		$base    = trailingslashit( wp_normalize_path( wp_get_upload_dir()['basedir'] ) );
		$path    = wp_normalize_path( $path );
		$path    = 0 === strpos( $path, $base ) ? 'uploads/' . substr( $path, strlen( $base ) ) : wp_basename( $path );
		$items[] = array(
			'key'                   => $row['item_key'],
			'attachment_id'         => $id,
			'title'                 => $id ? get_the_title( $id ) : $path,
			'edit_url'              => $id && current_user_can( 'edit_post', $id ) ? get_edit_post_link( $id, 'raw' ) : '',
			'path'                  => $path,
			'size'                  => (string) ( $payload['size'] ?? '' ),
			'status'                => $row['status'],
			'error'                 => $row['error'],
			'action'                => $health['action'] ?? ( in_array( $row['status'], array( 'failed', 'skipped' ), true ) ? __( 'Review the reason, resolve the source, permission or storage issue, then retry selected items. A new attempt checks current conditions.', 'thumbnail-manager' ) : '' ),
			'health'                => $health,
			'scope_preserved_sizes' => array_values( (array) ( $payload['scope_preserved_sizes'] ?? array() ) ),
			'bytes'                 => $row['bytes'],
			'retryable'             => $can_retry && yotm_job_result_retryable_item( $row ),
		);
	}//end foreach
	$data['items']          = $items;
	$public['skipped']      = 'recommendation' === $job['type'] ? 0 : yotm_job_count_items_by_status( $job['id'], array( 'skipped' ) );
	$data['job']            = $public;
	$data['schema_version'] = 1;
	$data['filter']         = $filter;
	$data['unit']           = 'prune' === $job['type'] ? 'files' : ( 'regenerate' === $job['type'] ? 'images' : 'scan' );
	$data['read_at']        = gmdate( 'Y-m-d H:i:s' );
	$data['complete']       = 'completed' === $job['status'] && ! $expired;
	$data['can_retry']      = $can_retry;
	$data['limitations']    = array(
		__( 'Saved observations describe this run, not the current state of media. Opening this result starts no processing. Times are UTC.', 'thumbnail-manager' ),
		__( 'Recent results are normally retained for seven days after completion or stopping; the saved deadline is shown. Export before that deadline to retain a copy.', 'thumbnail-manager' ),
	);
	if ( $terminal && yotm_job_has_terminal_recovery_evidence( $job['id'] ) ) {
		$data['limitations'][] = __( 'Some unsuccessful items retain media recovery evidence. Export this report and inspect the original recovery state before starting a fresh attempt.', 'thumbnail-manager' );
	}
	if ( ! $data['complete'] ) {
		$data['limitations'][] = __( 'This run is unfinished, stopped or expired. Its results may be partial. Resume only a valid active job, or start a new check or scan.', 'thumbnail-manager' );
	}
	if ( 'regenerate' === $job['type'] && 0 === $data['total'] && $job['processed'] > 0 ) {
		$data['limitations'][] = __( 'Detailed item results are unavailable for this older job. Only its saved summary can be exported. Run a new check for current details.', 'thumbnail-manager' );
	}
	if ( 'recommendation' === $job['type'] && empty( $public['context']['result'] ) ) {
		$data['limitations'][] = __( 'No complete recommendation result was saved. Start a new scan to obtain recommendations and coverage details.', 'thumbnail-manager' );
	}
	if ( 'prune' === $job['type'] ) {
		$data['limitations'][] = __( 'Prune bytes are candidate estimates before deletion and recorded bytes freed after deletion. A retry creates a new preview requiring fresh approval.', 'thumbnail-manager' );
	}
	return yotm_regenerate_application_success( $data );
}

/**
 * Start a fresh selected retry, without changing the terminal source record.
 *
 * @param string $token Source token.
 * @param array  $keys Selected opaque item keys, at most 100 per attempt.
 * @return array
 */
function yotm_job_retry_application( $token, $keys ) {
	$source = yotm_job_get( $token );
	if ( is_wp_error( $source ) || $source['expires_at'] < gmdate( 'Y-m-d H:i:s' ) || ! in_array( $source['status'], array( 'completed', 'cancelled' ), true ) || ! in_array( $source['type'], array( 'regenerate', 'prune' ), true ) || ! empty( $source['payload']['recovery_only'] ) || yotm_job_has_recovery_journals( $source['id'] ) || yotm_job_has_terminal_recovery_evidence( $source['id'] ) ) {
		return yotm_regenerate_application_error( array( 'msg' => __( 'Retry requires an available completed or stopped job without pending recovery. Continue an active job instead.', 'thumbnail-manager' ) ), 409 );
	}
	if ( ! is_array( $keys ) || empty( $keys ) || count( $keys ) > 100 ) {
		return yotm_regenerate_application_error( array( 'msg' => __( 'Select between 1 and 100 unsuccessful items per attempt.', 'thumbnail-manager' ) ), 400 );
	}
	$rows = array();
	foreach ( array_unique( $keys ) as $key ) {
		if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{64}$/', $key ) ) {
			return yotm_regenerate_application_error( array( 'msg' => __( 'Invalid retry selection.', 'thumbnail-manager' ) ), 400 );
		}
		$row = yotm_job_get_item_by_key( $source['id'], $key );
		if ( ! $row || ! yotm_job_result_retryable_item( $row ) ) {
			return yotm_regenerate_application_error( array( 'msg' => __( 'Only unsuccessful items from this job without unresolved recovery can be retried.', 'thumbnail-manager' ) ), 400 );
		}
		$rows[] = $row;
	}
	if ( 'prune' === $source['type'] ) {
		return yotm_prune_prepare_application(
			array_values( array_diff( array_keys( yotm_get_registered_sizes() ), (array) ( $source['payload']['remove'] ?? array() ) ) ),
			(array) ( $source['payload']['scan_subpaths'] ?? array() ),
			! empty( $source['payload']['discover_current_legacy'] ),
			! empty( $source['payload']['discover_historical'] ),
			array(
				'retry_of'   => $source['token'],
				'retry_keys' => array_column( $rows, 'item_key' ),
			)
		);
	}
	$payload = array_intersect_key(
		$source['payload'],
		array_flip( array( 'operation', 'force_all', 'only_missing', 'selected_sizes', 'selection_meta_max', 'selection_subpaths', 'source_selector' ) )
	);
	if ( 'attached_meta_v2' === ( $source['payload']['selector'] ?? '' ) ) {
		$payload['source_selector'] = 'attached_meta_v2';
	}
	$transaction = ! empty( $payload['force_all'] ) || 'repair' === ( $payload['operation'] ?? '' ) || ( isset( $payload['selected_sizes'] ) && 'check' !== ( $payload['operation'] ?? '' ) );
	$payload     = array_merge(
		$payload,
		array(
			'retry_of'       => $source['token'],
			'scope'          => 'ids',
			'scope_label'    => __( 'Selected unsuccessful images', 'thumbnail-manager' ),
			'selector'       => 'retry_selection_v1',
			'cursor_mode'    => 0,
			'discovery_done' => 0,
			'selection_done' => 0,
			'inspected_at'   => gmdate( 'Y-m-d H:i:s' ),
			'scan_phase'     => $transaction ? 'source_index' : 'selection',
		)
	);
	foreach ( $rows as $row ) {
		$item_payload = array( 'attachment_id' => absint( $row['payload']['attachment_id'] ?? 0 ) );
		if ( isset( $row['payload']['selection_meta_id'] ) ) {
			$item_payload['selection_meta_id'] = absint( $row['payload']['selection_meta_id'] );
		}
		$payload['retry_items'][ $row['item_key'] ] = $item_payload;
	}
	$job = yotm_job_create(
		'regenerate',
		$payload,
		array(
			'status'       => 'running',
			'phase'        => $payload['scan_phase'],
			'counter_mode' => $transaction ? 'item_v3' : 'item_v2',
			'total'        => count( $rows ),
			'ttl'          => DAY_IN_SECONDS,
		)
	);
	if ( is_wp_error( $job ) ) {
		return yotm_regenerate_application_error( array( 'msg' => $job->get_error_message() ), 409 );
	}
	return yotm_regenerate_application_success(
		array(
			'token' => $job['token'],
			'type'  => $job['type'],
		)
	);
}

/**
 * Materialize a fixed bounded retry selection before allowing execution.
 *
 * @param array $job Retry job with the server-derived selection snapshot.
 * @param array $worker Current worker lease.
 * @return array|WP_Error
 */
function yotm_job_retry_select_application( $job, $worker ) {
	$payload = $job['payload'];
	$items   = $payload['retry_items'] ?? array();
	if ( 'retry_selection_v1' !== ( $payload['selector'] ?? '' ) || ! is_array( $items ) || empty( $items ) || count( $items ) > 100 ) {
		return new WP_Error( 'yotm_retry_selection_invalid', __( 'The saved retry selection is unavailable. Stop this job and select unsuccessful items again.', 'thumbnail-manager' ) );
	}
	if ( ! yotm_job_refresh_worker( $worker ) ) {
		return yotm_job_storage_error();
	}
	foreach ( $items as $key => $item_payload ) {
		if ( ! yotm_job_worker_add_selection_item( $worker, $key, $item_payload, $payload ) ) {
			$existing = yotm_job_get_item_by_key( $job['id'], $key );
			if ( ! $existing || $existing['payload'] !== $item_payload ) {
				return yotm_job_storage_error();
			}
		}
	}
	$payload['selection_done'] = 1;
	$payload['discovery_done'] = 1;
	$payload['scan_phase']     = 'regenerate';
	unset( $payload['retry_items'] );
	if ( ! yotm_job_worker_update(
		$worker,
		array(
			'payload' => $payload,
			'phase'   => 'regenerate',
		)
	) ) {
		return yotm_job_storage_error();
	}
	return yotm_job_get_by_id( $job['id'] );
}
