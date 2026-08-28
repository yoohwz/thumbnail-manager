<?php
/**
 * Persistent thumbnail regeneration jobs.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_yotm_regenerate_prepare', 'yotm_regenerate_prepare' );
add_action( 'wp_ajax_yotm_regenerate_batch', 'yotm_regenerate_batch' );

/**
 * Prepare a persistent regeneration job.
 */
function yotm_regenerate_prepare() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$scope = sanitize_text_field( wp_unslash( $_POST['scope'] ?? 'all' ) );

	if ( ! in_array( $scope, array( 'all', 'year', 'subpath', 'ids' ), true ) ) {
		$scope = 'all';
	}

	$subpath      = isset( $_POST['subpath'] ) ? yotm_clean_upload_subpath( sanitize_text_field( wp_unslash( $_POST['subpath'] ) ) ) : '';
	$ids_raw      = isset( $_POST['attachment_ids'] ) ? sanitize_textarea_field( wp_unslash( $_POST['attachment_ids'] ) ) : '';
	$force_all    = ! empty( $_POST['force_all'] );
	$only_missing = ! empty( $_POST['only_missing'] ) && ! $force_all;
	$query_args   = array();
	$scope_label  = __( 'All media', 'thumbnail-manager' );
	$cursor_mode  = true;
	$selector     = 'attachment_id_v1';
	$ids          = array();

	if ( 'year' === $scope ) {
		$query_args['date_query'] = array( array( 'year' => (int) current_time( 'Y' ) ) );
		$scope_label              = __( 'Current year', 'thumbnail-manager' );
	} elseif ( 'subpath' === $scope ) {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( yotm_normalize_filesystem_path( $uploads['basedir'] ) );

		if ( '' === $subpath ) {
			wp_send_json_error( array( 'msg' => __( 'Please choose an uploads folder.', 'thumbnail-manager' ) ), 400 );
		}

		$target_dir = yotm_resolve_upload_scan_base( $base, $subpath );
		if ( is_wp_error( $target_dir ) ) {
			wp_send_json_error( array( 'msg' => $target_dir->get_error_message() ), 400 );
		}

		$query_args['meta_query'] = array(
			array(
				'key'     => '_wp_attached_file',
				'value'   => '^' . $subpath . '/',
				'compare' => 'REGEXP',
			),
		);
		$selector                 = 'attached_meta_v2';
		$scope_label              = yotm_uploads_relative_label( $base, $target_dir );
	} elseif ( 'ids' === $scope ) {
		preg_match_all( '/\d+/', $ids_raw, $matches );
		$raw_ids = isset( $matches[0] ) ? array_map( 'absint', $matches[0] ) : array();
		$raw_ids = array_values( array_filter( array_unique( $raw_ids ) ) );

		foreach ( $raw_ids as $attachment_id ) {
			$post = get_post( $attachment_id );
			$mime = get_post_mime_type( $attachment_id );

			if ( $post && 'attachment' === $post->post_type && is_string( $mime ) && 0 === strpos( $mime, 'image/' ) ) {
				$ids[] = $attachment_id;
			}
		}

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'msg' => __( 'Please enter at least one valid image attachment ID.', 'thumbnail-manager' ) ), 400 );
		}

		$cursor_mode = false;
		$scope_label = __( 'Specific attachment IDs', 'thumbnail-manager' );
	}//end if

	$selection_meta_max = 'attached_meta_v2' === $selector ? yotm_get_max_attached_file_meta_id() : 0;
	if ( is_wp_error( $selection_meta_max ) ) {
		wp_send_json_error( array( 'msg' => $selection_meta_max->get_error_message() ), 503 );
	}
	$total         = 'attached_meta_v2' === $selector ? 0 : ( $cursor_mode ? yotm_count_image_attachments( $query_args ) : count( $ids ) );
	$max_id        = 'attached_meta_v2' === $selector ? 0 : ( $cursor_mode ? yotm_get_max_image_attachment_id( $query_args ) : 0 );
	$initial_phase = $force_all ? 'source_index' : ( 'attached_meta_v2' === $selector ? 'selection' : 'regenerate' );
	$job           = yotm_job_create(
		'regenerate',
		array(
			'only_missing'             => $only_missing ? 1 : 0,
			'force_all'                => $force_all ? 1 : 0,
			'scan_phase'               => $initial_phase,
			'source_index_initialized' => 0,
			'source_index_complete'    => 0,
			'source_index_cursor'      => 0,
			'source_index_max_id'      => 0,
			'scope'                    => $scope,
			'scope_label'              => $scope_label,
			'subpath'                  => $subpath,
			'selector'                 => $selector,
			'selection_done'           => 'attached_meta_v2' === $selector ? 0 : 1,
			'selection_meta_after'     => 0,
			'selection_meta_max'       => absint( $selection_meta_max ),
			'selection_scanned'        => 0,
			'selection_matched'        => 0,
			'selection_subpaths'       => 'attached_meta_v2' === $selector ? array( $subpath ) : array(),
			'cursor_mode'              => $cursor_mode ? 1 : 0,
			'discovery_done'           => 'attached_meta_v2' === $selector ? 0 : ( $cursor_mode ? 0 : 1 ),
			'query_args'               => $query_args,
		),
		array(
			'status'       => 'running',
			'phase'        => $initial_phase,
			'counter_mode' => $force_all ? 'item_v3' : 'item_v2',
			'total'        => $total,
			'max_id'       => $max_id,
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

	if ( ! $cursor_mode ) {
		foreach ( $ids as $attachment_id ) {
			$item_key = hash( 'sha256', 'attachment:' . $attachment_id );
			$inserted = yotm_job_add_item(
				$job['id'],
				$item_key,
				array( 'attachment_id' => $attachment_id )
			);
			if ( ! $inserted && ! yotm_job_item_exists( $job['id'], $item_key ) ) {
				yotm_job_delete( $job['id'] );
				wp_send_json_error( array( 'msg' => yotm_job_storage_error()->get_error_message() ), 500 );
			}
		}
	}

	wp_send_json_success( yotm_build_regenerate_response( yotm_job_get_by_id( $job['id'] ), false ) );
}

/**
 * Process a persistent regeneration job in batches.
 */
function yotm_regenerate_batch() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	$batch = isset( $_POST['batch'] ) ? absint( wp_unslash( $_POST['batch'] ) ) : 20;
	$batch = max( 1, min( 100, $batch ) );
	$job   = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		wp_send_json_error( array( 'msg' => $job->get_error_message() ), 400 );
	}

	if ( 'regenerate' !== $job['type'] ) {
		wp_send_json_error( array( 'msg' => __( 'Invalid regeneration job.', 'thumbnail-manager' ) ), 400 );
	}

	if ( 'completed' === $job['status'] ) {
		wp_send_json_success( yotm_build_regenerate_response( $job, true ) );
	}

	if ( 'running' !== $job['status'] ) {
		wp_send_json_error( array( 'msg' => __( 'This regeneration job is not runnable.', 'thumbnail-manager' ) ), 409 );
	}

	$worker = yotm_job_acquire_worker( $job['id'], array( 'running' ), array( 'source_index', 'selection', 'regenerate' ) );
	if ( is_wp_error( $worker ) ) {
		if ( 'yotm_job_worker_busy' !== $worker->get_error_code() ) {
			wp_send_json_error( array( 'msg' => $worker->get_error_message() ), 503 );
		}

		$data                       = $worker->get_error_data();
		$current                    = is_array( $data ) && is_array( $data['job'] ?? null ) ? $data['job'] : $job;
		$response                   = yotm_build_regenerate_response( $current, ( $current['status'] ?? '' ) === 'completed' );
		$response['retry_after_ms'] = 'running' === ( $current['status'] ?? '' ) ? 250 : 0;
		wp_send_json_success( $response );
	}

	$job = yotm_job_get_by_id( $job['id'] );
	if ( 'source_index' === $job['phase'] ) {
		$indexed = yotm_prune_source_index_batch( $job, $batch, $worker );
		if ( is_wp_error( $indexed ) ) {
			wp_send_json_error( array( 'msg' => $indexed->get_error_message() ), 503 );
		}
		$response                   = yotm_build_regenerate_response( $indexed['job'], false );
		$response['retry_after_ms'] = 50;
		wp_send_json_success( $response );
	}
	if ( 'selection' === $job['phase'] ) {
		$selected = yotm_regenerate_select_folder_batch( $job, $worker, $batch );
		if ( is_wp_error( $selected ) ) {
			wp_send_json_error( array( 'msg' => $selected->get_error_message() ), 503 );
		}
		$response                   = yotm_build_regenerate_response( $selected, false );
		$response['retry_after_ms'] = 50;
		wp_send_json_success( $response );
	}
	if ( in_array( $job['counter_mode'], array( 'item_v2', 'item_v3' ), true ) ) {
		wp_send_json_success( yotm_regenerate_item_batch( $job, $worker, $batch ) );
	}

	wp_send_json_success( yotm_regenerate_legacy_batch( $job, $worker, $batch ) );
}

