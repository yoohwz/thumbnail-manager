<?php
/**
 * Regeneration application orchestration.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Force regeneration coordinates exact media promotion and persistent recovery checkpoints.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize,WordPress.WP.GetMetaSingle.Missing

/**
 * Build a successful transport-neutral application outcome.
 *
 * @param mixed $data Response data.
 * @param int   $status HTTP status.
 * @return array{success:bool,data:mixed,status:int}
 */
function yotm_regenerate_application_success( $data, $status = 200 ) {
	return array(
		'success' => true,
		'data'    => $data,
		'status'  => absint( $status ),
	);
}

/**
 * Build a failed transport-neutral application outcome.
 *
 * @param mixed $data Response data.
 * @param int   $status HTTP status.
 * @return array{success:bool,data:mixed,status:int}
 */
function yotm_regenerate_application_error( $data, $status = 400 ) {
	return array(
		'success' => false,
		'data'    => $data,
		'status'  => absint( $status ),
	);
}

/**
 * Prepare a persistent regeneration job without transport concerns.
 *
 * @param string $scope Requested scope.
 * @param string $subpath Upload subpath.
 * @param string $ids_raw Raw attachment IDs.
 * @param bool   $force_all Whether Force mode is enabled.
 * @param bool   $only_missing Whether only missing sizes are requested.
 * @return array{success:bool,data:mixed,status:int}
 */
function yotm_regenerate_prepare_application( $scope, $subpath, $ids_raw, $force_all, $only_missing ) {
	$scope = sanitize_text_field( (string) $scope );

	if ( ! in_array( $scope, array( 'all', 'year', 'subpath', 'ids' ), true ) ) {
		$scope = 'all';
	}

	$subpath      = yotm_clean_upload_subpath( (string) $subpath );
	$ids_raw      = sanitize_textarea_field( (string) $ids_raw );
	$force_all    = (bool) $force_all;
	$only_missing = (bool) $only_missing && ! $force_all;
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
			return yotm_regenerate_application_error( array( 'msg' => __( 'Please choose an uploads folder.', 'thumbnail-manager' ) ), 400 );
		}

		$target_dir = yotm_resolve_upload_scan_base( $base, $subpath );
		if ( is_wp_error( $target_dir ) ) {
			return yotm_regenerate_application_error( array( 'msg' => $target_dir->get_error_message() ), 400 );
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Legacy query fallback; new folder-scoped jobs use the bounded raw-meta selector.
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
			return yotm_regenerate_application_error( array( 'msg' => __( 'Please enter at least one valid image attachment ID.', 'thumbnail-manager' ) ), 400 );
		}

		$cursor_mode = false;
		$scope_label = __( 'Specific attachment IDs', 'thumbnail-manager' );
	}//end if

	$selection_meta_max = 'attached_meta_v2' === $selector ? yotm_get_max_attached_file_meta_id() : 0;
	if ( is_wp_error( $selection_meta_max ) ) {
		return yotm_regenerate_application_error( array( 'msg' => $selection_meta_max->get_error_message() ), 503 );
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
		return yotm_regenerate_application_error(
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
				return yotm_regenerate_application_error( array( 'msg' => yotm_job_storage_error()->get_error_message() ), 500 );
			}
		}
	}

	return yotm_regenerate_application_success( yotm_build_regenerate_response( yotm_job_get_by_id( $job['id'] ), false ) );
}

/**
 * Process a persistent regeneration job batch without transport concerns.
 *
 * @param string $token Job token.
 * @param int    $batch Batch size.
 * @return array{success:bool,data:mixed,status:int}
 */
