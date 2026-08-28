<?php
/**
 * Resumable prune scanning and manifest creation.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_yotm_prune_prepare', 'yotm_prune_prepare' );
add_action( 'wp_ajax_yotm_prune_scan_batch', 'yotm_prune_scan_batch' );

/** ===== AJAX: Prepare persistent prune scan ===== */
function yotm_prune_prepare() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );

	$keep           = isset( $_POST['keep'] ) && is_array( $_POST['keep'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['keep'] ) ) : array();
	$limit_subpaths = isset( $_POST['limit_subpaths'] ) && is_array( $_POST['limit_subpaths'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['limit_subpaths'] ) )
		: array();

	if ( empty( $limit_subpaths ) && isset( $_POST['limit_subpath'] ) ) {
		$limit_subpaths = array( sanitize_text_field( wp_unslash( $_POST['limit_subpath'] ) ) );
	}

	$limit_subpaths   = yotm_normalize_upload_subpaths( $limit_subpaths );
	$discover_orphans = ! empty( $_POST['discover_orphans'] );
	$uploads          = wp_get_upload_dir();
	$base             = trailingslashit( yotm_normalize_filesystem_path( $uploads['basedir'] ) );
	$scan_bases       = yotm_resolve_upload_scan_bases( $base, $limit_subpaths );

	if ( is_wp_error( $scan_bases ) ) {
		wp_send_json_error( array( 'msg' => $scan_bases->get_error_message() ), 400 );
	}

	$sizes              = yotm_get_registered_sizes();
	$all                = array_keys( $sizes );
	$keep               = array_values( array_unique( array_intersect( $keep, $all ) ) );
	$to_remove          = array_values( array_diff( $all, $keep ) );
	$query_args         = yotm_attachment_query_args_for_upload_subpaths( $limit_subpaths );
	$selector           = empty( $limit_subpaths ) ? 'attachment_id_v1' : 'attached_meta_v2';
	$selection_meta_max = 'attached_meta_v2' === $selector ? yotm_get_max_attached_file_meta_id() : 0;
	if ( is_wp_error( $selection_meta_max ) ) {
		wp_send_json_error( array( 'msg' => $selection_meta_max->get_error_message() ), 503 );
	}
	$scan_total  = 'attached_meta_v2' === $selector ? 0 : yotm_count_image_attachments( $query_args );
	$scope_label = yotm_uploads_scope_label( $base, $scan_bases );
	$job         = yotm_job_create(
		'prune',
		array(
			'scan_base'                => $scan_bases[0],
			'scan_bases'               => $scan_bases,
			'scan_subpaths'            => $limit_subpaths,
			'scan_base_label'          => $scope_label,
			'scan_base_labels'         => array_map(
				static function ( $scan_base ) use ( $base ) {
					return yotm_uploads_relative_label( $base, $scan_base );
				},
				$scan_bases
			),
			'base'                     => $base,
			'query_args'               => $query_args,
			'selector'                 => $selector,
			'selection_done'           => 'attached_meta_v2' === $selector ? 0 : 1,
			'selection_meta_after'     => 0,
			'selection_meta_max'       => absint( $selection_meta_max ),
			'selection_scanned'        => 0,
			'selection_matched'        => 0,
			'selection_subpaths'       => $limit_subpaths,
			'scan_processed'           => 0,
			'scan_total_attachments'   => $scan_total,
			'scan_phase'               => 'source_index',
			'ownership_schema'         => 'generated_file_v1',
			'source_index_initialized' => 0,
			'source_index_complete'    => 0,
			'source_index_cursor'      => 0,
			'source_index_max_id'      => 0,
			'sample'                   => array(),
			'keep'                     => $keep,
			'remove'                   => $to_remove,
			'sizes'                    => $sizes,
			'discover_orphans'         => $discover_orphans ? 1 : 0,
			'orphan_summary'           => yotm_initial_orphan_summary(),
			'disk_queue'               => array(),
			'disk_cursor_version'      => 'dfs_v2',
			'disk_entries_processed'   => 0,
		),
		array(
			'status'       => 'scanning',
			'phase'        => 'source_index',
			'counter_mode' => 'item_v3',
			'max_id'       => 'attached_meta_v2' === $selector ? 0 : yotm_get_max_image_attachment_id( $query_args ),
			'ttl'          => DAY_IN_SECONDS,
		)
	);

	if ( is_wp_error( $job ) ) {
		$data = $job->get_error_data();
		wp_send_json_error(
			array(
				'msg'          => $job->get_error_message(),
				'resume_token' => is_array( $data ) ? ( $data['token'] ?? '' ) : '',
			),
			409
		);
	}

	wp_send_json_success( yotm_build_prune_scan_response( $job, false ) );
}

/**
 * Scan attachment metadata, disk entries, and the immutable manifest in bounded batches.
 */