/**
 * Materialize one bounded folder-selector page into regeneration items.
 *
 * @param array $job Regeneration job.
 * @param array $worker Current worker ownership.
 * @param int   $batch Batch size.
 * @return array|WP_Error
 */
function yotm_regenerate_select_folder_batch( $job, $worker, $batch ) {
	$payload = $job['payload'];
	if ( 'attached_meta_v2' !== ( $payload['selector'] ?? '' ) ) {
		return new WP_Error( 'yotm_regenerate_selector_invalid', __( 'The regeneration folder selector is invalid.', 'thumbnail-manager' ) );
	}

	$rows = yotm_get_attached_file_selector_rows_after(
		absint( $payload['selection_meta_after'] ?? 0 ),
		absint( $payload['selection_meta_max'] ?? 0 ),
		$batch
	);
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}

	if ( empty( $rows ) ) {
		$total                        = yotm_job_count_items( $job['id'] );
		$payload['selection_done']    = 1;
		$payload['discovery_done']    = 1;
		$payload['selection_matched'] = $total;
		$payload['scan_phase']        = 'regenerate';
		if ( ! yotm_job_worker_update(
			$worker,
			array(
				'payload' => $payload,
				'phase'   => 'regenerate',
				'total'   => $total,
			)
		) ) {
			return yotm_job_storage_error();
		}
		return yotm_job_get_by_id( $job['id'] );
	}

	$subpaths = (array) ( $payload['selection_subpaths'] ?? array() );
	$inserted = 0;
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
		if ( is_wp_error( $authorized ) ) {
			continue;
		}
		$item_key = hash( 'sha256', 'attachment:' . absint( $row['attachment_id'] ) );
		if ( yotm_job_add_item(
			$job['id'],
			$item_key,
			array(
				'attachment_id'     => absint( $row['attachment_id'] ),
				'selection_meta_id' => absint( $row['meta_id'] ),
			)
		) ) {
			++$inserted;
		} elseif ( ! yotm_job_item_exists( $job['id'], $item_key ) ) {
			return yotm_job_storage_error();
		}
	}//end foreach

	$payload['selection_meta_after'] = max( array_map( 'absint', wp_list_pluck( $rows, 'meta_id' ) ) );
	$payload['selection_scanned']    = (int) ( $payload['selection_scanned'] ?? 0 ) + count( $rows );
	$payload['selection_matched']    = (int) ( $payload['selection_matched'] ?? 0 ) + $inserted;
	if ( ! yotm_job_worker_update( $worker, array( 'payload' => $payload ) ) ) {
		return yotm_job_storage_error();
	}

	return yotm_job_get_by_id( $job['id'] );
}

