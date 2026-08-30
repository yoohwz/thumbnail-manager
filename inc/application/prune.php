<?php
/**
 * Transport-neutral Prune application sequencing and policy.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/media-source-indexing.php';
require_once dirname( __DIR__ ) . '/media/prune.php';

/**
 * Return one successful Application outcome for a historical transport adapter.
 *
 * @param array $data Public response data.
 * @return array
 */
function yotm_prune_application_success( $data ) {
	return array(
		'success' => true,
		'data'    => $data,
		'status'  => 200,
	);
}

/**
 * Return one failed Application outcome for a historical transport adapter.
 *
 * @param array $data Public error data.
 * @param int   $status Existing response status.
 * @return array
 */
function yotm_prune_application_error( $data, $status ) {
	return array(
		'success' => false,
		'data'    => $data,
		'status'  => absint( $status ),
	);
}

/**
 * Merge Prune ownership evidence into one mutable queued item.
 *
 * @param int    $job_id Job ID.
 * @param string $item_key Stable item key.
 * @param array  $payload New Prune payload evidence.
 * @return bool
 */
function yotm_prune_merge_item_payload( $job_id, $item_key, $payload ) {
	$item = yotm_job_get_mutable_item( $job_id, $item_key );
	if ( ! is_array( $item ) ) {
		return false;
	}

	$current  = $item['payload'];
	$refs     = array();
	$evidence = array();

	foreach ( array_merge( (array) ( $current['metadata_refs'] ?? array() ), (array) ( $payload['metadata_refs'] ?? array() ) ) as $ref ) {
		if ( ! is_array( $ref ) ) {
			continue;
		}

		$key          = absint( $ref['attachment_id'] ?? 0 ) . ':' . sanitize_key( $ref['size'] ?? '' ) . ':' . sanitize_file_name( $ref['filename'] ?? '' );
		$refs[ $key ] = array(
			'attachment_id'     => absint( $ref['attachment_id'] ?? 0 ),
			'size'              => sanitize_key( $ref['size'] ?? '' ),
			'filename'          => sanitize_file_name( $ref['filename'] ?? '' ),
			'selection_meta_id' => absint( $ref['selection_meta_id'] ?? 0 ),
		);
	}

	foreach ( array_merge( (array) ( $current['ownership_evidence'] ?? array() ), (array) ( $payload['ownership_evidence'] ?? array() ) ) as $proof ) {
		if ( ! is_array( $proof ) ) {
			continue;
		}

		$attachment_id = absint( $proof['attachment_id'] ?? 0 );
		$size          = sanitize_key( $proof['size'] ?? '' );
		$filename      = sanitize_file_name( $proof['filename'] ?? '' );
		$selection     = sanitize_key( $proof['selection'] ?? '' );
		$key           = $attachment_id . ':' . $size . ':' . $filename . ':' . $selection;

		if ( ! $attachment_id || '' === $size || '' === $filename || '' === $selection ) {
			continue;
		}

		$evidence[ $key ] = array(
			'attachment_id'     => $attachment_id,
			'size'              => $size,
			'filename'          => $filename,
			'mime'              => sanitize_mime_type( $proof['mime'] ?? '' ),
			'selection'         => $selection,
			'selection_meta_id' => absint( $proof['selection_meta_id'] ?? 0 ),
		);
	}//end foreach

	ksort( $refs );
	ksort( $evidence );
	$current['metadata_refs']      = array_values( $refs );
	$current['ownership_evidence'] = array_values( $evidence );
	$current['ownership_schema']   = sanitize_key( $payload['ownership_schema'] ?? ( $current['ownership_schema'] ?? '' ) );
	$current['ownership']          = sanitize_key( $payload['ownership'] ?? ( $current['ownership'] ?? '' ) );

	if ( empty( $current['remove_metadata'] ) && ! empty( $payload['remove_metadata'] ) ) {
		$current['remove_metadata'] = 1;
	}

	return yotm_job_update_mutable_item_payload( $item['id'], $current );
}

/**
 * Project one opaque Jobs item page into the existing Prune manifest response.
 *
 * @param int    $job_id Job ID.
 * @param int    $page Current page.
 * @param int    $per_page Items per page.
 * @param string $search Optional path/size search.
 * @return array{items:array,total:int,pages:int,page:int}|WP_Error
 */
function yotm_prune_get_items_page( $job_id, $page = 1, $per_page = 25, $search = '' ) {
	$rows = yotm_job_get_item_rows_page( $job_id, $page, $per_page, $search );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	$items = array();

	foreach ( $rows['items'] as $row ) {
		$payload = $row['payload'];
		$items[] = array(
			'id'                   => (int) $row['id'],
			'path'                 => (string) ( $payload['path'] ?? '' ),
			'attachment_id'        => absint( $payload['attachment_id'] ?? 0 ),
			'size'                 => (string) ( $payload['size'] ?? '' ),
			'source'               => (string) ( $payload['source'] ?? '' ),
			'ownership'            => (string) ( $payload['ownership'] ?? '' ),
			'ownership_schema'     => (string) ( $payload['ownership_schema'] ?? '' ),
			'ownership_evidence'   => is_array( $payload['ownership_evidence'] ?? null ) ? $payload['ownership_evidence'] : array(),
			'observed_width'       => absint( $payload['observed_width'] ?? 0 ),
			'observed_height'      => absint( $payload['observed_height'] ?? 0 ),
			'observed_mime'        => (string) ( $payload['observed_mime'] ?? '' ),
			'matched_remove_sizes' => array_values( array_map( 'sanitize_key', (array) ( $payload['matched_remove_sizes'] ?? array() ) ) ),
			'source_path'          => (string) ( $payload['source_path'] ?? '' ),
			'status'               => (string) $row['status'],
			'error'                => (string) $row['error'],
			'estimated_bytes'      => absint( $payload['estimated_bytes'] ?? $row['bytes'] ),
			'bytes'                => (int) $row['bytes'],
		);
	}//end foreach

	$rows['items'] = $items;
	return $rows;
}

/**
 * Revalidate Prune items and advance the immutable Jobs digest in bounded rows.
 *
 * @param array      $job Job row.
 * @param int        $limit Hash batch size.
 * @param array|null $worker Optional worker ownership data.
 * @return array{done:bool,job:array}|WP_Error
 */