function yotm_prune_scan_batch() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	$batch = isset( $_POST['batch'] ) ? absint( wp_unslash( $_POST['batch'] ) ) : 100;
	$batch = max( 1, min( 500, $batch ) );
	$job   = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		wp_send_json_error( array( 'msg' => $job->get_error_message() ), 400 );
	}

	if ( 'prune' !== $job['type'] ) {
		wp_send_json_error( array( 'msg' => __( 'Invalid prune job.', 'thumbnail-manager' ) ), 400 );
	}

	if ( in_array( $job['status'], array( 'awaiting_approval', 'approved', 'deleting', 'completed' ), true ) ) {
		wp_send_json_success( yotm_build_prune_scan_response( $job, true ) );
	}

	if ( 'scanning' !== $job['status'] ) {
		wp_send_json_error( array( 'msg' => __( 'This prune job is not scannable.', 'thumbnail-manager' ) ), 409 );
	}

	$worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'source_index', 'selection', 'metadata', 'disk', 'manifest' ) );
	if ( is_wp_error( $worker ) ) {
		if ( 'yotm_job_worker_busy' !== $worker->get_error_code() ) {
			wp_send_json_error( array( 'msg' => $worker->get_error_message() ), 503 );
		}

		$data                       = $worker->get_error_data();
		$current                    = is_array( $data ) && is_array( $data['job'] ?? null ) ? $data['job'] : $job;
		$response                   = yotm_build_prune_scan_response( $current, ( $current['status'] ?? '' ) !== 'scanning' );
		$response['retry_after_ms'] = 'scanning' === ( $current['status'] ?? '' ) ? 250 : 0;
		wp_send_json_success( $response );
	}

	$job = yotm_job_get_by_id( $job['id'] );

	$payload = $job['payload'];
	$phase   = $payload['scan_phase'] ?? 'source_index';

	if ( 'source_index' === $phase ) {
		$indexed = yotm_prune_source_index_batch( $job, $batch, $worker );
		if ( is_wp_error( $indexed ) ) {
			wp_send_json_error( array( 'msg' => $indexed->get_error_message() ), 503 );
		}
		$job = $indexed['job'];
		if ( ! $indexed['done'] ) {
			wp_send_json_success( yotm_build_prune_scan_response( $job, false ) );
		}
		$payload = $job['payload'];
		$phase   = $payload['scan_phase'] ?? $job['phase'];
	}//end if

	if ( 'selection' === $phase ) {
		$rows = yotm_get_attached_file_selector_rows_after(
			absint( $payload['selection_meta_after'] ?? 0 ),
			absint( $payload['selection_meta_max'] ?? 0 ),
			$batch
		);
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'msg' => $rows->get_error_message() ), 503 );
		}

		if ( ! empty( $rows ) ) {
			$ids                = array();
			$selection_meta_ids = array();
			$subpaths           = (array) ( $payload['selection_subpaths'] ?? array() );
			foreach ( $rows as $row ) {
				$relative = yotm_normalize_attached_file_relative_path( $row['value'] ?? null );
				if ( is_wp_error( $relative ) || ! yotm_attached_file_is_in_subpaths( $relative, $subpaths ) ) {
					continue;
				}
				$authorized = yotm_authorize_attached_file_selector_scope(
					$row['attachment_id'],
					$row['meta_id'],
					$payload['selection_meta_max'],
					$subpaths
				);
				if ( ! is_wp_error( $authorized ) ) {
					$attachment_id                        = absint( $row['attachment_id'] );
					$ids[ $attachment_id ]                = $attachment_id;
					$selection_meta_ids[ $attachment_id ] = absint( $row['meta_id'] );
				}
			}

			if ( ! empty( $ids ) ) {
				yotm_prune_store_metadata_candidates( $job, array_values( $ids ), $payload, $selection_meta_ids );
			}
			$payload['selection_meta_after'] = max( array_map( 'absint', wp_list_pluck( $rows, 'meta_id' ) ) );
			$payload['selection_scanned']    = (int) ( $payload['selection_scanned'] ?? 0 ) + count( $rows );
			$payload['selection_matched']    = (int) ( $payload['selection_matched'] ?? 0 ) + count( $ids );
			$payload['scan_processed']       = (int) ( $payload['scan_processed'] ?? 0 ) + count( $ids );
			yotm_job_worker_update(
				$worker,
				array(
					'payload' => $payload,
					'total'   => yotm_job_count_items( $job['id'] ),
				)
			);
			wp_send_json_success( yotm_build_prune_scan_response( yotm_job_get_by_id( $job['id'] ), false ) );
		}//end if

		$payload['selection_done']         = 1;
		$payload['scan_total_attachments'] = (int) ( $payload['selection_matched'] ?? 0 );
		$job                               = yotm_prune_advance_after_metadata( $job, $worker, $payload );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'msg' => $job->get_error_message() ), 503 );
		}
		$payload = $job['payload'];
		$phase   = $payload['scan_phase'] ?? $job['phase'];
	}//end if

	if ( 'metadata' === $phase ) {
		if ( empty( $payload['source_index_complete'] ) ) {
			wp_send_json_error( array( 'msg' => __( 'The authoritative source index is incomplete. Run a new scan.', 'thumbnail-manager' ) ), 409 );
		}
		$ids = yotm_get_image_attachment_ids_after(
			is_array( $payload['query_args'] ?? null ) ? $payload['query_args'] : array(),
			$job['cursor'],
			$batch,
			$job['max_id']
		);

		if ( ! empty( $ids ) ) {
			yotm_prune_store_metadata_candidates( $job, $ids, $payload );

			$payload['scan_processed'] = (int) ( $payload['scan_processed'] ?? 0 ) + count( $ids );
			$cursor                    = max( array_map( 'absint', $ids ) );
			$total                     = yotm_job_count_items( $job['id'] );
			yotm_job_worker_update(
				$worker,
				array(
					'payload' => $payload,
					'cursor'  => $cursor,
					'total'   => $total,
				)
			);

			wp_send_json_success( yotm_build_prune_scan_response( yotm_job_get_by_id( $job['id'] ), false ) );
		}//end if

		$job = yotm_prune_advance_after_metadata( $job, $worker, $payload );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'msg' => $job->get_error_message() ), 503 );
		}
	}//end if

	if ( 'disk' === ( $job['payload']['scan_phase'] ?? '' ) ) {
		$disk = yotm_prune_scan_disk_batch( $job, $batch, $worker );
		$job  = $disk['job'];

		if ( ! $disk['done'] ) {
			wp_send_json_success( yotm_build_prune_scan_response( $job, false ) );
		}

		$payload               = $job['payload'];
		$payload['scan_phase'] = 'manifest';
		yotm_job_worker_update(
			$worker,
			array(
				'payload' => $payload,
				'phase'   => 'manifest',
			)
		);
		$job = yotm_job_get_by_id( $job['id'] );
	}

	$manifest = yotm_job_build_manifest_batch( $job, max( 500, $batch * 5 ), $worker );
	$job      = $manifest['job'];

	if ( ! $manifest['done'] ) {
		wp_send_json_success( yotm_build_prune_scan_response( $job, false ) );
	}

	$current = yotm_job_get_by_id( $job['id'] );
	if ( is_array( $current ) && 'cancelled' === $current['status'] ) {
		wp_send_json_success( yotm_build_prune_scan_response( $current, false ) );
	}

	$payload                    = $job['payload'];
	$payload['scan_phase']      = 'review';
	$payload['scan_done']       = 1;
	$payload['estimated_bytes'] = yotm_job_sum_item_bytes( $job['id'] );
	$total                      = yotm_job_count_items( $job['id'] );
	$final_status               = $total > 0 ? 'awaiting_approval' : 'completed';
	$final_phase                = $total > 0 ? 'review' : 'completed';
	yotm_job_worker_update(
		$worker,
		array(
			'payload'    => $payload,
			'phase'      => $final_phase,
			'status'     => $final_status,
			'total'      => $total,
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( $total > 0 ? HOUR_IN_SECONDS : 7 * DAY_IN_SECONDS ) ),
		)
	);

	wp_send_json_success( yotm_build_prune_scan_response( yotm_job_get_by_id( $job['id'] ), true ) );
}