/**
 * Process an item-authoritative regeneration batch.
 *
 * @param array $job Job row.
 * @param array $worker Worker ownership data.
 * @param int   $batch Batch size.
 * @return array
 */
function yotm_regenerate_item_batch( $job, $worker, $batch ) {
	$payload     = $job['payload'];
	$cursor_mode = ! empty( $payload['cursor_mode'] );
	$item_v3     = 'item_v3' === ( $job['counter_mode'] ?? '' );
	$counters    = $item_v3
		? array( 'remaining' => yotm_job_has_remaining_items( $job['id'] ) ? 1 : 0 )
		: yotm_job_item_counters( $job['id'] );

	if ( $cursor_mode && 0 === $counters['remaining'] && empty( $payload['discovery_done'] ) ) {
		$ids = yotm_get_image_attachment_ids_after(
			is_array( $payload['query_args'] ?? null ) ? $payload['query_args'] : array(),
			$job['cursor'],
			$batch,
			$job['max_id']
		);

		if ( empty( $ids ) ) {
			$payload['discovery_done'] = 1;
			yotm_job_worker_update( $worker, array( 'payload' => $payload ) );
		} else {
			$materialized = true;
			foreach ( $ids as $attachment_id ) {
				$item_key = hash( 'sha256', 'attachment:' . absint( $attachment_id ) );
				$inserted = yotm_job_add_item(
					$job['id'],
					$item_key,
					array( 'attachment_id' => absint( $attachment_id ) )
				);
				if ( ! $inserted && ! yotm_job_item_exists( $job['id'], $item_key ) ) {
					$materialized = false;
					break;
				}
			}

			if ( ! $materialized ) {
				return array_merge(
					yotm_build_regenerate_response( yotm_job_get_by_id( $job['id'] ), false ),
					array( 'retry_after_ms' => 250 )
				);
			}

			yotm_job_worker_update( $worker, array( 'cursor' => max( array_map( 'absint', $ids ) ) ) );
		}//end if

		$job     = yotm_job_get_by_id( $job['id'] );
		$payload = $job['payload'];
	}//end if

	$items = yotm_job_claim_items( $worker, $batch );
	if ( is_wp_error( $items ) ) {
		return array_merge(
			yotm_build_regenerate_response( yotm_job_get_by_id( $job['id'] ), false ),
			array( 'retry_after_ms' => 250 )
		);
	}

	foreach ( $items as $item ) {
		if ( ! empty( $job['payload']['recovery_only'] ) && empty( $item['payload']['regeneration_journal'] ) ) {
			yotm_job_release_item_claim( $item );
			continue;
		}
		if ( ! yotm_job_refresh_worker( $worker ) || ! yotm_job_refresh_item_claim( $item ) ) {
			break;
		}

		$attachment_id = absint( $item['payload']['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) {
			$item_v3
				? yotm_job_finish_item_v3( $item, $worker, 'failed', __( 'Attachment ID is missing.', 'thumbnail-manager' ) )
				: yotm_job_finish_item( $item, 'failed', __( 'Attachment ID is missing.', 'thumbnail-manager' ) );
			continue;
		}
		if ( 'attached_meta_v2' === ( $payload['selector'] ?? '' ) ) {
			$authorized = yotm_authorize_attached_file_selector_scope(
				$attachment_id,
				absint( $item['payload']['selection_meta_id'] ?? 0 ),
				absint( $payload['selection_meta_max'] ?? 0 ),
				(array) ( $payload['selection_subpaths'] ?? array() )
			);
			if ( is_wp_error( $authorized ) ) {
				$item_v3
					? yotm_job_finish_item_v3( $item, $worker, 'skipped', $authorized->get_error_message() )
					: yotm_job_finish_item( $item, 'skipped', $authorized->get_error_message() );
				continue;
			}
		}

		$result = ! empty( $payload['force_all'] )
			? yotm_regenerate_force_attachment( $attachment_id, $item, $worker )
			: yotm_regenerate_attachment( $attachment_id, ! empty( $payload['only_missing'] ), false );
		if ( 'regenerated' === $result['status'] ) {
			$item_v3 ? yotm_job_finish_item_v3( $item, $worker, 'done' ) : yotm_job_finish_item( $item, 'done' );
		} elseif ( 'skipped' === $result['status'] ) {
			$item_v3
				? yotm_job_finish_item_v3( $item, $worker, 'skipped', (string) $result['message'] )
				: yotm_job_finish_item( $item, 'skipped', (string) $result['message'] );
		} elseif ( 'retry' === $result['status'] ) {
			yotm_job_release_item_claim( $item );
		} else {
			$item_v3
				? yotm_job_finish_item_v3( $item, $worker, 'failed', (string) $result['message'] )
				: yotm_job_finish_item( $item, 'failed', (string) $result['message'] );
		}
	}//end foreach

	$job = $item_v3 ? yotm_job_get_by_id( $job['id'] ) : yotm_job_sync_item_counters( $job['id'] );
	if ( $item_v3 && ! empty( $job['payload']['recovery_only'] ) && ! yotm_job_has_recovery_journals( $job['id'] ) ) {
		yotm_job_worker_update(
			$worker,
			array(
				'status'     => 'expired',
				'phase'      => 'expired',
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_AUDIT_RETENTION_SECONDS ),
			)
		);
		return yotm_build_regenerate_response( yotm_job_get_by_id( $job['id'] ), false );
	}
	$remaining = $item_v3 ? yotm_job_has_remaining_items( $job['id'] ) : 0 !== yotm_job_item_counters( $job['id'] )['remaining'];
	$done      = ! empty( $job['payload']['discovery_done'] ) && ! $remaining;

	if ( $done && 'running' === $job['status'] ) {
		if ( $item_v3 ) {
			$audit = yotm_job_item_counters( $job['id'] );
			if ( (int) $job['processed'] !== $audit['processed'] || (int) $job['succeeded'] !== $audit['succeeded'] || (int) $job['failed'] !== $audit['failed'] ) {
				return array_merge( yotm_build_regenerate_response( $job, false ), array( 'retry_after_ms' => 250 ) );
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
	}

	$current = yotm_job_get_by_id( $job['id'] );

	return yotm_build_regenerate_response( $current, 'completed' === $current['status'] );
}

/**
 * Resume one pre-migration regeneration job without mixing counter sources.
 *
 * @param array $job Job row.
 * @param array $worker Worker ownership data.
 * @param int   $batch Batch size.
 * @return array
 */
function yotm_regenerate_legacy_batch( $job, $worker, $batch ) {
	$payload          = $job['payload'];
	$cursor_mode      = ! empty( $payload['cursor_mode'] );
	$items            = array();
	$cursor_exhausted = false;

	if ( $cursor_mode ) {
		$ids = yotm_get_image_attachment_ids_after(
			is_array( $payload['query_args'] ?? null ) ? $payload['query_args'] : array(),
			$job['cursor'],
			$batch,
			$job['max_id']
		);
		foreach ( $ids as $attachment_id ) {
			$items[] = array(
				'id'      => 0,
				'payload' => array( 'attachment_id' => $attachment_id ),
			);
		}
		$cursor_exhausted = empty( $items );
	} else {
		$items = yotm_job_claim_items( $worker, $batch );
		if ( is_wp_error( $items ) ) {
			return array_merge( yotm_build_regenerate_response( $job, false ), array( 'retry_after_ms' => 250 ) );
		}
		$ids = array_map(
			static function ( $item ) {
				return absint( $item['payload']['attachment_id'] ?? 0 );
			},
			$items
		);
	}//end if

	$processed_now   = 0;
	$regenerated_now = 0;
	$failed_now      = 0;
	$last_id         = $job['cursor'];

	foreach ( $items as $item ) {
		if ( ! yotm_job_refresh_worker( $worker ) || ( ! empty( $item['id'] ) && ! yotm_job_refresh_item_claim( $item ) ) ) {
			break;
		}

		$attachment_id = absint( $item['payload']['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) {
			continue;
		}

		$last_id = max( $last_id, $attachment_id );
		$result  = ! empty( $payload['force_all'] )
			? yotm_regenerate_failure( __( 'This legacy Force job predates transactional regeneration. Start a new Force job.', 'thumbnail-manager' ) )
			: yotm_regenerate_attachment( $attachment_id, ! empty( $payload['only_missing'] ), false );
		++$processed_now;

		if ( 'regenerated' === $result['status'] ) {
			++$regenerated_now;
			if ( ! empty( $item['id'] ) ) {
				yotm_job_finish_item( $item, 'done' );
			}
		} elseif ( 'skipped' === $result['status'] ) {
			if ( ! empty( $item['id'] ) ) {
				yotm_job_finish_item( $item, 'skipped', (string) $result['message'] );
			}
		} else {
			++$failed_now;
			if ( ! empty( $item['id'] ) ) {
				yotm_job_finish_item( $item, 'failed', (string) $result['message'] );
			} else {
				$failure_key = hash( 'sha256', 'regenerate-failure:' . $attachment_id );
				yotm_job_add_item( $job['id'], $failure_key, array( 'attachment_id' => $attachment_id ), 'failed' );
				$failure_item = yotm_job_get_item_by_key( $job['id'], $failure_key );
				if ( $failure_item ) {
					yotm_job_update_item( $failure_item['id'], 'failed', (string) $result['message'] );
				}
			}
		}//end if
	}//end foreach

	$fields = array(
		'processed' => $job['processed'] + $processed_now,
		'succeeded' => $job['succeeded'] + $regenerated_now,
		'failed'    => $job['failed'] + $failed_now,
	);
	if ( $cursor_mode && $processed_now > 0 ) {
		$fields['cursor'] = $last_id;
	}
	if ( $processed_now > 0 ) {
		yotm_job_legacy_worker_update( $worker, $fields );
	}

	$remaining = $cursor_mode
		? ( $cursor_exhausted ? 0 : 1 )
		: yotm_job_count_items( $job['id'], 'queued' ) + yotm_job_count_items( $job['id'], 'processing' );
	$current   = yotm_job_get_by_id( $job['id'] );
	$done      = 0 === $remaining;

	if ( $done && 'running' === $current['status'] ) {
		yotm_job_worker_update(
			$worker,
			array(
				'status'     => 'completed',
				'phase'      => 'completed',
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_AUDIT_RETENTION_SECONDS ),
			)
		);
	}

	$current = yotm_job_get_by_id( $job['id'] );

	return yotm_build_regenerate_response( $current, 'completed' === $current['status'] );
}

/**
 * Regenerate one attachment with an original-image source for force mode.
 *
 * @param int  $attachment_id Attachment ID.
 * @param bool $only_missing Only create missing sizes.
 * @param bool $force_all Force full metadata regeneration.
 * @return array{status:string,message:string}
 */
function yotm_regenerate_attachment( $attachment_id, $only_missing, $force_all ) {
	if ( $force_all ) {
		return yotm_regenerate_failure( __( 'Force regeneration requires a persistent claimed job item.', 'thumbnail-manager' ) );
	}
	$file = get_attached_file( $attachment_id );
	$mime = get_post_mime_type( $attachment_id );

	if ( ! $file || ! file_exists( $file ) ) {
		return array(
			'status'  => 'failed',
			'message' => __( 'Attached image file is missing.', 'thumbnail-manager' ),
		);
	}

	if ( ! is_string( $mime ) || 0 !== strpos( $mime, 'image/' ) || 'image/svg+xml' === $mime ) {
		return array(
			'status'  => 'skipped',
			'message' => __( 'Attachment is not a raster image.', 'thumbnail-manager' ),
		);
	}

	if ( $only_missing && ! $force_all && function_exists( 'wp_get_missing_image_subsizes' ) && function_exists( 'wp_update_image_subsizes' ) ) {
		$missing = wp_get_missing_image_subsizes( $attachment_id );

		if ( empty( $missing ) ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'No image sizes are missing.', 'thumbnail-manager' ),
			);
		}

		$result = wp_update_image_subsizes( $attachment_id );

		return is_wp_error( $result )
			? array(
				'status'  => 'failed',
				'message' => $result->get_error_message(),
			)
			: array(
				'status'  => 'regenerated',
				'message' => '',
			);
	}//end if

	$old_metadata = wp_get_attachment_metadata( $attachment_id );
	$source_file  = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $attachment_id ) : $file;

	if ( ! is_string( $source_file ) || ! file_exists( $source_file ) ) {
		$source_file = $file;
	}

	$metadata = wp_generate_attachment_metadata( $attachment_id, $source_file );

	if ( is_wp_error( $metadata ) || empty( $metadata ) || ! is_array( $metadata ) ) {
		$message = is_wp_error( $metadata ) ? $metadata->get_error_message() : __( 'WordPress returned empty image metadata.', 'thumbnail-manager' );

		return array(
			'status'  => 'failed',
			'message' => $message,
		);
	}

	if ( is_array( $old_metadata ) ) {
		$metadata = array_merge( $old_metadata, $metadata );
	}

	if ( false === wp_update_attachment_metadata( $attachment_id, $metadata ) ) {
		return array(
			'status'  => 'failed',
			'message' => __( 'Could not update attachment metadata.', 'thumbnail-manager' ),
		);
	}

	return array(
		'status'  => 'regenerated',
		'message' => '',
	);
}