function yotm_prune_build_manifest_batch( $job, $limit = 1000, $worker = null ) {
	$payload      = $job['payload'];
	$after        = isset( $payload['manifest_after'] ) ? (string) $payload['manifest_after'] : '';
	$digest       = isset( $payload['manifest_digest'] ) ? (string) $payload['manifest_digest'] : yotm_job_manifest_digest_seed();
	$class_counts = is_array( $payload['manifest_class_counts'] ?? null )
		? $payload['manifest_class_counts']
		: array(
			'metadata_backed'            => 0,
			'verified_legacy'            => 0,
			'verified_legacy_current'    => 0,
			'verified_historical_legacy' => 0,
		);
	$limit        = max( 10, min( 5000, absint( $limit ) ) );
	$rows         = yotm_job_get_manifest_rows_after( $job['id'], $after, $limit );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}

	foreach ( $rows as $row ) {
		$item_payload = $row['payload'];
		$item_schema  = (string) ( $item_payload['ownership_schema'] ?? '' );
		if ( 'prune' === ( $job['type'] ?? '' ) && in_array( $item_schema, array( 'generated_file_v1', 'legacy_generated_v1', 'historical_legacy_generated_v1' ), true ) ) {
			$source_fence = yotm_media_source_fence_acquire();
			if ( is_wp_error( $source_fence ) ) {
				return array(
					'done' => false,
					'job'  => yotm_job_get_by_id( $job['id'] ),
				);
			}
			try {
				if ( 'legacy_generated_v1' === $item_schema ) {
					$reference_check = yotm_prune_validate_legacy_evidence( $item_payload, $payload );
				} elseif ( 'historical_legacy_generated_v1' === $item_schema ) {
					$reference_check = yotm_prune_validate_historical_evidence( $item_payload, $payload );
				} else {
					$reference_check = yotm_prune_validate_live_reference_evidence( $item_payload, $item_payload['path'] ?? '' );
				}
			} finally {
				yotm_media_source_fence_release( $source_fence );
			}
			if ( is_wp_error( $reference_check ) ) {
				if ( ! yotm_job_delete_item_by_key( $job['id'], $row['item_key'] ) ) {
					return array(
						'done' => false,
						'job'  => yotm_job_get_by_id( $job['id'] ),
					);
				}
				$after = $row['item_key'];
				continue;
			}
		}//end if

		if ( 'generated_file_v1' === $item_schema ) {
			$class_counts['metadata_backed'] = absint( $class_counts['metadata_backed'] ?? 0 ) + 1;
		} elseif ( 'legacy_generated_v1' === $item_schema ) {
			$class_counts['verified_legacy']         = absint( $class_counts['verified_legacy'] ?? 0 ) + 1;
			$class_counts['verified_legacy_current'] = absint( $class_counts['verified_legacy_current'] ?? 0 ) + 1;
		} elseif ( 'historical_legacy_generated_v1' === $item_schema ) {
			$class_counts['verified_legacy']            = absint( $class_counts['verified_legacy'] ?? 0 ) + 1;
			$class_counts['verified_historical_legacy'] = absint( $class_counts['verified_historical_legacy'] ?? 0 ) + 1;
		}
		$digest = yotm_job_manifest_digest_advance( $digest, $row['item_key'], $row['payload_json'] );
		$after  = $row['item_key'];
	}//end foreach

	return yotm_job_update_manifest_checkpoint(
		$job,
		$after,
		$digest,
		count( $rows ) < $limit,
		$worker,
		array(
			'reference_generation'  => defined( 'YOTM_MEDIA_REFERENCE_GENERATION' ) ? YOTM_MEDIA_REFERENCE_GENERATION : 0,
			'manifest_class_counts' => $class_counts,
		)
	);
}

/**
 * Prepare a persistent prune job from parsed request values.
 *
 * @param string[] $keep Registered image-size names to preserve.
 * @param string[] $limit_subpaths Optional upload-relative scan scopes.
 * @param bool     $discover_orphans Whether to include current-disabled disk-only discovery.
 * @param bool     $discover_historical Whether to include historical-unregistered discovery.
 * @return array Application outcome.
 */
function yotm_prune_prepare_application( $keep, $limit_subpaths, $discover_orphans, $discover_historical = false ) {
	$limit_subpaths      = yotm_normalize_upload_subpaths( $limit_subpaths );
	$discover_orphans    = (bool) $discover_orphans;
	$discover_historical = (bool) $discover_historical;
	$scan_disk           = $discover_orphans || $discover_historical;
	$uploads             = wp_get_upload_dir();
	$base                = trailingslashit( yotm_normalize_filesystem_path( $uploads['basedir'] ) );
	$scan_bases          = yotm_resolve_upload_scan_bases( $base, $limit_subpaths );

	if ( is_wp_error( $scan_bases ) ) {
		return yotm_prune_application_error( array( 'msg' => $scan_bases->get_error_message() ), 400 );
	}

	$sizes              = yotm_get_registered_sizes();
	$all                = array_keys( $sizes );
	$keep               = array_values( array_unique( array_intersect( $keep, $all ) ) );
	$to_remove          = array_values( array_diff( $all, $keep ) );
	$query_args         = yotm_attachment_query_args_for_upload_subpaths( $limit_subpaths );
	$selector           = empty( $limit_subpaths ) ? 'attachment_id_v1' : 'attached_meta_v2';
	$selection_meta_max = 'attached_meta_v2' === $selector ? yotm_get_max_attached_file_meta_id() : 0;
	if ( is_wp_error( $selection_meta_max ) ) {
		return yotm_prune_application_error( array( 'msg' => $selection_meta_max->get_error_message() ), 503 );
	}
	$scan_total                           = 'attached_meta_v2' === $selector ? 0 : yotm_count_image_attachments( $query_args );
	$scope_label                          = yotm_uploads_scope_label( $base, $scan_bases );
	$job_payload                          = array(
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
		'discover_orphans'         => $scan_disk ? 1 : 0,
		'discover_current_legacy'  => $discover_orphans ? 1 : 0,
		'discover_historical'      => $discover_historical ? 1 : 0,
		'orphan_summary'           => yotm_initial_orphan_summary(),
		'disk_queue'               => array(),
		'disk_cursor_version'      => 'dfs_v2',
		'disk_entries_processed'   => 0,
	);
	$job_payload['legacy_policy']         = array(
		'version'                         => 2,
		'enabled'                         => $scan_disk ? 1 : 0,
		'current_disabled_enabled'        => $discover_orphans ? 1 : 0,
		'historical_unregistered_enabled' => $discover_historical ? 1 : 0,
		'constants'                       => yotm_historical_cohort_constants(),
		'ratio_schema'                    => 'integer_gcd_v1',
		'hash'                            => '',
	);
	$job_payload['legacy_policy']['hash'] = yotm_legacy_policy_hash( $job_payload );
	$job                                  = yotm_job_create(
		'prune',
		$job_payload,
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
		return yotm_prune_application_error(
			array(
				'msg'          => $job->get_error_message(),
				'resume_token' => is_array( $data ) ? ( $data['token'] ?? '' ) : '',
			),
			409
		);
	}

	return yotm_prune_application_success( yotm_build_prune_scan_response( $job, false ) );
}

