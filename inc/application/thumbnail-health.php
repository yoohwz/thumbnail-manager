<?php
/**
 * Selected thumbnail inspection and missing-only repair orchestration.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Start repair from an owned, completed inspection; derive selection server-side.
 *
 * @param string $token Inspection token.
 * @return array
 */
function yotm_thumbnail_health_prepare_repair( $token ) {
	$source = yotm_job_get( sanitize_text_field( $token ) );
	if ( is_wp_error( $source ) || 'regenerate' !== $source['type'] || 'check' !== ( $source['payload']['operation'] ?? '' ) || 'completed' !== $source['status'] ) {
		return yotm_regenerate_application_error( array( 'msg' => __( 'Run a complete thumbnail check before repairing this selection.', 'thumbnail-manager' ) ), 400 );
	}
	$payload = $source['payload'];
	$payload = array_merge(
		$payload,
		array(
			'operation'                => 'repair',
			'inspected_at'             => gmdate( 'Y-m-d H:i:s' ),
			'report_token'             => $source['token'],
			'source_selector'          => $payload['selector'] ?? 'attachment_id_v1',
			'selector'                 => 'health_report_v1',
			'report_page'              => 1,
			'cursor_mode'              => 0,
			'discovery_done'           => 0,
			'selection_done'           => 0,
			'source_index_initialized' => 0,
			'source_index_complete'    => 0,
			'scan_phase'               => 'source_index',
			'force_all'                => 0,
			'only_missing'             => 1,
		)
	);
	$job     = yotm_job_create(
		'regenerate',
		$payload,
		array(
			'status'       => 'running',
			'phase'        => 'source_index',
			'counter_mode' => 'item_v3',
			'total'        => $source['total'],
			'ttl'          => DAY_IN_SECONDS,
		)
	);
	if ( is_wp_error( $job ) ) {
		$data = $job->get_error_data();
		return yotm_regenerate_application_error(
			array(
				'msg'          => $job->get_error_message(),
				'resume_token' => is_array( $data ) ? ( $data['token'] ?? '' ) : '',
			),
			409
		);
	}
	return yotm_regenerate_application_success( yotm_build_regenerate_response( $job, false ) );
}

/**
 * Copy a bounded page of the exact inspected attachment selection.
 *
 * @param array $job Repair job.
 * @param array $worker Worker lease.
 * @return array|WP_Error
 */
function yotm_thumbnail_health_select_report_batch( $job, $worker ) {
	$payload = $job['payload'];
	$source  = yotm_job_get( $payload['report_token'] ?? '' );
	if ( is_wp_error( $source ) || 'completed' !== $source['status'] || 'check' !== ( $source['payload']['operation'] ?? '' ) ) {
		return new WP_Error( 'yotm_health_report_expired', __( 'The inspection record is no longer available. Stop this job and run a new check.', 'thumbnail-manager' ) );
	}
	// Fixed page size keeps the persisted page cursor stable across client batch changes.
	$page = yotm_job_get_item_rows_page( $source['id'], absint( $payload['report_page'] ?? 1 ), 100 );
	if ( is_wp_error( $page ) ) {
		return $page;
	}
	foreach ( $page['items'] as $source_item ) {
		$item_payload = array( 'attachment_id' => absint( $source_item['payload']['attachment_id'] ?? 0 ) );
		if ( isset( $source_item['payload']['selection_meta_id'] ) ) {
			$item_payload['selection_meta_id'] = absint( $source_item['payload']['selection_meta_id'] );
		}
		$key = hash( 'sha256', 'attachment:' . $item_payload['attachment_id'] );
		if ( ! yotm_job_add_item( $job['id'], $key, $item_payload ) && ! yotm_job_item_exists( $job['id'], $key ) ) {
			return yotm_job_storage_error();
		}
	}
	$payload['report_page'] = $page['page'] + 1;
	$done                   = $page['page'] >= $page['pages'];
	if ( $done ) {
		$payload['selection_done'] = 1;
		$payload['discovery_done'] = 1;
	}
	if ( ! yotm_job_worker_update(
		$worker,
		array(
			'payload' => $payload,
			'phase'   => $done ? 'regenerate' : 'selection',
		)
	) ) {
		return yotm_job_storage_error();
	}
	return yotm_job_get_by_id( $job['id'] );
}

/**
 * Inspect or repair one claimed attachment and persist its public outcome.
 *
 * @param array  $item Claimed item.
 * @param array  $worker Worker lease.
 * @param string $operation Check or repair.
 * @return array
 */