/**
 * Build normalized generated-file paths from attachment metadata.
 *
 * @param string $attached_file Current attached file path.
 * @param array  $metadata Attachment metadata.
 * @return array<string,string>
 */
function yotm_regenerate_metadata_file_map( $attached_file, $metadata ) {
	$paths = array();

	if ( ! is_string( $attached_file ) || '' === $attached_file || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
		return $paths;
	}

	$directory = trailingslashit( dirname( yotm_normalize_filesystem_path( $attached_file ) ) );

	foreach ( $metadata['sizes'] as $size_data ) {
		if ( ! is_array( $size_data ) ) {
			continue;
		}

		$filename = $size_data['file'] ?? ( $size_data['filename'] ?? '' );
		if ( ! is_string( $filename ) || '' === $filename ) {
			continue;
		}

		$path           = yotm_normalize_filesystem_path( $directory . wp_basename( $filename ) );
		$paths[ $path ] = $path;
	}

	return $paths;
}

/**
 * Delete stale generated files after replacement metadata has been saved.
 *
 * Files still referenced by the new metadata and all original/attached paths
 * remain protected.
 *
 * @param string $attached_file Current attached file path.
 * @param string $source_file Original source used to regenerate metadata.
 * @param array  $old_metadata Previous attachment metadata.
 * @param array  $new_metadata Replacement attachment metadata.
 */