/**
 * Advance one bounded prune scan batch.
 *
 * @param string $token Persistent job token.
 * @param int    $batch Maximum work items for this request.
 * @return array Application outcome.
 */
function yotm_prune_scan_application( $token, $batch ) {
	$batch = max( 1, min( 500, $batch ) );
	$job   = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		return yotm_prune_application_error( array( 'msg' => $job->get_error_message() ), 400 );
	}

	if ( 'prune' !== $job['type'] ) {
		return yotm_prune_application_error( array( 'msg' => __( 'Invalid prune job.', 'thumbnail-manager' ) ), 400 );
	}

	if ( in_array( $job['status'], array( 'awaiting_approval', 'approved', 'deleting', 'completed' ), true ) ) {
		return yotm_prune_application_success( yotm_build_prune_scan_response( $job, true ) );
	}

	if ( 'scanning' !== $job['status'] ) {
		return yotm_prune_application_error( array( 'msg' => __( 'This prune job is not scannable.', 'thumbnail-manager' ) ), 409 );
	}

	$worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'source_index', 'selection', 'metadata', 'disk', 'cohort_aggregate', 'cohort_materialize', 'manifest' ) );
	if ( is_wp_error( $worker ) ) {
		if ( 'yotm_job_worker_busy' !== $worker->get_error_code() ) {
			return yotm_prune_application_error( array( 'msg' => $worker->get_error_message() ), 503 );
		}

		$data                       = $worker->get_error_data();
		$current                    = is_array( $data ) && is_array( $data['job'] ?? null ) ? $data['job'] : $job;
		$response                   = yotm_build_prune_scan_response( $current, ( $current['status'] ?? '' ) !== 'scanning' );
		$response['retry_after_ms'] = 'scanning' === ( $current['status'] ?? '' ) ? 250 : 0;
		return yotm_prune_application_success( $response );
	}

	$job = yotm_job_get_by_id( $job['id'] );

	$payload = $job['payload'];
	$phase   = $payload['scan_phase'] ?? 'source_index';

	if ( 'source_index' === $phase ) {
		$indexed = yotm_application_media_source_index_batch( $job, $batch, $worker );
		if ( is_wp_error( $indexed ) ) {
			return yotm_prune_application_error( array( 'msg' => $indexed->get_error_message() ), 503 );
		}
		$job = $indexed['job'];
		if ( ! $indexed['done'] ) {
			return yotm_prune_application_success( yotm_build_prune_scan_response( $job, false ) );
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
			return yotm_prune_application_error( array( 'msg' => $rows->get_error_message() ), 503 );
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
			$queued_total = yotm_job_count_items_by_status( $job['id'], array( 'queued' ) );
			if ( is_wp_error( $queued_total ) ) {
				return yotm_prune_application_error( array( 'msg' => $queued_total->get_error_message() ), 503 );
			}
			$payload['selection_meta_after'] = max( array_map( 'absint', wp_list_pluck( $rows, 'meta_id' ) ) );
			$payload['selection_scanned']    = (int) ( $payload['selection_scanned'] ?? 0 ) + count( $rows );
			$payload['selection_matched']    = (int) ( $payload['selection_matched'] ?? 0 ) + count( $ids );
			$payload['scan_processed']       = (int) ( $payload['scan_processed'] ?? 0 ) + count( $ids );
			yotm_job_worker_update(
				$worker,
				array(
					'payload' => $payload,
					'total'   => $queued_total,
				)
			);
			return yotm_prune_application_success( yotm_build_prune_scan_response( yotm_job_get_by_id( $job['id'] ), false ) );
		}//end if

		$payload['selection_done']         = 1;
		$payload['scan_total_attachments'] = (int) ( $payload['selection_matched'] ?? 0 );
		$job                               = yotm_prune_advance_after_metadata( $job, $worker, $payload );
		if ( is_wp_error( $job ) ) {
			return yotm_prune_application_error( array( 'msg' => $job->get_error_message() ), 503 );
		}
		$payload = $job['payload'];
		$phase   = $payload['scan_phase'] ?? $job['phase'];
	}//end if

	if ( 'metadata' === $phase ) {
		if ( empty( $payload['source_index_complete'] ) ) {
			return yotm_prune_application_error( array( 'msg' => __( 'The authoritative source index is incomplete. Run a new scan.', 'thumbnail-manager' ) ), 409 );
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
			$total                     = yotm_job_count_items_by_status( $job['id'], array( 'queued' ) );
			if ( is_wp_error( $total ) ) {
				return yotm_prune_application_error( array( 'msg' => $total->get_error_message() ), 503 );
			}
			yotm_job_worker_update(
				$worker,
				array(
					'payload' => $payload,
					'cursor'  => $cursor,
					'total'   => $total,
				)
			);

			return yotm_prune_application_success( yotm_build_prune_scan_response( yotm_job_get_by_id( $job['id'] ), false ) );
		}//end if

		$job = yotm_prune_advance_after_metadata( $job, $worker, $payload );
		if ( is_wp_error( $job ) ) {
			return yotm_prune_application_error( array( 'msg' => $job->get_error_message() ), 503 );
		}
	}//end if

	if ( 'disk' === ( $job['payload']['scan_phase'] ?? '' ) ) {
		$disk = yotm_prune_scan_disk_batch( $job, $batch, $worker );
		$job  = $disk['job'];

		if ( ! $disk['done'] ) {
			return yotm_prune_application_success( yotm_build_prune_scan_response( $job, false ) );
		}

		$payload               = $job['payload'];
		$historical_enabled    = ! is_wp_error( yotm_historical_policy_validate( $payload ) );
		$payload['scan_phase'] = $historical_enabled ? 'cohort_aggregate' : 'manifest';
		if ( $historical_enabled ) {
			$payload['cohort_aggregate_stage'] = 'anchors';
			$payload['cohort_anchors_after']   = 0;
		}
		if ( ! yotm_job_worker_update(
			$worker,
			array(
				'payload' => $payload,
				'phase'   => $payload['scan_phase'],
			)
		) ) {
			return yotm_prune_application_error( array( 'msg' => __( 'The historical cohort transition could not be persisted safely.', 'thumbnail-manager' ) ), 503 );
		}
		$job = yotm_job_get_by_id( $job['id'] );
	}//end if

	if ( 'cohort_aggregate' === ( $job['payload']['scan_phase'] ?? '' ) ) {
		$cohort = yotm_prune_historical_aggregate_batch( $job, $batch, $worker );
		if ( is_wp_error( $cohort ) ) {
			return yotm_prune_application_error( array( 'msg' => $cohort->get_error_message() ), 503 );
		}
		$job = $cohort['job'];
		if ( ! $cohort['done'] ) {
			return yotm_prune_application_success( yotm_build_prune_scan_response( $job, false ) );
		}
	}

	if ( 'cohort_materialize' === ( $job['payload']['scan_phase'] ?? '' ) ) {
		$cohort = yotm_prune_historical_materialize_batch( $job, $batch, $worker );
		if ( is_wp_error( $cohort ) ) {
			return yotm_prune_application_error( array( 'msg' => $cohort->get_error_message() ), 503 );
		}
		$job = $cohort['job'];
		if ( ! $cohort['done'] ) {
			return yotm_prune_application_success( yotm_build_prune_scan_response( $job, false ) );
		}
	}

	$intermediate       = array( 'historical_anchor', 'historical_observation', 'historical_cohort', 'historical_rejected' );
	$intermediate_count = yotm_job_count_items_by_status( $job['id'], $intermediate );
	if ( is_wp_error( $intermediate_count ) ) {
		return yotm_prune_application_error( array( 'msg' => $intermediate_count->get_error_message() ), 503 );
	}
	if ( 0 !== $intermediate_count ) {
		return yotm_prune_application_error( array( 'msg' => __( 'Historical cohort evidence is incomplete and cannot enter the manifest.', 'thumbnail-manager' ) ), 409 );
	}

	$manifest = yotm_prune_build_manifest_batch( $job, max( 500, $batch * 5 ), $worker );
	if ( is_wp_error( $manifest ) ) {
		return yotm_prune_application_error( array( 'msg' => $manifest->get_error_message() ), 503 );
	}
	$job = $manifest['job'];

	if ( ! $manifest['done'] ) {
		return yotm_prune_application_success( yotm_build_prune_scan_response( $job, false ) );
	}

	$current = yotm_job_get_by_id( $job['id'] );
	if ( is_array( $current ) && 'cancelled' === $current['status'] ) {
		return yotm_prune_application_success( yotm_build_prune_scan_response( $current, false ) );
	}

	$payload                    = $job['payload'];
	$payload['scan_phase']      = 'review';
	$payload['scan_done']       = 1;
	$payload['estimated_bytes'] = yotm_job_sum_item_bytes( $job['id'] );
	$total                      = yotm_job_count_items_by_status( $job['id'], array( 'queued' ) );
	if ( is_wp_error( $total ) ) {
		return yotm_prune_application_error( array( 'msg' => $total->get_error_message() ), 503 );
	}
	$final_status = $total > 0 ? 'awaiting_approval' : 'completed';
	$final_phase  = $total > 0 ? 'review' : 'completed';
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

	return yotm_prune_application_success( yotm_build_prune_scan_response( yotm_job_get_by_id( $job['id'] ), true ) );
}