/**
 * Discover and persist prune candidates for one bounded attachment batch.
 *
 * @param array $job Prune job.
 * @param int[] $ids Attachment IDs.
 * @param array $payload Job payload, updated by reference.
 * @param int[] $selection_meta_ids Exact selected meta IDs keyed by attachment ID.
 */
function yotm_prune_store_metadata_candidates( $job, $ids, &$payload, $selection_meta_ids = array() ) {
	$candidates = array();
	yotm_collect_metadata_prune_candidates_for_ids(
		$ids,
		is_array( $payload['scan_bases'] ?? null ) ? $payload['scan_bases'] : array( $payload['scan_base'] ),
		is_array( $payload['keep'] ?? null ) ? $payload['keep'] : array(),
		is_array( $payload['remove'] ?? null ) ? $payload['remove'] : array(),
		is_array( $payload['sizes'] ?? null ) ? $payload['sizes'] : array(),
		! empty( $payload['discover_orphans'] ),
		$candidates,
		$payload['orphan_summary']
	);

	foreach ( $candidates as $candidate ) {
		if ( empty( $candidate['path'] ) ) {
			continue;
		}
		if ( 'attached_meta_v2' === ( $payload['selector'] ?? '' ) ) {
			$bound = true;
			foreach ( array( 'metadata_refs', 'ownership_evidence' ) as $field ) {
				foreach ( (array) ( $candidate[ $field ] ?? array() ) as $index => $evidence ) {
					$attachment_id = absint( $evidence['attachment_id'] ?? 0 );
					if ( empty( $selection_meta_ids[ $attachment_id ] ) ) {
						$bound = false;
						break 2;
					}
					$candidate[ $field ][ $index ]['selection_meta_id'] = absint( $selection_meta_ids[ $attachment_id ] );
				}
			}
			if ( ! $bound ) {
				continue;
			}
		}

		$candidate                    = yotm_prune_normalize_candidate_evidence( $candidate );
		$path                         = yotm_normalize_filesystem_path( $candidate['path'] );
		$item_key                     = hash( 'sha256', $path );
		$bytes                        = is_file( $path ) ? filesize( $path ) : 0;
		$bytes                        = false === $bytes ? 0 : (int) $bytes;
		$candidate['estimated_bytes'] = $bytes;
		$inserted                     = yotm_job_add_item( $job['id'], $item_key, $candidate, 'queued', $bytes );

		if ( ! $inserted ) {
			yotm_job_merge_item_payload( $job['id'], $item_key, $candidate );
		}

		if ( $inserted && count( $payload['sample'] ) < 300 ) {
			$payload['sample'][] = $path;
		}
	}//end foreach
}