function yotm_thumbnail_health_process_item( $item, $worker, $operation ) {
	$id = absint( $item['payload']['attachment_id'] ?? 0 );
	if ( 'repair' === $operation ) {
		$result = yotm_regenerate_force_attachment( $id, $item, $worker, true );
		if ( 'retry' === $result['status'] ) {
			return $result;
		}
		// The transaction journals through its own local item; reload its exact payload.
		$current = yotm_job_get_item_by_key( $item['job_id'], $item['item_key'] );
		if ( ! $current ) {
			return yotm_regenerate_failure( yotm_job_storage_error(), true );
		}
		$item = $current;
	} else {
		$result = array(
			'status'  => 'regenerated',
			'message' => '',
		);
	}
	$health                   = yotm_thumbnail_health_inspect( $id );
	$report                   = $health['report'];
	$report['inspected_at']   = gmdate( 'Y-m-d H:i:s' );
	$report['restored_sizes'] = array();
	$report['restored_count'] = 0;
	if ( 'repair' === $operation && 'regenerated' === $result['status'] ) {
		$journal                  = $item['payload']['regeneration_journal'] ?? array();
		$report['restored_sizes'] = array_keys( $journal['destinations'] ?? array() );
		$report['restored_count'] = count( $report['restored_sizes'] );
		$report['message']        = __( 'Missing thumbnails were restored. Other thumbnail files and metadata were preserved.', 'thumbnail-manager' );
	} elseif ( 'repair' === $operation && '' !== $result['message'] ) {
		$report['message'] = $result['message'];
		if ( 'failed' === $result['status'] ) {
			$report['status'] = 'blocked';
			$report['action'] = __( 'Review the reason below, resolve the source or storage issue, then run a new check.', 'thumbnail-manager' );
		}
	} elseif ( 'check' === $operation ) {
		$result['status']  = 'blocked' === $report['status'] ? 'failed' : ( 'unsupported' === $report['status'] ? 'skipped' : 'regenerated' );
		$result['message'] = $report['message'];
	}
	$payload                  = $item['payload'];
	$payload['health_report'] = $report;
	if ( ! yotm_job_update_claimed_item_payload( $item, $payload ) ) {
		return yotm_regenerate_failure( yotm_job_storage_error(), true );
	}
	return $result;
}

/**
 * Return only public outcome fields; journals and private snapshots stay server-side.
 *
 * @param string $token Owned job token.
 * @param int    $page Page number.
 * @param int    $per_page Page length.
 * @return array
 */
function yotm_thumbnail_health_results_application( $token, $page = 1, $per_page = 25 ) {
	$job = yotm_job_get( $token );
	if ( is_wp_error( $job ) || 'regenerate' !== $job['type'] || ! in_array( $job['payload']['operation'] ?? '', array( 'check', 'repair' ), true ) ) {
		return yotm_regenerate_application_error( array( 'msg' => __( 'This thumbnail inspection or repair record is unavailable.', 'thumbnail-manager' ) ), 404 );
	}
	$data = yotm_job_get_item_rows_page( $job['id'], $page, $per_page );
	if ( is_wp_error( $data ) ) {
		return yotm_regenerate_application_error( array( 'msg' => $data->get_error_message() ), 503 );
	}
	$items   = array();
	$allowed = array_flip( array( 'status', 'message', 'reason', 'action', 'sizes', 'missing_count', 'checked_count', 'restored_count', 'restored_sizes', 'inspected_at' ) );
	foreach ( $data['items'] as $item ) {
		$report                  = array_intersect_key( (array) ( $item['payload']['health_report'] ?? array() ), $allowed );
		$report['attachment_id'] = absint( $item['payload']['attachment_id'] ?? 0 );
		$report['title']         = get_the_title( $report['attachment_id'] );
		$report['item_status']   = $item['status'];
		if ( empty( $report['message'] ) && ! empty( $item['error'] ) ) {
			$report['message'] = $item['error'];
		}
		$items[] = $report;
	}
	$data['items']        = $items;
	$data['operation']    = $job['payload']['operation'];
	$data['done']         = 'completed' === $job['status'];
	$data['scope_label']  = $job['payload']['scope_label'] ?? '';
	$data['inspected_at'] = $job['payload']['inspected_at'] ?? '';
	return yotm_regenerate_application_success( $data );
}