/**
 * Approve a reviewed immutable prune manifest.
 *
 * @param string $token Persistent job token.
 * @param string $manifest_hash Reviewed immutable manifest hash.
 * @param bool   $confirmed Whether the Human explicitly confirmed deletion.
 * @return array Application outcome.
 */
function yotm_prune_approve_application( $token, $manifest_hash, $confirmed ) {
	$job = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		return yotm_prune_application_error( array( 'msg' => $job->get_error_message() ), 400 );
	}

	$valid = yotm_prune_validate_review_job( $job, $manifest_hash, $confirmed );

	if ( is_wp_error( $valid ) ) {
		return yotm_prune_application_error( array( 'msg' => $valid->get_error_message() ), 409 );
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
		return yotm_prune_application_error( array( 'msg' => __( 'The prune job changed before approval. Refresh and review its current state.', 'thumbnail-manager' ) ), 409 );
	}

	return yotm_prune_application_success(
		array(
			'token'         => $approved['token'],
			'manifest_hash' => $approved['manifest_hash'],
			'total'         => $approved['total'],
			'expires_in'    => 30 * MINUTE_IN_SECONDS,
		)
	);
}

/**
 * Delete one bounded batch from an approved prune manifest.
 *
 * @param string $token Persistent job token.
 * @param string $manifest_hash Approved immutable manifest hash.
 * @param int    $batch Maximum work items for this request.
 * @return array Application outcome.
 */