/**
 * Move a prune scan from attachment discovery to disk or manifest work.
 *
 * @param array $job Prune job.
 * @param array $worker Current worker ownership.
 * @param array $payload Current payload.
 * @return array|WP_Error
 */
function yotm_prune_advance_after_metadata( $job, $worker, $payload ) {
	if ( ! empty( $payload['discover_orphans'] ) ) {
		$payload['scan_phase'] = 'disk';
		$payload['disk_queue'] = array_map(
			static function ( $scan_base ) {
				return array(
					'path'   => $scan_base,
					'root'   => $scan_base,
					'offset' => 0,
				);
			},
			is_array( $payload['scan_bases'] ?? null ) ? $payload['scan_bases'] : array( $payload['scan_base'] )
		);
		$phase                 = 'disk';
	} else {
		$payload['scan_phase'] = 'manifest';
		$phase                 = 'manifest';
	}

	if ( ! yotm_job_worker_update(
		$worker,
		array(
			'payload' => $payload,
			'phase'   => $phase,
		)
	) ) {
		return yotm_job_storage_error();
	}

	return yotm_job_get_by_id( $job['id'] );
}

/**
 * Build the complete site-wide source baseline in bounded resumable batches.
 *
 * @param array $job Prune job.
 * @param int   $limit Attachment batch size.
 * @param array $worker Current worker ownership.
 * @return array{done:bool,job:array}|WP_Error
 */
