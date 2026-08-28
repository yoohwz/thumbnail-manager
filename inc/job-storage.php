<?php
/**
 * Persistent job compatibility and feature transport.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/jobs/storage.php';
require_once __DIR__ . '/jobs/engine.php';
require_once __DIR__ . '/jobs/items.php';

/**
 * Build an immutable manifest hash in bounded batches.
 *
 * @param array      $job Job row.
 * @param int        $limit Hash batch size.
 * @param array|null $worker Optional worker ownership data.
 * @return array{done:bool,job:array}
 */
function yotm_job_build_manifest_batch( $job, $limit = 1000, $worker = null ) {
	global $wpdb;

	$payload = $job['payload'];
	$after   = isset( $payload['manifest_after'] ) ? (string) $payload['manifest_after'] : '';
	$digest  = isset( $payload['manifest_digest'] ) ? (string) $payload['manifest_digest'] : hash( 'sha256', 'yotm-manifest-v1' );
	$tables  = yotm_job_table_names();
	$limit   = max( 10, min( 5000, absint( $limit ) ) );
	$rows    = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT item_key,payload FROM {$tables['items']} WHERE job_id = %d AND item_key > %s ORDER BY item_key ASC LIMIT %d",
			$job['id'],
			$after,
			$limit
		)
	);

	foreach ( $rows as $row ) {
		$item_payload = yotm_job_decode_payload( $row->payload );
		if (
			'prune' === ( $job['type'] ?? '' )
			&& 'generated_file_v1' === ( $item_payload['ownership_schema'] ?? '' )
			&& function_exists( 'yotm_prune_validate_live_reference_evidence' )
		) {
			$source_fence = yotm_media_source_fence_acquire();
			if ( is_wp_error( $source_fence ) ) {
				return array(
					'done' => false,
					'job'  => yotm_job_get_by_id( $job['id'] ),
				);
			}
			try {
				$reference_check = yotm_prune_validate_live_reference_evidence( $item_payload, $item_payload['path'] ?? '' );
			} finally {
				yotm_media_source_fence_release( $source_fence );
			}
			if ( is_wp_error( $reference_check ) ) {
				// Mutable scan item: unsafe ownership is removed before immutable hashing/review.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned pre-manifest item.
				$removed = $wpdb->delete(
					$tables['items'],
					array(
						'job_id'   => (int) $job['id'],
						'item_key' => (string) $row->item_key,
					),
					array( '%d', '%s' )
				);
				if ( 1 !== $removed ) {
					return array(
						'done' => false,
						'job'  => yotm_job_get_by_id( $job['id'] ),
					);
				}
				$after = (string) $row->item_key;
				continue;
			}
		}//end if
		$digest = hash( 'sha256', $digest . ':' . $row->item_key . ':' . hash( 'sha256', (string) $row->payload ) );
		$after  = (string) $row->item_key;
	}//end foreach

	$payload['manifest_after']  = $after;
	$payload['manifest_digest'] = $digest;
	$done                       = count( $rows ) < $limit;
	$fields                     = array( 'payload' => $payload );

	if ( $done ) {
		$fields['manifest_hash']         = $digest;
		$payload['reference_generation'] = defined( 'YOTM_MEDIA_REFERENCE_GENERATION' ) ? YOTM_MEDIA_REFERENCE_GENERATION : 0;
		$fields['payload']               = $payload;
	}

	$updated = is_array( $worker )
		? yotm_job_worker_update( $worker, $fields )
		: yotm_job_update( $job['id'], $fields );

	return array(
		'done' => $done && $updated,
		'job'  => yotm_job_get_by_id( $job['id'] ),
	);
}

/**
 * Project a recommendation result for public delivery.
 *
 * @param mixed         $result Persisted recommendation result.
 * @param callable|null $projector Optional projector override for tests.
 * @return array|null
 */
function yotm_job_public_recommendation_result( $result, $projector = null ) {
	if ( null === $projector ) {
		if ( ! function_exists( 'yotm_recommendation_result_for_response' ) ) {
			return null;
		}

		$projector = 'yotm_recommendation_result_for_response';
	}

	if ( ! is_callable( $projector ) ) {
		return null;
	}

	$projected = call_user_func( $projector, $result );

	return is_array( $projected ) ? $projected : null;
}

/**
 * Return safe job state for browser resume.
 *
 * @param array $job Job row.
 * @return array
 */