function yotm_prune_delete_application( $token, $manifest_hash, $batch ) {
	$batch = max( 1, min( 500, $batch ) );
	$job   = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		return yotm_prune_application_error( array( 'msg' => $job->get_error_message() ), 400 );
	}

	$delete_allowed = yotm_prune_validate_delete_job( $job, $manifest_hash );

	if ( is_wp_error( $delete_allowed ) ) {
		return yotm_prune_application_error( array( 'msg' => $delete_allowed->get_error_message() ), 409 );
	}

	$worker = yotm_job_acquire_worker( $job['id'], array( 'approved', 'deleting' ), array( 'delete' ) );
	if ( is_wp_error( $worker ) ) {
		if ( 'yotm_job_worker_busy' !== $worker->get_error_code() ) {
			return yotm_prune_application_error( array( 'msg' => $worker->get_error_message() ), 503 );
		}

		$data     = $worker->get_error_data();
		$current  = is_array( $data ) && is_array( $data['job'] ?? null ) ? $data['job'] : $job;
		$response = yotm_build_prune_delete_response( $current, 0, 0, true );
		return yotm_prune_application_success( $response );
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
		return yotm_prune_application_error( array( 'msg' => $items->get_error_message() ), 409 );
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
		return yotm_prune_application_success( yotm_build_prune_delete_response( yotm_job_get_by_id( $job['id'] ), $deleted_now, $failed_now, false ) );
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
				return yotm_prune_application_error( array( 'msg' => __( 'Prune counters did not match the terminal item audit.', 'thumbnail-manager' ) ), 503 );
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

	return yotm_prune_application_success( yotm_build_prune_delete_response( yotm_job_get_by_id( $job['id'] ), $deleted_now, $failed_now, $retry ) );
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
		! empty( $payload['discover_current_legacy'] ),
		$candidates,
		$payload['orphan_summary']
	);
	$historical = yotm_collect_historical_metadata_anchors_for_ids( $ids, $payload, $selection_meta_ids );
	foreach ( $historical['anchors'] as $anchor ) {
		$item_key = hash( 'sha256', 'historical-anchor-v1:' . (string) ( $anchor['historical_witness_key'] ?? '' ) );
		if ( yotm_job_add_item( $job['id'], $item_key, $anchor, 'historical_anchor', 0 ) ) {
			$payload['orphan_summary']['historical_anchors'] = absint( $payload['orphan_summary']['historical_anchors'] ?? 0 ) + 1;
		}
	}
	$payload['orphan_summary']['source_errors']        = absint( $payload['orphan_summary']['source_errors'] ?? 0 ) + absint( $historical['errors'] ?? 0 );
	$payload['orphan_summary']['historical_ambiguous'] = absint( $payload['orphan_summary']['historical_ambiguous'] ?? 0 ) + absint( $historical['overflow'] ?? 0 );

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
			yotm_prune_merge_item_payload( $job['id'], $item_key, $candidate );
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

	$existing             = yotm_job_existing_item_keys( $job['id'], array_keys( $disk_checks ) );
	$unmapped_paths       = array_diff_key( $disk_checks, $existing );
	$legacy_paths         = array();
	$legacy_results       = array();
	$precounted_ambiguous = array();
	foreach ( $unmapped_paths as $path ) {
		$filename = wp_basename( $path );
		if ( preg_match( '/\.(?:jpe?g|png|gif)\.(?:webp|avif)$/i', $filename ) ) {
			$summary['unverified_sidecars'] = (int) ( $summary['unverified_sidecars'] ?? 0 ) + 1;
			$legacy_results[ $path ]        = new WP_Error( 'yotm_legacy_sidecar_unverified', __( 'The format sidecar is not exact generated-file ownership evidence.', 'thumbnail-manager' ) );
		} elseif ( preg_match( '/(?:\.(?:bak|backup|orig|original|old|tmp|temp)(?:\.|$)|@\d+x|-\d+\.)/i', $filename ) ) {
			$summary['ambiguous_siblings'] = (int) ( $summary['ambiguous_siblings'] ?? 0 ) + 1;
			$precounted_ambiguous[ $path ] = true;
			$legacy_results[ $path ]       = new WP_Error( 'yotm_legacy_sibling_ambiguous', __( 'The disk file is an ambiguous sibling.', 'thumbnail-manager' ) );
		} else {
			$legacy_paths[] = $path;
		}
	}
	if ( ! empty( $legacy_paths ) ) {
		$classified = yotm_classify_legacy_disk_candidates( $legacy_paths, $payload );
		if ( is_wp_error( $classified ) ) {
			foreach ( $legacy_paths as $path ) {
				$legacy_results[ $path ] = $classified;
			}
		} else {
			$legacy_results = array_merge( $legacy_results, $classified );
		}
	}

	foreach ( $unmapped_paths as $path ) {
		$result = $legacy_results[ $path ] ?? new WP_Error( 'yotm_legacy_unclassified', __( 'The disk-only file could not be classified safely.', 'thumbnail-manager' ) );
		if ( is_array( $result ) ) {
			$historical_observation = 'historical_observation_v1' === ( $result['ownership_schema'] ?? '' );
			$item_key               = $historical_observation
				? hash( 'sha256', 'historical-observation-v1:' . $path )
				: hash( 'sha256', $path );
			$item_status            = $historical_observation ? 'historical_observation' : 'queued';
			$inserted               = yotm_job_add_item( $job['id'], $item_key, $result, $item_status, $historical_observation ? 0 : absint( $result['estimated_bytes'] ?? 0 ) );
			if ( $inserted ) {
				if ( $historical_observation ) {
					$summary['historical_observations'] = absint( $summary['historical_observations'] ?? 0 ) + 1;
				} else {
					$summary['verified_legacy']         = absint( $summary['verified_legacy'] ?? 0 ) + 1;
					$summary['verified_legacy_current'] = absint( $summary['verified_legacy_current'] ?? 0 ) + 1;
					if ( count( $payload['sample'] ) < 300 ) {
						$payload['sample'][] = $path;
					}
				}
				continue;
			}
			$result = new WP_Error( 'yotm_legacy_item_persist_failed', __( 'The verified legacy item could not be persisted safely.', 'thumbnail-manager' ) );
		}//end if

		$code = is_wp_error( $result ) ? $result->get_error_code() : 'yotm_legacy_unclassified';
		if ( 'yotm_legacy_kept_dimension' === $code ) {
			$summary['kept_dimension_preserved'] = (int) ( $summary['kept_dimension_preserved'] ?? 0 ) + 1;
		} elseif ( in_array( $code, array( 'yotm_legacy_filename_invalid', 'yotm_legacy_image_mismatch', 'yotm_legacy_node_invalid', 'yotm_legacy_path_invalid', 'yotm_legacy_file_unreadable' ), true ) ) {
			$summary['malformed_preserved'] = (int) ( $summary['malformed_preserved'] ?? 0 ) + 1;
		} elseif ( in_array( $code, array( 'yotm_legacy_sibling_ambiguous', 'yotm_legacy_family_ambiguous', 'yotm_legacy_generation_source_mismatch' ), true ) ) {
			if ( empty( $precounted_ambiguous[ $path ] ) ) {
				$summary['ambiguous_siblings'] = (int) ( $summary['ambiguous_siblings'] ?? 0 ) + 1;
			}
		} elseif ( 'yotm_legacy_path_owned' === $code ) {
			$summary['protected_sources'] = (int) ( $summary['protected_sources'] ?? 0 ) + 1;
		} elseif ( 'yotm_historical_shape_unsupported' === $code ) {
			$summary['historical_shape_preserved'] = absint( $summary['historical_shape_preserved'] ?? 0 ) + 1;
		} elseif ( 'yotm_historical_current_projection' === $code ) {
			$summary['historical_ambiguous'] = absint( $summary['historical_ambiguous'] ?? 0 ) + 1;
		} elseif ( 0 === strpos( $code, 'yotm_media_' ) || 0 === strpos( $code, 'yotm_regenerate_' ) || 0 === strpos( $code, 'yotm_prune_selector_' ) ) {
			$summary['source_errors'] = (int) ( $summary['source_errors'] ?? 0 ) + 1;
		}
		$summary['unmapped']         = (int) ( $summary['unmapped'] ?? 0 ) + 1;
		$summary['unmapped_skipped'] = (int) ( $summary['unmapped_skipped'] ?? 0 ) + 1;
		if ( count( $summary['unmapped_sample'] ) < 20 ) {
			$summary['unmapped_sample'][] = $path;
		}
	}//end foreach

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
 * Return the stable item key for one cohort row.
 *
 * @param string $kind Cohort row kind.
 * @param string $signature Historical signature.
 * @param string $size_key Optional historical size key.
 * @return string
 */