function yotm_prune_source_index_batch( $job, $limit, $worker ) {
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
 * Deduplicate and sort immutable ownership evidence before persistence.
 *
 * @param array $candidate Candidate payload.
 * @return array
 */
function yotm_prune_normalize_candidate_evidence( $candidate ) {
	foreach ( array( 'metadata_refs', 'ownership_evidence' ) as $field ) {
		$normalized = array();
		foreach ( (array) ( $candidate[ $field ] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$key                = absint( $entry['attachment_id'] ?? 0 ) . ':' . sanitize_key( $entry['size'] ?? '' ) . ':' . sanitize_file_name( $entry['filename'] ?? '' ) . ':' . sanitize_key( $entry['selection'] ?? '' );
			$normalized[ $key ] = $entry;
		}
		ksort( $normalized );
		$candidate[ $field ] = array_values( $normalized );
	}

	return $candidate;
}

/**
 * Scan disk entries with a persisted breadth-first directory cursor.
 *
 * @param array      $job Job row.
 * @param int        $limit Maximum directory entries.
 * @param array|null $worker Optional worker ownership data.
 * @return array{done:bool,job:array}
 */
function yotm_prune_scan_disk_batch( $job, $limit = 100, $worker = null ) {
	$payload     = $job['payload'];
	$queue       = is_array( $payload['disk_queue'] ?? null ) ? $payload['disk_queue'] : array();
	$dfs_v2      = 'dfs_v2' === ( $payload['disk_cursor_version'] ?? '' );
	$summary     = is_array( $payload['orphan_summary'] ?? null ) ? $payload['orphan_summary'] : yotm_initial_orphan_summary();
	$scan_bases  = is_array( $payload['scan_bases'] ?? null ) ? $payload['scan_bases'] : array( $payload['scan_base'] ?? '' );
	$processed   = 0;
	$disk_checks = array();
	$limit       = max( 1, min( 1000, absint( $limit ) ) );
	$disc_rx     = '/-(\d+)x(\d+)(?:@\d+x)?(?:-\d+)?'
		. '(?:\.(?:jpg|jpeg|png|gif|avif|bak|backup|orig|original|old|tmp|temp))*'
		. '\.(?:jpg|jpeg|png|gif|webp|avif)$/i';

	while ( ! empty( $queue ) && $processed < $limit ) {
		$queue_index  = $dfs_v2 ? count( $queue ) - 1 : 0;
		$queue_item   = is_array( $queue[ $queue_index ] ) ? $queue[ $queue_index ] : array(
			'path'   => $queue[ $queue_index ],
			'offset' => 0,
		);
		$current      = yotm_normalize_filesystem_path( $queue_item['path'] ?? '' );
		$offset       = absint( $queue_item['offset'] ?? 0 );
		$current_root = yotm_find_scan_root_for_path( $current, $scan_bases );

		if ( '' === $current_root || ! is_dir( $current ) ) {
			array_splice( $queue, $queue_index, 1 );
			continue;
		}

		try {
			$iterator = new DirectoryIterator( $current );
			if ( $offset > 0 ) {
				$iterator->seek( $offset );
			}
		} catch ( Throwable $error ) {
			$summary['errors'] = is_array( $summary['errors'] ?? null ) ? $summary['errors'] : array();
			if ( count( $summary['errors'] ) < 20 ) {
				$summary['errors'][] = $error->getMessage();
			}
			array_splice( $queue, $queue_index, 1 );
			continue;
		}

		$descended = false;
		while ( $iterator->valid() && $processed < $limit ) {
			$is_dot     = $iterator->isDot();
			$is_dir     = $iterator->isDir();
			$is_link    = $iterator->isLink();
			$is_file    = $iterator->isFile();
			$entry_path = $iterator->getPathname();
			$iterator->next();
			++$offset;
			++$processed;

			if ( $is_dot ) {
				continue;
			}

			if ( $is_dir ) {
				if ( ! $is_link ) {
					$subdir = yotm_normalize_filesystem_path( $entry_path );
					if ( yotm_is_path_inside_dir( $subdir, $current_root ) ) {
						if ( $dfs_v2 ) {
							$queue[ $queue_index ] = array(
								'path'   => $current,
								'root'   => $current_root,
								'offset' => $offset,
							);
						}
						$queue[] = array(
							'path'   => $subdir,
							'root'   => $current_root,
							'offset' => 0,
						);
						if ( $dfs_v2 ) {
							$descended = true;
							break;
						}
					}
				}//end if
			} elseif ( $is_file ) {
				$path = yotm_normalize_filesystem_path( $entry_path );
				if ( false === strpos( $path, '/imagify-backups/' ) ) {
					$summary['total_files'] = (int) ( $summary['total_files'] ?? 0 ) + 1;

					if ( preg_match( $disc_rx, $path, $matches ) ) {
						$dim                      = $matches[1] . 'x' . $matches[2];
						$summary['found'][ $dim ] = (int) ( $summary['found'][ $dim ] ?? 0 ) + 1;

						$disk_checks[ hash( 'sha256', $path ) ] = $path;
					}
				}//end if
			}//end if
		}//end while

		if ( $descended ) {
			continue;
		}
		if ( ! $iterator->valid() ) {
			array_splice( $queue, $queue_index, 1 );
		} else {
			$queue[ $queue_index ] = array(
				'path'   => $current,
				'root'   => $current_root,
				'offset' => $offset,
			);
		}
	}//end while

	$existing = yotm_job_existing_item_keys( $job['id'], array_keys( $disk_checks ) );
	foreach ( array_diff_key( $disk_checks, $existing ) as $path ) {
		$filename = wp_basename( $path );
		if ( preg_match( '/\.(?:jpe?g|png|gif)\.(?:webp|avif)$/i', $filename ) ) {
			$summary['unverified_sidecars'] = (int) ( $summary['unverified_sidecars'] ?? 0 ) + 1;
		} elseif ( preg_match( '/(?:\.(?:bak|backup|orig|original|old|tmp|temp)(?:\.|$)|@\d+x|-\d+\.)/i', $filename ) ) {
			$summary['ambiguous_siblings'] = (int) ( $summary['ambiguous_siblings'] ?? 0 ) + 1;
		}
		$summary['unmapped']         = (int) ( $summary['unmapped'] ?? 0 ) + 1;
		$summary['unmapped_skipped'] = (int) ( $summary['unmapped_skipped'] ?? 0 ) + 1;
		if ( count( $summary['unmapped_sample'] ) < 20 ) {
			$summary['unmapped_sample'][] = $path;
		}
	}

	arsort( $summary['found'] );
	$payload['orphan_summary']         = $summary;
	$payload['disk_queue']             = array_values( $queue );
	$payload['disk_entries_processed'] = (int) ( $payload['disk_entries_processed'] ?? 0 ) + $processed;
	if ( is_array( $worker ) ) {
		yotm_job_worker_update( $worker, array( 'payload' => $payload ) );
	} else {
		yotm_job_update( $job['id'], array( 'payload' => $payload ) );
	}

	return array(
		'done' => empty( $queue ),
		'job'  => yotm_job_get_by_id( $job['id'] ),
	);
}

/**
 * Build the scan/review response without returning internal queue data.
 *
 * @param array $job Job row.
 * @param bool  $done Whether review is ready.
 * @return array
 */
function yotm_build_prune_scan_response( $job, $done ) {
	$payload    = $job['payload'];
	$scan_total = max( 1, (int) ( $payload['scan_total_attachments'] ?? 0 ) );
	$phase      = $payload['scan_phase'] ?? $job['phase'];
	$selecting  = 'selection' === $phase && empty( $payload['selection_done'] );
	$processed  = $selecting
		? (int) ( $payload['selection_scanned'] ?? 0 )
		: min( (int) ( $payload['scan_processed'] ?? 0 ), $scan_total );

	if ( $done ) {
		$percent = 100;
	} elseif ( $selecting ) {
		$percent = null;
	} elseif ( in_array( $phase, array( 'source_index', 'metadata' ), true ) ) {
		$percent = min( ! empty( $payload['discover_orphans'] ) ? 90 : 98, ( $processed / $scan_total ) * 90 );
	} elseif ( 'disk' === $phase ) {
		$percent = 95;
	} else {
		$percent = 99;
	}

	return array(
		'token'                  => $job['token'],
		'status'                 => $job['status'],
		'scan_done'              => (bool) $done,
		'scan_phase'             => $phase,
		'scan_processed'         => $processed,
		'scan_total_attachments' => (int) ( $payload['scan_total_attachments'] ?? 0 ),
		'disk_entries_processed' => (int) ( $payload['disk_entries_processed'] ?? 0 ),
		'scan_percent'           => $percent,
		'total_known'            => ! $selecting,
		'selection_scanned'      => (int) ( $payload['selection_scanned'] ?? 0 ),
		'total'                  => $job['total'],
		'estimated_bytes'        => (int) ( $payload['estimated_bytes'] ?? 0 ),
		'estimated_bytes_human'  => size_format( (int) ( $payload['estimated_bytes'] ?? 0 ) ),
		'sample'                 => is_array( $payload['sample'] ?? null ) ? $payload['sample'] : array(),
		'keep'                   => is_array( $payload['keep'] ?? null ) ? $payload['keep'] : array(),
		'remove'                 => is_array( $payload['remove'] ?? null ) ? $payload['remove'] : array(),
		'scan_base'              => (string) ( $payload['scan_base_label'] ?? 'uploads/' ),
		'orphan_summary'         => is_array( $payload['orphan_summary'] ?? null ) ? $payload['orphan_summary'] : yotm_initial_orphan_summary(),
		'manifest_hash'          => $job['manifest_hash'],
		'expires_at'             => $job['expires_at'],
		'stopped'                => in_array( $job['status'], array( 'cancelled', 'expired' ), true ),
	);
}

/**
 * Return the initial disk-orphan reporting counters.
 *
 * @return array
 */
function yotm_initial_orphan_summary() {
	return array(
		'found'                   => array(),
		'delete'                  => array(),
		'kept_match'              => array(),
		'total_files'             => 0,
		'skipped_original'        => 0,
		'skipped_original_sample' => array(),
		'unmapped'                => 0,
		'unmapped_sample'         => array(),
		'unmapped_skipped'        => 0,
		'unverified_sidecars'     => 0,
		'ambiguous_siblings'      => 0,
		'protected_sources'       => 0,
		'source_errors'           => 0,
	);
}

/**
 * Add or merge one normalized path into the candidate map.
 *
 * @param array  $candidates Candidate map, passed by reference.
 * @param string $path Candidate filesystem path.
 * @param array  $args Candidate metadata.
 */
function yotm_add_prune_candidate( &$candidates, $path, $args = array() ) {
	$path  = yotm_normalize_filesystem_path( $path );
	$proof = array(
		'attachment_id' => absint( $args['attachment_id'] ?? 0 ),
		'size'          => sanitize_key( $args['size'] ?? '' ),
		'filename'      => wp_basename( $path ),
		'mime'          => sanitize_mime_type( $args['mime'] ?? '' ),
		'selection'     => sanitize_key( $args['selection'] ?? '' ),
	);

	if ( isset( $candidates[ $path ] ) ) {
		if ( ! empty( $args['remove_metadata'] ) ) {
			$candidates[ $path ]['remove_metadata'] = 1;
		}

		if ( empty( $candidates[ $path ]['attachment_id'] ) && ! empty( $args['attachment_id'] ) ) {
			$candidates[ $path ]['attachment_id'] = absint( $args['attachment_id'] );
		}

		if ( empty( $candidates[ $path ]['size'] ) && ! empty( $args['size'] ) ) {
			$candidates[ $path ]['size'] = sanitize_key( $args['size'] );
		}

		if ( ! empty( $args['attachment_id'] ) && ! empty( $args['size'] ) && ! empty( $args['remove_metadata'] ) ) {
			$candidates[ $path ]['metadata_refs'][] = array(
				'attachment_id' => absint( $args['attachment_id'] ),
				'size'          => sanitize_key( $args['size'] ),
				'filename'      => wp_basename( $path ),
			);
		}
		$candidates[ $path ]['ownership_evidence'][] = $proof;

		return;
	}//end if

	$candidates[ $path ] = array(
		'path'               => $path,
		'attachment_id'      => isset( $args['attachment_id'] ) ? absint( $args['attachment_id'] ) : 0,
		'size'               => isset( $args['size'] ) ? sanitize_key( $args['size'] ) : '',
		'source'             => isset( $args['source'] ) ? sanitize_key( $args['source'] ) : 'metadata',
		'ownership_schema'   => 'generated_file_v1',
		'ownership'          => 'metadata_size',
		'ownership_evidence' => array( $proof ),
		'remove_metadata'    => ! empty( $args['remove_metadata'] ) ? 1 : 0,
		'metadata_refs'      => ! empty( $args['attachment_id'] ) && ! empty( $args['size'] ) && ! empty( $args['remove_metadata'] )
			? array(
				array(
					'attachment_id' => absint( $args['attachment_id'] ),
					'size'          => sanitize_key( $args['size'] ),
					'filename'      => wp_basename( $path ),
				),
			)
			: array(),
	);
}

/**
 * Collect metadata-backed prune candidates for a bounded attachment set.
 *
 * @param int[]           $ids Attachment IDs.
 * @param string|string[] $scan_bases Validated scan roots.
 * @param string[]        $keep Registered sizes to keep.
 * @param string[]        $to_remove Registered sizes to remove.
 * @param array           $sizes Registered size definitions.
 * @param bool            $discover_orphans Whether legacy metadata sizes are included.
 * @param array           $candidates Candidate map, passed by reference.
 * @param array           $orphan_summary Orphan counters, passed by reference.
 */
function yotm_collect_metadata_prune_candidates_for_ids( $ids, $scan_bases, $keep, $to_remove, $sizes, $discover_orphans, &$candidates, &$orphan_summary ) {
	$scan_bases = (array) $scan_bases;
	$keep_dims  = yotm_keep_dims_from_sizes( $keep, $sizes );
	$delete_map = array();
	$raw_batch  = yotm_media_reference_raw_postmeta_rows_batch( $ids, array( '_wp_attached_file', '_wp_attachment_metadata' ) );
	if ( is_wp_error( $raw_batch ) ) {
		$orphan_summary['source_errors'] = (int) ( $orphan_summary['source_errors'] ?? 0 ) + count( (array) $ids );
		return;
	}

	if ( isset( $orphan_summary['delete'] ) && is_array( $orphan_summary['delete'] ) ) {
		foreach ( $orphan_summary['delete'] as $dim ) {
			if ( is_string( $dim ) && '' !== $dim ) {
				$delete_map[ $dim ] = true;
			}
		}
	}

	foreach ( $ids as $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			continue;
		}
		$attached_rows = $raw_batch[ $attachment_id ]['_wp_attached_file'] ?? array();
		$metadata_rows = $raw_batch[ $attachment_id ]['_wp_attachment_metadata'] ?? array();
		if (
			1 !== count( $attached_rows )
			|| 1 !== count( $metadata_rows )
			|| ! is_string( $attached_rows[0]['value'] )
			|| '' === $attached_rows[0]['value']
			|| ! is_array( $metadata_rows[0]['value'] )
		) {
			$orphan_summary['source_errors'] = (int) ( $orphan_summary['source_errors'] ?? 0 ) + 1;
			continue;
		}
		$uploads  = wp_get_upload_dir();
		$base     = (string) ( $uploads['basedir'] ?? '' );
		$raw_file = $attached_rows[0]['value'];
		$file     = preg_match( '#^(?:[A-Za-z]:)?/#', $raw_file ) ? $raw_file : trailingslashit( $base ) . ltrim( $raw_file, '/\\' );
		$file     = yotm_media_source_canonical_path( $file );
		if ( is_wp_error( $file ) ) {
			$orphan_summary['source_errors'] = (int) ( $orphan_summary['source_errors'] ?? 0 ) + 1;
			continue;
		}

		$original_path = yotm_normalize_filesystem_path( $file );

		if ( ! yotm_is_path_inside_any_dir( $original_path, $scan_bases ) ) {
			continue;
		}

		$metadata = $metadata_rows[0]['value'];
		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			continue;
		}

		$metadata_file = ! empty( $metadata['file'] ) && is_string( $metadata['file'] )
			? trailingslashit( $base ) . ltrim( $metadata['file'], '/\\' )
			: $original_path;
		$metadata_file = yotm_media_source_canonical_path( $metadata_file );
		if ( is_wp_error( $metadata_file ) ) {
			$orphan_summary['source_errors'] = (int) ( $orphan_summary['source_errors'] ?? 0 ) + 1;
			continue;
		}
		$upload_dir = trailingslashit( dirname( yotm_normalize_filesystem_path( $metadata_file ) ) );

		foreach ( $metadata['sizes'] as $size_name => $size_data ) {
			if ( ! is_array( $size_data ) ) {
				continue;
			}

			$filename = $size_data['file'] ?? ( $size_data['filename'] ?? '' );
			if ( '' === $filename ) {
				continue;
			}

			$thumb_path = yotm_normalize_filesystem_path( $upload_dir . wp_basename( $filename ) );

			if ( $thumb_path === $original_path || ! yotm_is_path_inside_any_dir( $thumb_path, $scan_bases ) ) {
				continue;
			}

			if ( in_array( $size_name, $keep, true ) ) {
				continue;
			}

			$registered_remove = in_array( $size_name, $to_remove, true );
			$orphan_remove     = false;
			$source            = 'metadata';

			if ( ! $registered_remove && $discover_orphans && ! isset( $sizes[ $size_name ] ) ) {
				$dim = yotm_dimension_from_size_data( $size_data );

				if ( '' !== $dim ) {
					$orphan_summary['found'][ $dim ] = ( $orphan_summary['found'][ $dim ] ?? 0 ) + 1;

					if ( yotm_dimension_matches_keep( $dim, $keep_dims ) ) {
						$orphan_summary['kept_match'][] = $dim;
					} else {
						$delete_map[ $dim ] = true;
						$orphan_remove      = true;
						$source             = 'metadata_orphan';
					}
				}
			}

			if ( ! $registered_remove && ! $orphan_remove ) {
				continue;
			}

			if ( ! is_file( $thumb_path ) ) {
				continue;
			}

			$protected = yotm_media_source_path_is_authoritative( $thumb_path );
			if ( is_wp_error( $protected ) ) {
				$orphan_summary['source_errors'] = (int) ( $orphan_summary['source_errors'] ?? 0 ) + 1;
				continue;
			}
			if ( $protected ) {
				$orphan_summary['protected_sources'] = (int) ( $orphan_summary['protected_sources'] ?? 0 ) + 1;
				continue;
			}

			yotm_add_prune_candidate(
				$candidates,
				$thumb_path,
				array(
					'attachment_id'   => $attachment_id,
					'size'            => (string) $size_name,
					'source'          => $source,
					'remove_metadata' => true,
					'mime'            => (string) ( $size_data['mime-type'] ?? '' ),
					'selection'       => $registered_remove ? 'registered_remove' : 'metadata_orphan',
				)
			);
		}//end foreach
	}//end foreach

	$orphan_summary['delete'] = array_keys( $delete_map );

	if ( isset( $orphan_summary['kept_match'] ) && is_array( $orphan_summary['kept_match'] ) ) {
		$orphan_summary['kept_match'] = array_values( array_unique( $orphan_summary['kept_match'] ) );
	}
}