function yotm_cleanup_obsolete_generated_files( $attached_file, $source_file, $old_metadata, $new_metadata ) {
	$uploads = wp_get_upload_dir();
	$base    = (string) ( $uploads['basedir'] ?? '' );

	if ( '' === $base ) {
		return;
	}

	$old_paths = yotm_regenerate_metadata_file_map( $attached_file, $old_metadata );
	$new_paths = yotm_regenerate_metadata_file_map( $attached_file, $new_metadata );
	$protected = array();

	foreach ( array( $attached_file, $source_file ) as $path ) {
		if ( is_string( $path ) && '' !== $path ) {
			$protected[ yotm_normalize_filesystem_path( $path ) ] = true;
		}
	}

	foreach ( array_diff_key( $old_paths, $new_paths ) as $path ) {
		if ( isset( $protected[ $path ] ) || ! yotm_is_path_inside_dir( $path, $base ) || ! is_file( $path ) ) {
			continue;
		}

		wp_delete_file( $path );
	}
}

/**
 * Build a regeneration response from a persisted job.
 *
 * @param array $job Job row.
 * @param bool  $done Whether complete.
 * @return array
 */
function yotm_build_regenerate_response( $job, $done ) {
	$payload     = $job['payload'];
	$total       = (int) $job['total'];
	$processed   = (int) $job['processed'];
	$failed      = (int) $job['failed'];
	$skipped     = max( 0, $processed - (int) $job['succeeded'] - $failed );
	$selector_v2 = 'attached_meta_v2' === ( $payload['selector'] ?? '' );
	$total_known = ! $selector_v2 || ! empty( $payload['selection_done'] );
	$percent     = $done ? 100 : ( $total_known ? ( $total > 0 ? min( 99.9, ( $processed / $total ) * 100 ) : 100 ) : null );

	return array(
		'token'             => $job['token'],
		'status'            => $job['status'],
		'phase'             => $job['phase'],
		'processed'         => $processed,
		'total'             => $total,
		'regenerated'       => (int) $job['succeeded'],
		'skipped'           => $skipped,
		'failed'            => $failed,
		'remaining'         => $total_known ? max( 0, $total - $processed ) : null,
		'percent'           => $percent,
		'total_known'       => $total_known,
		'selection_done'    => ! empty( $payload['selection_done'] ),
		'selection_scanned' => (int) ( $payload['selection_scanned'] ?? 0 ),
		'done'              => (bool) $done,
		'stopped'           => in_array( $job['status'], array( 'cancelled', 'expired' ), true ),
		'scope'             => (string) ( $payload['scope'] ?? 'all' ),
		'scope_label'       => (string) ( $payload['scope_label'] ?? __( 'All media', 'thumbnail-manager' ) ),
		'only_missing'      => ! empty( $payload['only_missing'] ) ? 1 : 0,
		'force_all'         => ! empty( $payload['force_all'] ) ? 1 : 0,
		'errors'            => yotm_job_get_error_sample( $job['id'] ),
	);
}