function yotm_prune_historical_cohort_item_key( $kind, $signature, $size_key = '' ) {
	return hash( 'sha256', 'historical-cohort-v1:' . sanitize_key( $kind ) . ':' . (string) $signature . ':' . sanitize_key( $size_key ) );
}

/**
 * Merge one deterministic inert cohort state row.
 *
 * @param array  $job Job row.
 * @param array  $worker Current worker.
 * @param string $item_key Stable cohort row key.
 * @param array  $incoming Incoming state.
 * @return array|WP_Error Current merged payload.
 */
function yotm_prune_historical_merge_cohort_state( $job, $worker, $item_key, $incoming ) {
	if ( yotm_job_worker_add_item( $worker, $item_key, $incoming, 'historical_cohort', 0 ) ) {
		return $incoming;
	}
	$item = yotm_job_get_item_by_key( $job['id'], $item_key );
	if ( ! is_array( $item ) || 'historical_cohort' !== ( $item['status'] ?? '' ) ) {
		return new WP_Error( 'yotm_historical_cohort_state', __( 'Historical cohort state could not be loaded safely.', 'thumbnail-manager' ) );
	}
	$current = is_array( $item['payload'] ?? null ) ? $item['payload'] : array();
	if ( ! hash_equals( (string) ( $current['evidence_kind'] ?? '' ), (string) ( $incoming['evidence_kind'] ?? '' ) ) ) {
		return new WP_Error( 'yotm_historical_cohort_collision', __( 'Historical cohort state collided with another evidence class.', 'thumbnail-manager' ) );
	}
	if ( 'historical_cohort_key_v1' === ( $incoming['evidence_kind'] ?? '' ) ) {
		$current['anchors'] = yotm_historical_reduce_witness_pool(
			array_merge( (array) ( $current['anchors'] ?? array() ), (array) ( $incoming['anchors'] ?? array() ) ),
			yotm_historical_cohort_constants()['min_metadata_anchors']
		);
	} else {
		$current['observations'] = yotm_historical_reduce_witness_pool(
			array_merge( (array) ( $current['observations'] ?? array() ), (array) ( $incoming['observations'] ?? array() ) ),
			yotm_historical_cohort_constants()['min_disk_observations']
		);
		$keys                    = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_merge( (array) ( $current['qualifying_keys'] ?? array() ), (array) ( $incoming['qualifying_keys'] ?? array() ) ) ) ) ) );
		sort( $keys );
		$current['qualifying_keys'] = array_slice( $keys, 0, 2 );
	}
	foreach ( array( 'evidence_kind', 'historical_signature', 'historical_size_key' ) as $field ) {
		if ( isset( $incoming[ $field ] ) ) {
			$current[ $field ] = $incoming[ $field ];
		}
	}
	if ( ! yotm_job_worker_replace_item( $worker, $item['id'], 'historical_cohort', 'historical_cohort', $current, 0 ) ) {
		return new WP_Error( 'yotm_historical_cohort_state', __( 'Historical cohort state could not be persisted safely.', 'thumbnail-manager' ) );
	}
	return $current;
}

/**
 * Aggregate inert anchors/observations into deterministic bounded cohort rows.
 *
 * @param array $job Job row.
 * @param int   $limit Maximum evidence rows.
 * @param array $worker Current worker.
 * @return array{done:bool,job:array}|WP_Error
 */