/**
 * Convert metadata dimensions to a stable width-by-height key.
 *
 * @param array $size_data Attachment size metadata.
 * @return string
 */
function yotm_dimension_from_size_data( $size_data ) {
	$width  = isset( $size_data['width'] ) ? absint( $size_data['width'] ) : 0;
	$height = isset( $size_data['height'] ) ? absint( $size_data['height'] ) : 0;

	if ( $width <= 0 || $height <= 0 ) {
		return '';
	}

	return $width . 'x' . $height;
}

/**
 * Check whether a legacy dimension matches a retained registered size.
 *
 * @param string $dim Width-by-height key.
 * @param array  $keep_dims Dimension lookup maps.
 * @return bool
 */
function yotm_dimension_matches_keep( $dim, $keep_dims ) {
	if ( in_array( $dim, $keep_dims['exact'], true ) ) {
		return true;
	}

	if ( preg_match( '/^(\d+)x(\d+)$/', $dim, $matches ) ) {
		return in_array( (int) $matches[1], array_map( 'intval', $keep_dims['width_any'] ), true );
	}

	return false;
}

/**
 * Find the primary thumbnail and safe extension variants beside it.
 *
 * @param string $thumb_path Primary thumbnail path.
 * @return string[]
 */
function yotm_get_thumbnail_file_variants( $thumb_path ) {
	$thumb_path = yotm_normalize_filesystem_path( $thumb_path );

	return is_file( $thumb_path ) ? array( $thumb_path ) : array();
}