function yotm_regenerate_batch_application( $token, $batch ) {
	$token = sanitize_text_field( (string) $token );
	$batch = max( 1, min( 100, absint( $batch ) ) );
	$job   = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		return yotm_regenerate_application_error( array( 'msg' => $job->get_error_message() ), 400 );
	}

	if ( 'regenerate' !== $job['type'] ) {
		return yotm_regenerate_application_error( array( 'msg' => __( 'Invalid regeneration job.', 'thumbnail-manager' ) ), 400 );
	}

	if ( 'completed' === $job['status'] ) {
		return yotm_regenerate_application_success( yotm_build_regenerate_response( $job, true ) );
	}

	if ( 'running' !== $job['status'] ) {
		return yotm_regenerate_application_error( array( 'msg' => __( 'This regeneration job is not runnable.', 'thumbnail-manager' ) ), 409 );
	}

	$worker = yotm_job_acquire_worker( $job['id'], array( 'running' ), array( 'source_index', 'selection', 'regenerate' ) );
	if ( is_wp_error( $worker ) ) {
		if ( 'yotm_job_worker_busy' !== $worker->get_error_code() ) {
			return yotm_regenerate_application_error( array( 'msg' => $worker->get_error_message() ), 503 );
		}

		$data                       = $worker->get_error_data();
		$current                    = is_array( $data ) && is_array( $data['job'] ?? null ) ? $data['job'] : $job;
		$response                   = yotm_build_regenerate_response( $current, ( $current['status'] ?? '' ) === 'completed' );
		$response['retry_after_ms'] = 'running' === ( $current['status'] ?? '' ) ? 250 : 0;
		return yotm_regenerate_application_success( $response );
	}

	$job = yotm_job_get_by_id( $job['id'] );
	if ( 'source_index' === $job['phase'] ) {
		$indexed = yotm_application_media_source_index_batch( $job, $batch, $worker );
		if ( is_wp_error( $indexed ) ) {
			return yotm_regenerate_application_error( array( 'msg' => $indexed->get_error_message() ), 503 );
		}
		$response                   = yotm_build_regenerate_response( $indexed['job'], false );
		$response['retry_after_ms'] = 50;
		return yotm_regenerate_application_success( $response );
	}
	if ( 'selection' === $job['phase'] ) {
		$selected = yotm_regenerate_select_folder_batch( $job, $worker, $batch );
		if ( is_wp_error( $selected ) ) {
			return yotm_regenerate_application_error( array( 'msg' => $selected->get_error_message() ), 503 );
		}
		$response                   = yotm_build_regenerate_response( $selected, false );
		$response['retry_after_ms'] = 50;
		return yotm_regenerate_application_success( $response );
	}
	if ( in_array( $job['counter_mode'], array( 'item_v2', 'item_v3' ), true ) ) {
		return yotm_regenerate_application_success( yotm_regenerate_item_batch( $job, $worker, $batch ) );
	}

	return yotm_regenerate_application_success( yotm_regenerate_legacy_batch( $job, $worker, $batch ) );
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

	$items = yotm_job_claim_items( $worker, $batch, ! empty( $job['payload']['recovery_only'] ) );
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
		if ( 'attached_meta_v2' === ( $payload['selector'] ?? '' ) && empty( $item['payload']['regeneration_journal'] ) ) {
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
		$terminal = yotm_job_recovery_terminal_status( $job );
		yotm_job_worker_update(
			$worker,
			array(
				'status'     => $terminal,
				'phase'      => $terminal,
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
 * Persist one exact journal phase under the current item claim.
 *
 * @param array $item Claimed item, updated by reference.
 * @param array $journal Exact journal.
 * @return bool
 */
function yotm_regenerate_persist_journal( &$item, $journal ) {
	$payload                         = $item['payload'];
	$payload['regeneration_journal'] = $journal;
	if ( ! yotm_job_update_claimed_item_payload( $item, $payload ) ) {
		return false;
	}
	$item['payload'] = $payload;
	return true;
}

/**
 * Resume or roll back a journal left by an interrupted Force request.
 *
 * @param array $item Claimed item, updated by reference.
 * @return array
 */
function yotm_regenerate_recover_journal( &$item ) {
	$journal = $item['payload']['regeneration_journal'] ?? array();
	if ( empty( $journal ) ) {
		return array(
			'status'  => 'none',
			'message' => '',
		);
	}
	if (
		YOTM_REGENERATE_JOURNAL_VERSION !== absint( $journal['version'] ?? 0 )
		|| empty( $journal['attachment_id'] )
		|| ! is_array( $journal['old_metadata'] ?? null )
		|| ! is_array( $journal['final_metadata'] ?? null )
		|| ! is_array( $journal['destinations'] ?? null )
		|| empty( $journal['full'] )
	) {
		return yotm_regenerate_failure( __( 'The regeneration recovery journal is malformed and requires manual inspection.', 'thumbnail-manager' ) );
	}
	$full  = yotm_media_source_canonical_path( $journal['full'] );
	$stage = yotm_normalize_filesystem_path( (string) ( $journal['stage'] ?? '' ) );
	if ( is_wp_error( $full ) || ! hash_equals( (string) $full, (string) $journal['full'] ) || 0 !== strpos( trailingslashit( $stage ), trailingslashit( dirname( $full ) ) . '.yotm-regenerate-' ) ) {
		return yotm_regenerate_failure( __( 'The regeneration recovery paths are invalid and require manual inspection.', 'thumbnail-manager' ) );
	}

	$aliases = array();
	foreach ( $journal['destinations'] as $entry ) {
		$destination = yotm_media_source_canonical_path( $entry['destination'] ?? '' );
		$backup      = yotm_normalize_filesystem_path( (string) ( $entry['backup'] ?? '' ) );
		if (
			is_wp_error( $destination )
			|| ! hash_equals( (string) $destination, (string) ( $entry['destination'] ?? '' ) )
			|| ( '' !== $backup && 0 !== strpos( $backup, trailingslashit( $stage ) ) )
		) {
			return yotm_regenerate_failure( __( 'The regeneration recovery paths are invalid and require manual inspection.', 'thumbnail-manager' ) );
		}
		$aliases[] = array( 'path' => $destination );
	}
	$locks = yotm_media_path_lock_aliases( $aliases );
	if ( is_wp_error( $locks ) ) {
		return yotm_regenerate_failure( $locks, true );
	}
	try {
		$raw_rows = yotm_media_reference_raw_postmeta_rows( absint( $journal['attachment_id'] ), '_wp_attachment_metadata' );
		if ( is_wp_error( $raw_rows ) ) {
			return yotm_regenerate_failure( $raw_rows, true );
		}
		$raw = array_column( $raw_rows, 'value' );
		if ( 1 !== count( $raw ) || ! is_array( $raw[0] ) ) {
			return yotm_regenerate_failure( __( 'Current metadata does not match either journaled transaction state.', 'thumbnail-manager' ) );
		}

		if ( $raw[0] === $journal['final_metadata'] && hash_equals( (string) $journal['new_metadata_hash'], yotm_regenerate_metadata_hash( $raw[0] ) ) ) {
			$synced = yotm_media_source_sync_attachment( absint( $journal['attachment_id'] ), null, true );
			if ( is_wp_error( $synced ) ) {
				return yotm_regenerate_failure( $synced, true );
			}
			$snapshot = array(
				'full'     => (string) $journal['full'],
				'metadata' => $journal['old_metadata'],
			);
			$cleaned  = yotm_regenerate_cleanup_obsolete( $snapshot, $journal['final_metadata'] );
			if ( is_wp_error( $cleaned ) ) {
				return yotm_regenerate_failure( $cleaned, true );
			}
			$journal['phase'] = 'cleanup_complete';
			if ( ! yotm_regenerate_persist_journal( $item, $journal ) ) {
				return yotm_regenerate_failure( __( 'Could not finalize the recovered regeneration journal.', 'thumbnail-manager' ), true );
			}
			yotm_regenerate_remove_stage( (string) ( $journal['stage'] ?? '' ), dirname( (string) $journal['full'] ) );
			return array(
				'status'  => 'regenerated',
				'message' => '',
			);
		}//end if

		if ( $raw[0] !== $journal['old_metadata'] || ! hash_equals( (string) $journal['old_metadata_hash'], yotm_regenerate_metadata_hash( $raw[0] ) ) ) {
			return yotm_regenerate_failure( __( 'Current metadata does not match either journaled transaction state.', 'thumbnail-manager' ) );
		}
		foreach ( $journal['destinations'] as $slug => $entry ) {
			$owners = yotm_media_reference_path_owners( $entry['destination'] ?? '' );
			if ( is_wp_error( $owners ) || ! empty( $owners['protected'] ) ) {
				return yotm_regenerate_failure( is_wp_error( $owners ) ? $owners : __( 'Recovery found a newly protected destination.', 'thumbnail-manager' ) );
			}
			$actual = array();
			foreach ( $owners['generated'] as $owner ) {
				$actual[] = absint( $owner['attachment_id'] ?? 0 ) . ':' . sanitize_key( $owner['size'] ?? '' ) . ':' . (string) ( $owner['filename'] ?? '' );
			}
			sort( $actual );
			$expected = array_values( (array) ( $entry['owners'] ?? array() ) );
			sort( $expected );
			if ( $actual !== $expected ) {
				return yotm_regenerate_failure( __( 'Destination ownership changed after the interrupted regeneration.', 'thumbnail-manager' ) );
			}
			$promoted = ! empty( $entry['promoted'] ) || (string) ( $journal['promotion_slug'] ?? '' ) === (string) $slug;
			$path     = (string) ( $entry['destination'] ?? '' );
			if ( $promoted ) {
				if ( ! yotm_regenerate_file_hash_matches( $path, (string) ( $entry['promoted_hash'] ?? '' ) ) ) {
					return yotm_regenerate_failure( __( 'A promoted artifact changed after the interrupted regeneration.', 'thumbnail-manager' ) );
				}
				if ( ! empty( $entry['old_absent'] ) ) {
					if ( ! @unlink( $path ) ) {
						return yotm_regenerate_failure( __( 'Could not remove an exact interrupted promoted artifact.', 'thumbnail-manager' ) );
					}
				} elseif ( empty( $entry['backup'] ) || ! yotm_regenerate_file_hash_matches( $entry['backup'], (string) $entry['old_hash'] ) || ! @rename( $entry['backup'], $path ) ) {
					return yotm_regenerate_failure( __( 'Could not restore an exact interrupted generated-file backup.', 'thumbnail-manager' ) );
				}
			} elseif ( ! empty( $entry['old_absent'] ) ? false !== @lstat( $path ) : ! yotm_regenerate_file_hash_matches( $path, (string) $entry['old_hash'] ) ) {
				return yotm_regenerate_failure( __( 'A non-promoted destination changed after the interrupted regeneration.', 'thumbnail-manager' ) );
			}
		}//end foreach

		$payload = $item['payload'];
		unset( $payload['regeneration_journal'] );
		if ( ! yotm_job_update_claimed_item_payload( $item, $payload ) ) {
			return yotm_regenerate_failure( __( 'Could not clear the rolled-back regeneration journal.', 'thumbnail-manager' ), true );
		}
		$item['payload'] = $payload;
		yotm_regenerate_remove_stage( (string) ( $journal['stage'] ?? '' ), dirname( (string) $journal['full'] ) );
		return yotm_regenerate_failure( __( 'The interrupted regeneration was rolled back and will be retried.', 'thumbnail-manager' ), true );
	} finally {
		foreach ( array_reverse( $locks ) as $lock ) {
			yotm_media_path_lock_release( $lock );
		}
	}//end try
}

/**
 * Execute one claim-fenced force-regeneration transaction.
 *
 * @param int   $attachment_id Attachment ID.
 * @param array $item Claimed item.
 * @param array $worker Worker ownership.
 * @return array{status:string,message:string,retryable?:bool}
 */
function yotm_regenerate_force_attachment( $attachment_id, $item, $worker ) {
	if ( empty( $item['id'] ) || ! yotm_job_refresh_worker( $worker ) || ! yotm_job_refresh_item_claim( $item ) ) {
		return yotm_regenerate_failure( __( 'The regeneration claim is stale.', 'thumbnail-manager' ), true );
	}
	$complete = yotm_media_reference_require_complete_index();
	if ( is_wp_error( $complete ) ) {
		return yotm_regenerate_failure( $complete, true );
	}
	$source_fence = yotm_media_source_fence_acquire();
	if ( is_wp_error( $source_fence ) ) {
		return yotm_regenerate_failure( $source_fence, true );
	}
	$attachment_lock = array();
	$path_locks      = array();
	$stage           = '';
	try {
		$attachment_lock = yotm_media_attachment_lock_acquire( $attachment_id );
		if ( is_wp_error( $attachment_lock ) ) {
			return yotm_regenerate_failure( $attachment_lock, true );
		}
		if ( ! empty( $item['payload']['regeneration_journal'] ) ) {
			return yotm_regenerate_recover_journal( $item );
		}
		$snapshot = yotm_regenerate_preflight( $attachment_id );
		if ( is_wp_error( $snapshot ) ) {
			return yotm_regenerate_failure( $snapshot );
		}
		$staged = yotm_regenerate_stage_outputs( $snapshot );
		if ( is_wp_error( $staged ) ) {
			return yotm_regenerate_failure( $staged );
		}
		$stage     = $staged['stage'];
		$finalized = yotm_regenerate_finalize_metadata( $snapshot, $staged );
		if ( is_wp_error( $finalized ) ) {
			return yotm_regenerate_failure( $finalized );
		}
		$aliases = array();
		foreach ( $finalized['map'] as $entry ) {
			$aliases[] = array( 'path' => $entry['destination'] );
		}
		$path_locks = yotm_media_path_lock_aliases( $aliases );
		if ( is_wp_error( $path_locks ) ) {
			return yotm_regenerate_failure( $path_locks, true );
		}
		$locked_paths = array_values( array_unique( array_column( $path_locks, 'path' ) ) );
		$final_paths  = array_values( array_unique( array_column( $finalized['map'], 'destination' ) ) );
		sort( $locked_paths );
		sort( $final_paths );
		if ( $locked_paths !== $final_paths ) {
			return yotm_regenerate_failure( __( 'The acquired destination-lock set does not match the final generated-file map.', 'thumbnail-manager' ) );
		}

		$current = yotm_regenerate_preflight( $attachment_id );
		if ( is_wp_error( $current ) || $current['attached_raw'] !== $snapshot['attached_raw'] || $current['metadata'] !== $snapshot['metadata'] || $current['backups'] !== $snapshot['backups'] || ! hash_equals( $snapshot['source_hash'], $current['source_hash'] ) ) {
			return yotm_regenerate_failure( __( 'Attachment state changed during regeneration; no live file was replaced.', 'thumbnail-manager' ), true );
		}
		$old_owners = yotm_regenerate_old_owners( $snapshot );
		if ( is_wp_error( $old_owners ) ) {
			return yotm_regenerate_failure( $old_owners );
		}
		$destinations = array();
		foreach ( $finalized['map'] as $slug => $entry ) {
			$prestate = yotm_regenerate_destination_prestate( $entry, $snapshot, $old_owners, $stage );
			if ( is_wp_error( $prestate ) ) {
				return yotm_regenerate_failure( $prestate );
			}
			$destinations[ $slug ] = array_merge( $entry, $prestate, array( 'promoted_hash' => $entry['hash'] ) );
		}
		$journal = array(
			'version'           => YOTM_REGENERATE_JOURNAL_VERSION,
			'phase'             => 'prepared',
			'attachment_id'     => $attachment_id,
			'old_metadata'      => $snapshot['metadata'],
			'old_metadata_hash' => $snapshot['metadata_hash'],
			'final_metadata'    => $finalized['metadata'],
			'new_metadata_hash' => yotm_regenerate_metadata_hash( $finalized['metadata'] ),
			'full'              => $snapshot['full'],
			'stage'             => $stage,
			'destinations'      => $destinations,
			'promotion_slug'    => '',
		);
		if ( ! yotm_regenerate_persist_journal( $item, $journal ) ) {
			return yotm_regenerate_failure( __( 'Could not persist the regeneration journal.', 'thumbnail-manager' ), true );
		}

		foreach ( $journal['destinations'] as $slug => &$entry ) {
			$owners_now = yotm_regenerate_destination_prestate( $entry, $snapshot, $old_owners, $stage, false );
			if ( is_wp_error( $owners_now ) || $owners_now['mode'] !== $entry['mode'] || $owners_now['owners'] !== $entry['owners'] || $owners_now['old_hash'] !== $entry['old_hash'] ) {
				return yotm_regenerate_failure( __( 'A generated destination changed before promotion.', 'thumbnail-manager' ), true );
			}
			$journal['promotion_slug'] = (string) $slug;
			if ( ! yotm_regenerate_persist_journal( $item, $journal ) ) {
				return yotm_regenerate_failure( __( 'Could not persist the next promotion intent.', 'thumbnail-manager' ), true );
			}
			if ( ! @rename( $entry['source'], $entry['destination'] ) || ! yotm_regenerate_file_hash_matches( $entry['destination'], $entry['promoted_hash'] ) ) {
				$entry['promoted'] = yotm_regenerate_file_hash_matches( $entry['destination'], $entry['promoted_hash'] );
				yotm_regenerate_rollback( $journal );
				return yotm_regenerate_failure( __( 'Could not promote all staged generated files.', 'thumbnail-manager' ) );
			}
			$entry['promoted']         = true;
			$journal['promotion_slug'] = '';
			if ( ! yotm_regenerate_persist_journal( $item, $journal ) ) {
				return yotm_regenerate_failure( __( 'Could not persist promoted-file evidence.', 'thumbnail-manager' ), true );
			}
		}
		unset( $entry );
		$journal['phase'] = 'files_promoted';
		if ( ! yotm_regenerate_persist_journal( $item, $journal ) ) {
			yotm_regenerate_rollback( $journal );
			return yotm_regenerate_failure( __( 'Could not persist the promoted-files journal.', 'thumbnail-manager' ) );
		}

		$written  = update_post_meta( $attachment_id, '_wp_attachment_metadata', $journal['final_metadata'] );
		$raw_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_metadata' );
		$raw      = is_wp_error( $raw_rows ) ? array() : array_column( $raw_rows, 'value' );
		if ( ( false === $written && ( 1 !== count( $raw ) || $raw[0] !== $journal['final_metadata'] ) ) || 1 !== count( $raw ) || $raw[0] !== $journal['final_metadata'] ) {
			update_post_meta( $attachment_id, '_wp_attachment_metadata', $snapshot['metadata'] );
			$restored_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_metadata' );
			$restored_raw  = is_wp_error( $restored_rows ) ? array() : array_column( $restored_rows, 'value' );
			$rolled_back   = yotm_regenerate_rollback( $journal );
			if ( ! $rolled_back || 1 !== count( $restored_raw ) || $restored_raw[0] !== $snapshot['metadata'] ) {
				return yotm_regenerate_failure( __( 'Metadata commit and automatic rollback were incomplete; journal evidence was retained for manual recovery.', 'thumbnail-manager' ) );
			}
			return yotm_regenerate_failure( __( 'Could not commit exact regenerated attachment metadata; the old state was restored.', 'thumbnail-manager' ) );
		}
		$journal['phase'] = 'metadata_committed';
		if ( ! yotm_regenerate_persist_journal( $item, $journal ) ) {
			return yotm_regenerate_failure( __( 'Metadata committed, but its recovery journal could not be advanced.', 'thumbnail-manager' ) );
		}
		$synced = yotm_media_source_sync_attachment( $attachment_id, null, true );
		if ( is_wp_error( $synced ) ) {
			return yotm_regenerate_failure( $synced, true );
		}
		$cleaned = yotm_regenerate_cleanup_obsolete( $snapshot, $journal['final_metadata'] );
		if ( is_wp_error( $cleaned ) ) {
			return yotm_regenerate_failure( $cleaned, true );
		}
		$journal['phase'] = 'cleanup_complete';
		if ( ! yotm_regenerate_persist_journal( $item, $journal ) ) {
			return yotm_regenerate_failure( __( 'Could not finalize the regeneration journal.', 'thumbnail-manager' ), true );
		}
		yotm_regenerate_remove_stage( $stage, dirname( $snapshot['full'] ) );
		$stage = '';
		return array(
			'status'  => 'regenerated',
			'message' => '',
		);
	} finally {
		foreach ( array_reverse( is_array( $path_locks ) ? $path_locks : array() ) as $handle ) {
			yotm_media_path_lock_release( $handle );
		}
		if ( is_array( $attachment_lock ) ) {
			yotm_media_attachment_lock_release( $attachment_lock );
		}
		yotm_media_source_fence_release( $source_fence );
		if ( '' !== $stage && is_dir( $stage ) && empty( $item['payload']['regeneration_journal'] ) ) {
			yotm_regenerate_remove_stage( $stage, dirname( $stage ) );
		}
	}//end try
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