function yotm_prune_historical_aggregate_batch( $job, $limit, $worker ) {
	$payload = $job['payload'];
	$limit   = max( 1, min( 500, absint( $limit ) ) );
	$stage   = (string) ( $payload['cohort_aggregate_stage'] ?? 'anchors' );
	$status  = 'anchors' === $stage ? 'historical_anchor' : 'historical_observation';
	$cursor  = absint( $payload[ 'cohort_' . $stage . '_after' ] ?? 0 );
	$rows    = yotm_job_get_status_rows_after_id( $job['id'], $status, $cursor, $limit );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}

	foreach ( $rows as $row ) {
		$evidence  = $row['payload'];
		$signature = (string) ( $evidence['historical_signature'] ?? '' );
		if ( '' === $signature ) {
			return new WP_Error( 'yotm_historical_cohort_state', __( 'Historical evidence lacks a canonical signature.', 'thumbnail-manager' ) );
		}
		if ( 'anchors' === $stage ) {
			$size_key = sanitize_key( $evidence['historical_size_key'] ?? '' );
			$key      = yotm_prune_historical_cohort_item_key( 'key', $signature, $size_key );
			$state    = yotm_prune_historical_merge_cohort_state(
				$job,
				$worker,
				$key,
				array(
					'evidence_kind'        => 'historical_cohort_key_v1',
					'historical_signature' => $signature,
					'historical_size_key'  => $size_key,
					'anchors'              => array( $evidence ),
				)
			);
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$families = array_unique( array_column( (array) ( $state['anchors'] ?? array() ), 'historical_family_key' ) );
			if ( count( array_filter( $families ) ) >= yotm_historical_cohort_constants()['min_metadata_anchors'] ) {
				$signature_key = yotm_prune_historical_cohort_item_key( 'signature', $signature );
				$merged        = yotm_prune_historical_merge_cohort_state(
					$job,
					$worker,
					$signature_key,
					array(
						'evidence_kind'        => 'historical_cohort_signature_v1',
						'historical_signature' => $signature,
						'qualifying_keys'      => array( $size_key ),
						'observations'         => array(),
					)
				);
				if ( is_wp_error( $merged ) ) {
					return $merged;
				}
			}
		} else {
			$key    = yotm_prune_historical_cohort_item_key( 'signature', $signature );
			$merged = yotm_prune_historical_merge_cohort_state(
				$job,
				$worker,
				$key,
				array(
					'evidence_kind'        => 'historical_cohort_signature_v1',
					'historical_signature' => $signature,
					'qualifying_keys'      => array(),
					'observations'         => array( $evidence ),
				)
			);
			if ( is_wp_error( $merged ) ) {
				return $merged;
			}
		}//end if
		$cursor = max( $cursor, absint( $row['id'] ) );
	}//end foreach
	$payload[ 'cohort_' . $stage . '_after' ] = $cursor;
	if ( count( $rows ) < $limit ) {
		if ( 'anchors' === $stage ) {
			$payload['cohort_aggregate_stage'] = 'observations';
		} else {
			$payload['scan_phase']               = 'cohort_materialize';
			$payload['cohort_materialize_stage'] = 'seal';
			$payload['cohort_seal_after']        = 0;
		}
	}
	$phase = ( $payload['scan_phase'] ?? '' ) === 'cohort_materialize' ? 'cohort_materialize' : 'cohort_aggregate';
	if ( ! yotm_job_worker_update(
		$worker,
		array(
			'payload' => $payload,
			'phase'   => $phase,
		)
	) ) {
		return yotm_job_storage_error();
	}
	return array(
		'done' => 'cohort_materialize' === $phase,
		'job'  => yotm_job_get_by_id( $job['id'] ),
	);
}

/**
 * Seal, promote, and clean historical cohort evidence in bounded phases.
 *
 * @param array $job Job row.
 * @param int   $limit Maximum evidence rows.
 * @param array $worker Current worker.
 * @return array{done:bool,job:array}|WP_Error
 */
