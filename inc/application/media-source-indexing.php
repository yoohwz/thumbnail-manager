<?php
/**
 * Shared Application coordinator for authoritative Media source indexing.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the complete site-wide source baseline in bounded resumable batches.
 *
 * @param array $job Prune or Regenerate job.
 * @param int   $limit Attachment batch size.
 * @param array $worker Current worker ownership.
 * @return array{done:bool,job:array}|WP_Error
 */
function yotm_application_media_source_index_batch( $job, $limit, $worker ) {
	$payload    = $job['payload'];
	$next_phase = 'attached_meta_v2' === ( $payload['selector'] ?? '' )
		? 'selection'
		: ( 'regenerate' === ( $job['type'] ?? '' ) ? 'regenerate' : 'metadata' );
	if ( empty( $payload['source_index_initialized'] ) ) {
		$complete = yotm_media_reference_require_complete_index();
		if ( true === $complete ) {
			$state                               = yotm_media_reference_index_state();
			$payload['source_index_initialized'] = 1;
			$payload['source_index_complete']    = 1;
			$payload['source_index_generation']  = YOTM_MEDIA_REFERENCE_GENERATION;
			$payload['source_index_token']       = $state['baseline_token'];
			$payload['scan_phase']               = $next_phase;
			if ( ! yotm_job_worker_update(
				$worker,
				array(
					'payload' => $payload,
					'phase'   => $next_phase,
					'cursor'  => 0,
				)
			) ) {
				return yotm_job_storage_error();
			}
			return array(
				'done' => true,
				'job'  => yotm_job_get_by_id( $job['id'] ),
			);
		}//end if

		$begun = yotm_media_reference_baseline_begin();
		if ( is_wp_error( $begun ) ) {
			return $begun;
		}
		$payload['source_index_initialized'] = 1;
		$payload['source_index_complete']    = 0;
		$payload['source_index_cursor']      = 0;
		$payload['source_index_max_id']      = yotm_get_max_image_attachment_id( array() );
		$payload['source_index_generation']  = YOTM_MEDIA_REFERENCE_GENERATION;
		$payload['source_index_token']       = $begun['baseline_token'];
		if ( ! yotm_job_worker_update( $worker, array( 'payload' => $payload ) ) ) {
			return yotm_job_storage_error();
		}
	} else {
		$state = yotm_media_reference_index_state();
		if (
			is_wp_error( $state )
			|| 'building' !== $state['status']
			|| YOTM_MEDIA_REFERENCE_GENERATION !== absint( $payload['source_index_generation'] ?? 0 )
			|| ! hash_equals( (string) $state['baseline_token'], (string) ( $payload['source_index_token'] ?? '' ) )
		) {
			$payload['source_index_initialized'] = 0;
			$payload['source_index_complete']    = 0;
			$payload['source_index_cursor']      = 0;
			$payload['source_index_max_id']      = 0;
			yotm_job_worker_update( $worker, array( 'payload' => $payload ) );
			return array(
				'done' => false,
				'job'  => yotm_job_get_by_id( $job['id'] ),
			);
		}
	}//end if

	$source_ids = yotm_get_image_attachment_ids_after(
		array(),
		absint( $payload['source_index_cursor'] ?? 0 ),
		max( 1, min( 500, absint( $limit ) ) ),
		absint( $payload['source_index_max_id'] ?? 0 )
	);
	if ( ! empty( $source_ids ) ) {
		foreach ( $source_ids as $source_id ) {
			$synced = yotm_media_source_sync_attachment( $source_id, null, true );
			if ( is_wp_error( $synced ) ) {
				return $synced;
			}
		}
		$payload['source_index_cursor'] = max( array_map( 'absint', $source_ids ) );
		if ( ! yotm_job_worker_update( $worker, array( 'payload' => $payload ) ) ) {
			return yotm_job_storage_error();
		}
		return array(
			'done' => false,
			'job'  => yotm_job_get_by_id( $job['id'] ),
		);
	}

	$source_fence = yotm_media_source_fence_acquire();
	if ( is_wp_error( $source_fence ) ) {
		return $source_fence;
	}
	try {
		$dirty = yotm_media_source_dirty_state();
		if ( is_wp_error( $dirty ) ) {
			return $dirty;
		}
		if ( ! empty( $dirty['entries'] ) ) {
			$payload['source_index_initialized'] = 0;
			$payload['source_index_complete']    = 0;
			$payload['source_index_cursor']      = 0;
			$payload['source_index_max_id']      = 0;
			if ( ! yotm_job_worker_update( $worker, array( 'payload' => $payload ) ) ) {
				return yotm_job_storage_error();
			}
			return array(
				'done' => false,
				'job'  => yotm_job_get_by_id( $job['id'] ),
			);
		}

		$current_max = yotm_get_max_image_attachment_id( array() );
		if ( $current_max > absint( $payload['source_index_max_id'] ?? 0 ) ) {
			$payload['source_index_max_id'] = $current_max;
			if ( ! yotm_job_worker_update( $worker, array( 'payload' => $payload ) ) ) {
				return yotm_job_storage_error();
			}
			return array(
				'done' => false,
				'job'  => yotm_job_get_by_id( $job['id'] ),
			);
		}

		$completed = yotm_media_reference_baseline_complete( $payload['source_index_token'] ?? '' );
		if ( is_wp_error( $completed ) ) {
			return $completed;
		}
	} finally {
		yotm_media_source_fence_release( $source_fence );
	}//end try

	$payload['source_index_complete']   = 1;
	$payload['source_index_generation'] = YOTM_MEDIA_REFERENCE_GENERATION;
	$payload['scan_phase']              = $next_phase;
	if ( ! yotm_job_worker_update(
		$worker,
		array(
			'payload' => $payload,
			'phase'   => $next_phase,
			'cursor'  => 0,
		)
	) ) {
		return yotm_job_storage_error();
	}
	return array(
		'done' => true,
		'job'  => yotm_job_get_by_id( $job['id'] ),
	);
}

/**
 * Backward-compatible Prune-named source-index coordinator.
 *
 * @param array $job Job row.
 * @param int   $limit Maximum attachment rows.
 * @param array $worker Worker ownership data.
 * @return array|WP_Error
 */
function yotm_prune_source_index_batch( $job, $limit, $worker ) {
	return yotm_application_media_source_index_batch( $job, $limit, $worker );
}