function yotm_job_public_data( $job ) {
	$payload = $job['payload'];
	$context = array();
	$allowed = array(
		'scope',
		'scope_label',
		'only_missing',
		'force_all',
		'scan_processed',
		'scan_total_attachments',
		'sample',
		'keep',
		'remove',
		'scan_base_label',
		'scan_base_labels',
		'scan_subpaths',
		'orphan_summary',
		'result',
		'scan_phase',
		'estimated_bytes',
		'disk_entries_processed',
		'selection_done',
		'selection_meta_after',
		'selection_meta_max',
		'selection_scanned',
		'selection_matched',
		'selector',
		'cancelled_at',
	);

	foreach ( $allowed as $key ) {
		if ( array_key_exists( $key, $payload ) ) {
			$context[ $key ] = $payload[ $key ];
		}
	}

	if (
		'recommendation' === ( $job['type'] ?? '' )
		&& 'completed' === ( $job['status'] ?? '' )
	) {
		$projected_result = yotm_job_public_recommendation_result( $context['result'] ?? array() );
		if ( is_array( $projected_result ) ) {
			$context['result'] = $projected_result;
		} else {
			unset( $context['result'] );
		}
	}

	$public = array(
		'token'         => $job['token'],
		'type'          => $job['type'],
		'status'        => $job['status'],
		'phase'         => $job['phase'],
		'manifest_hash' => $job['manifest_hash'],
		'total'         => $job['total'],
		'processed'     => $job['processed'],
		'succeeded'     => $job['succeeded'],
		'failed'        => $job['failed'],
		'bytes'         => $job['bytes'],
		'bytes_human'   => size_format( $job['bytes'] ),
		'created_at'    => $job['created_at'],
		'updated_at'    => $job['updated_at'],
		'expires_at'    => $job['expires_at'],
		'context'       => $context,
	);

	if ( 'regenerate' === ( $job['type'] ?? '' ) && 'attached_meta_v2' === ( $context['selector'] ?? '' ) ) {
		$public['total_known'] = ! empty( $context['selection_done'] );
	}

	return $public;
}

/**
 * Return recent jobs owned by the current user on this site.
 *
 * @param int $limit Maximum rows.
 * @return array[]|WP_Error
 */
function yotm_job_get_recent_for_current_user( $limit = 10 ) {
	global $wpdb;

	$ready = yotm_job_storage_ready();
	if ( is_wp_error( $ready ) ) {
		return $ready;
	}

	$tables      = yotm_job_table_names();
	$rows        = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$tables['jobs']} WHERE blog_id = %d AND user_id = %d ORDER BY id DESC LIMIT %d",
			get_current_blog_id(),
			get_current_user_id(),
			max( 1, min( 25, absint( $limit ) ) )
		)
	);
	$query_error = yotm_job_last_database_error();
	if ( is_wp_error( $query_error ) ) {
		return $query_error;
	}

	$out = array();

	foreach ( $rows as $row ) {
		$job = yotm_job_normalize_row( $row );
		if ( $job ) {
			$job = yotm_job_expire_if_inactive( $job );
		}
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( $job ) {
			$out[] = yotm_job_public_data( $job );
		}
	}

	return $out;
}

/**
 * AJAX job status for safe browser resume.
 */
function yotm_job_ajax_status() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	$job   = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		wp_send_json_error( array( 'msg' => $job->get_error_message() ), 404 );
	}

	wp_send_json_success( yotm_job_public_data( $job ) );
}
add_action( 'wp_ajax_yotm_job_status', 'yotm_job_ajax_status' );

/**
 * AJAX paginated manifest items for a job owned by the current user.
 */
function yotm_job_ajax_items() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	$job   = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		wp_send_json_error( array( 'msg' => $job->get_error_message() ), 404 );
	}

	if ( 'prune' !== $job['type'] ) {
		wp_send_json_error( array( 'msg' => __( 'Manifest items are only available for prune jobs.', 'thumbnail-manager' ) ), 400 );
	}

	$page     = isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1;
	$per_page = isset( $_POST['per_page'] ) ? absint( wp_unslash( $_POST['per_page'] ) ) : 25;
	$search   = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
	$data     = yotm_job_get_items_page( $job['id'], $page, $per_page, $search );

	wp_send_json_success( $data );
}
add_action( 'wp_ajax_yotm_job_items', 'yotm_job_ajax_items' );

/**
 * AJAX recent jobs for the active-job banner and history table.
 */
function yotm_job_ajax_recent() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$jobs = yotm_job_get_recent_for_current_user( 10 );

	if ( is_wp_error( $jobs ) ) {
		wp_send_json_error( array( 'msg' => $jobs->get_error_message() ), 503 );
	}

	wp_send_json_success( array( 'jobs' => $jobs ) );
}
add_action( 'wp_ajax_yotm_jobs_recent', 'yotm_job_ajax_recent' );

/**
 * AJAX server-side stop that retains audit data.
 */
function yotm_job_ajax_cancel() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	$job   = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		wp_send_json_error( array( 'msg' => $job->get_error_message() ), 404 );
	}

	$job = yotm_job_cancel( $job );
	if ( is_wp_error( $job ) ) {
		wp_send_json_error(
			array(
				'msg'            => $job->get_error_message(),
				'retry_after_ms' => 250,
			),
			409
		);
	}
	wp_send_json_success(
		array(
			'cancelled' => true,
			'job'       => yotm_job_public_data( $job ),
		)
	);
}
add_action( 'wp_ajax_yotm_job_cancel', 'yotm_job_ajax_cancel' );