function yotm_prune_historical_materialize_batch( $job, $limit, $worker ) {
	$payload = $job['payload'];
	$limit   = max( 1, min( 200, absint( $limit ) ) );
	$stage   = (string) ( $payload['cohort_materialize_stage'] ?? 'seal' );

	if ( 'seal' === $stage ) {
		$cursor = absint( $payload['cohort_seal_after'] ?? 0 );
		$rows   = yotm_job_get_status_rows_after_id( $job['id'], 'historical_cohort', $cursor, $limit );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		foreach ( $rows as $row ) {
			$state = $row['payload'];
			if ( 'historical_cohort_signature_v1' === ( $state['evidence_kind'] ?? '' ) ) {
				unset( $state['sealed_proof'] );
				$keys = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $state['qualifying_keys'] ?? array() ) ) ) ) );
				if ( 1 === count( $keys ) ) {
					$anchor_key = yotm_prune_historical_cohort_item_key( 'key', (string) $state['historical_signature'], $keys[0] );
					$anchor_row = yotm_job_get_item_by_key( $job['id'], $anchor_key );
					$anchors    = array();
					foreach ( (array) ( $anchor_row['payload']['anchors'] ?? array() ) as $anchor ) {
						$current = yotm_revalidate_historical_anchor( $anchor, $payload );
						if ( ! is_wp_error( $current ) ) {
							$anchors[] = $current;
						}
					}
					$observations = array();
					foreach ( (array) ( $state['observations'] ?? array() ) as $observation ) {
						$current = yotm_revalidate_historical_observation( $observation, $payload );
						if ( ! is_wp_error( $current ) ) {
							$observations[] = $current;
						}
					}
					$proof = yotm_historical_seal_cohort( $anchors, $observations, $keys[0], (string) ( $payload['legacy_policy']['hash'] ?? '' ) );
					if ( ! is_wp_error( $proof ) ) {
						$state['sealed_proof'] = $proof;
					}
				}//end if
				if ( ! yotm_job_worker_replace_item( $worker, $row['id'], 'historical_cohort', 'historical_cohort', $state, 0 ) ) {
					return yotm_job_storage_error();
				}
			}//end if
			$cursor = max( $cursor, absint( $row['id'] ) );
		}//end foreach
		$payload['cohort_seal_after'] = $cursor;
		if ( count( $rows ) < $limit ) {
			$payload['cohort_materialize_stage'] = 'promote';
			$payload['cohort_promote_after']     = 0;
		}
	} elseif ( 'promote' === $stage ) {
		$cursor = absint( $payload['cohort_promote_after'] ?? 0 );
		$rows   = yotm_job_get_status_rows_after_id( $job['id'], 'historical_observation', $cursor, $limit );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		foreach ( $rows as $row ) {
			$observation = $row['payload'];
			$signature   = (string) ( $observation['historical_signature'] ?? '' );
			$cohort_key  = yotm_prune_historical_cohort_item_key( 'signature', $signature );
			$cohort      = yotm_job_get_item_by_key( $job['id'], $cohort_key );
			$proof       = is_array( $cohort ) ? ( $cohort['payload']['sealed_proof'] ?? null ) : null;
			$current     = is_array( $proof ) ? yotm_revalidate_historical_observation( $observation, $payload ) : new WP_Error( 'yotm_historical_cohort_insufficient' );
			$item        = is_wp_error( $current ) ? $current : yotm_build_historical_legacy_item( $current, $proof );
			if ( is_wp_error( $item ) ) {
				if ( ! yotm_job_worker_replace_item( $worker, $row['id'], 'historical_observation', 'historical_rejected', $observation, 0 ) ) {
					return yotm_job_storage_error();
				}
				$payload['orphan_summary']['historical_below_threshold'] = absint( $payload['orphan_summary']['historical_below_threshold'] ?? 0 ) + 1;
			} else {
				if ( ! yotm_job_worker_replace_item( $worker, $row['id'], 'historical_observation', 'queued', $item, absint( $item['estimated_bytes'] ?? 0 ) ) ) {
					return yotm_job_storage_error();
				}
				$payload['orphan_summary']['verified_historical'] = absint( $payload['orphan_summary']['verified_historical'] ?? 0 ) + 1;
				if ( count( $payload['sample'] ) < 300 ) {
					$payload['sample'][] = (string) ( $item['path'] ?? '' );
				}
			}
			$cursor = max( $cursor, absint( $row['id'] ) );
		}//end foreach
		$payload['cohort_promote_after'] = $cursor;
		if ( count( $rows ) < $limit ) {
			$payload['cohort_materialize_stage'] = 'cleanup';
		}
	} else {
		$intermediate = array( 'historical_anchor', 'historical_observation', 'historical_cohort', 'historical_rejected' );
		$deleted      = yotm_job_worker_delete_status_batch( $worker, $intermediate, $limit );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}
		$remaining = yotm_job_count_items_by_status( $job['id'], $intermediate );
		if ( is_wp_error( $remaining ) ) {
			return $remaining;
		}
		if ( 0 === $remaining ) {
			$payload['scan_phase'] = 'manifest';
			if ( ! yotm_job_worker_update(
				$worker,
				array(
					'payload' => $payload,
					'phase'   => 'manifest',
				)
			) ) {
				return yotm_job_storage_error();
			}
			return array(
				'done' => true,
				'job'  => yotm_job_get_by_id( $job['id'] ),
			);
		}
		// The fenced row deletion is durable progress and already refreshed the worker lease.
		// Avoid a require-match payload update here because partial cleanup does not change the payload.
		return array(
			'done' => false,
			'job'  => yotm_job_get_by_id( $job['id'] ),
		);
	}//end if
	if ( ! yotm_job_worker_update( $worker, array( 'payload' => $payload ) ) ) {
		return yotm_job_storage_error();
	}
	return array(
		'done' => false,
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
	} elseif ( in_array( $phase, array( 'cohort_aggregate', 'cohort_materialize' ), true ) ) {
		$percent = 97;
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
		'manifest_class_counts'  => is_array( $payload['manifest_class_counts'] ?? null ) ? $payload['manifest_class_counts'] : array(
			'metadata_backed'            => 0,
			'verified_legacy'            => 0,
			'verified_legacy_current'    => 0,
			'verified_historical_legacy' => 0,
		),
		'manifest_hash'          => $job['manifest_hash'],
		'expires_at'             => $job['expires_at'],
		'stopped'                => in_array( $job['status'], array( 'cancelled', 'expired' ), true ),
	);
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
	$intermediate_count = empty( $job['id'] )
		? new WP_Error( 'yotm_historical_cohort_incomplete', __( 'Historical cohort evidence is incomplete. Run and review a new scan.', 'thumbnail-manager' ) )
		: yotm_job_count_items_by_status( $job['id'], array( 'historical_anchor', 'historical_observation', 'historical_cohort', 'historical_rejected' ) );
	if ( is_wp_error( $intermediate_count ) ) {
		return $intermediate_count;
	}
	if ( 0 !== $intermediate_count ) {
		return new WP_Error( 'yotm_historical_cohort_incomplete', __( 'Historical cohort evidence is incomplete. Run and review a new scan.', 'thumbnail-manager' ) );
	}
	if ( ! empty( $job['payload']['manifest_class_counts']['verified_legacy'] ) ) {
		$legacy_policy = yotm_legacy_policy_validate( $job['payload'] );
		if ( is_wp_error( $legacy_policy ) ) {
			return $legacy_policy;
		}
	}
	if ( ! empty( $job['payload']['manifest_class_counts']['verified_historical_legacy'] ) ) {
		$historical_policy = yotm_historical_policy_validate( $job['payload'] );
		if ( is_wp_error( $historical_policy ) ) {
			return $historical_policy;
		}
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
	$intermediate_count = empty( $job['id'] )
		? new WP_Error( 'yotm_historical_cohort_incomplete', __( 'Historical cohort evidence is incomplete. Run and review a new scan.', 'thumbnail-manager' ) )
		: yotm_job_count_items_by_status( $job['id'], array( 'historical_anchor', 'historical_observation', 'historical_cohort', 'historical_rejected' ) );
	if ( is_wp_error( $intermediate_count ) ) {
		return $intermediate_count;
	}
	if ( 0 !== $intermediate_count ) {
		return new WP_Error( 'yotm_historical_cohort_incomplete', __( 'Historical cohort evidence is incomplete. Run and review a new scan.', 'thumbnail-manager' ) );
	}
	if ( ! empty( $job['payload']['manifest_class_counts']['verified_legacy'] ) ) {
		$legacy_policy = yotm_legacy_policy_validate( $job['payload'] );
		if ( is_wp_error( $legacy_policy ) ) {
			return $legacy_policy;
		}
	}
	if ( ! empty( $job['payload']['manifest_class_counts']['verified_historical_legacy'] ) ) {
		$historical_policy = yotm_historical_policy_validate( $job['payload'] );
		if ( is_wp_error( $historical_policy ) ) {
			return $historical_policy;
		}
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

			$item_schema = (string) ( $item['payload']['ownership_schema'] ?? '' );
			if ( $journal_absent && 'historical_legacy_generated_v1' === $item_schema ) {
				$references = yotm_prune_validate_historical_absent_recovery( $item['payload'] ?? array(), $job['payload'] ?? array() );
			} elseif ( $journal_absent ) {
				$references = true;
			} elseif ( 'legacy_generated_v1' === $item_schema ) {
				$references = yotm_prune_validate_legacy_evidence( $item['payload'] ?? array(), $job['payload'] ?? array() );
			} elseif ( 'historical_legacy_generated_v1' === $item_schema ) {
				$references = yotm_prune_validate_historical_evidence( $item['payload'] ?? array(), $job['payload'] ?? array() );
			} else {
				$references = yotm_prune_validate_live_reference_evidence( $item['payload'] ?? array(), $path );
			}
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
